<?php
$session = require_account_session($lang, true);
$isSeller = (int) ($session['rolId'] ?? 0) === 2;
$hvCustomerDiscountPct = 0.0;
$hvCustomerSellers = [];
$hvCustomerSellersJson = '[]';
$hvCustomerContactName = '';
$hvCustomerBussName = '';
$hvCustomerResolvedFvId = 0;
$cidRawCo = trim((string) config('FULLVENDOR_COMPANY_ID', '0'));
$hvCompanyIdExport = 0;
if ($cidRawCo !== '') {
    if (ctype_digit($cidRawCo)) {
        $hvCompanyIdExport = (int) $cidRawCo;
    } elseif (is_numeric($cidRawCo)) {
        $hvCompanyIdExport = (int) $cidRawCo;
    } else {
        $hvCompanyIdExport = $cidRawCo;
    }
}
if (!$isSeller && class_exists('Db', false) && Db::enabled()) {
    $cu = (int) ($session['customerId'] ?? 0);
    $hvCustomerResolvedFvId = $cu;
    if ($cu > 0) {
        try {
            $st = Db::pdo()->prepare(
                'SELECT `discount`, `user_id`, `name`, `business_name`, `customeridfullvendor`, `customer_id`'
                . ' FROM `customers` WHERE (`customeridfullvendor` = ? OR `customer_id` = ?) LIMIT 1'
            );
            $st->execute([$cu, $cu]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($r)) {
                $hvCustomerContactName = trim((string) ($r['name'] ?? ''));
                $hvCustomerBussName = trim((string) ($r['business_name'] ?? ''));
                $resolvedFv = (int) ($r['customeridfullvendor'] ?? 0);
                if ($resolvedFv <= 0) {
                    $resolvedFv = (int) ($r['customer_id'] ?? 0);
                }
                if ($resolvedFv > 0) {
                    $hvCustomerResolvedFvId = $resolvedFv;
                }
                $hvCustomerDiscountPct = (float) ($r['discount'] ?? 0);
                if (!is_finite($hvCustomerDiscountPct) || $hvCustomerDiscountPct < 0) {
                    $hvCustomerDiscountPct = 0.0;
                }
                if ($hvCustomerDiscountPct > 100) {
                    $hvCustomerDiscountPct = 100.0;
                }
                $sellerCsv = trim((string) ($r['user_id'] ?? ''));
                if ($sellerCsv !== '') {
                    $sellerIds = [];
                    foreach (preg_split('/\s*,\s*/', $sellerCsv) ?: [] as $part) {
                        $part = trim((string) $part);
                        if ($part !== '' && ctype_digit($part)) {
                            $sid = (int) $part;
                            if ($sid > 0 && !in_array($sid, $sellerIds, true)) {
                                $sellerIds[] = $sid;
                            }
                        }
                    }
                    if ($sellerIds !== []) {
                        $nameById = [];
                        $ph = implode(',', array_fill(0, count($sellerIds), '?'));

                        $stU = Db::pdo()->prepare(
                            "SELECT `user_id`, `first_name`, `last_name` FROM `usersList` WHERE `user_id` IN ($ph)"
                        );
                        $stU->execute($sellerIds);
                        while ($u = $stU->fetch(PDO::FETCH_ASSOC)) {
                            $sid = (int) ($u['user_id'] ?? 0);
                            if ($sid <= 0) {
                                continue;
                            }
                            $full = trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? ''));
                            $nameById[$sid] = $full !== '' ? $full : ('Seller #' . $sid);
                        }

                        foreach ($sellerIds as $sid) {
                            $hvCustomerSellers[] = [
                                'id' => $sid,
                                'name' => $nameById[$sid] ?? ('Seller #' . $sid),
                            ];
                        }
                        $hvCustomerSellersJson = json_encode($hvCustomerSellers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
                    }
                }
            }
        } catch (Throwable) {
            $hvCustomerDiscountPct = 0.0;
        }
    }
}
$hvSellerCustomers = [];
$hvSellerCustomersJson = '[]';
if ($isSeller && class_exists('Db', false) && Db::enabled()) {
    require_once dirname(__DIR__, 2) . '/lib/SellerCustomers.php';
    $hvSellerFvUid = (int) ($session['userId'] ?? 0);
    $hvSellerCompanyId = (int) config('FULLVENDOR_COMPANY_ID', '0');
    $hvSellerCustomers = SellerCustomers::listForSellerFvUserId(Db::pdo(), $hvSellerFvUid, $hvSellerCompanyId);
    $hvSellerCustomersJson = json_encode($hvSellerCustomers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
}
ob_start();
if ($isSeller) { ?>
<div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 sm:py-10">
  <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 tracking-tight"><?= e($dict['cart']['title']) ?></h1>
  <p class="text-sm text-gray-500 mt-1"><?= e($dict['cart']['seller_cart_subtitle'] ?? '') ?></p>
  <div class="rounded-2xl border border-gray-200 bg-amber-50 p-4 sm:p-5 shadow-sm mt-8">
    <p id="hv-seller-gate-hint" class="text-sm text-amber-800 leading-relaxed"><?= e($dict['cart']['seller_cart_hint']) ?></p>
    <p id="hv-seller-no-customers" class="hidden mt-3 text-sm text-gray-600"><?= e($dict['home']['seller_no_customers']) ?></p>
  </div>
  <div id="hv-seller-customer-detail" class="hidden rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden mt-6">
    <div class="h-1 bg-red-700"></div>
    <div class="px-4 py-4 sm:px-5">
      <h2 class="text-[10px] font-semibold uppercase tracking-[0.2em] text-gray-400 mb-3"><?= e($dict['home']['seller_customer_details_title']) ?></h2>
      <div id="hv-seller-customer-detail-body" class="text-xs"></div>
    </div>
  </div>
  <div id="hv-seller-cart-lines-wrap" class="hidden mt-8">
    <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-lg">
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm text-left">
          <thead class="text-[10px] uppercase tracking-wider text-gray-500 bg-gray-50 border-b border-gray-100">
          <tr>
            <th class="px-4 py-3.5 font-semibold w-16"></th>
            <th class="px-4 py-3.5 font-semibold"><?= e($dict['cart']['col_product']) ?></th>
            <th class="px-4 py-3.5 font-semibold w-32 text-right"><?= e($dict['cart']['unit_price']) ?></th>
            <th class="px-4 py-3.5 font-semibold w-28 text-right"><?= e($dict['table']['qty'] ?? 'Qty') ?></th>
            <th class="px-4 py-3.5 font-semibold w-32 text-right"><?= e($dict['cart']['line_total']) ?></th>
            <th class="px-4 py-3.5 font-semibold min-w-[10rem]"><?= e($dict['cart']['line_notes']) ?></th>
            <th class="px-4 py-3.5 font-semibold w-14 text-center" aria-hidden="true"></th>
          </tr>
        </thead>
        <tbody id="hv-seller-cart-tbody" class="divide-y divide-gray-100/90"></tbody>
      </table>
      </div>
    </div>
  </div>
  <div id="hv-seller-notes-summary-row" class="hidden mt-8 w-full">
    <div class="hv-seller-notes-summary-grid grid">
      <div class="hv-seller-notes-col min-w-0 flex flex-col">
        <label for="hv-seller-order-notes" class="text-xs font-semibold uppercase tracking-wider text-gray-500 mb-2"><?= e($dict['cart']['order_notes_label']) ?></label>
        <textarea id="hv-seller-order-notes" rows="5" class="hv-seller-order-notes-ta rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm" placeholder="<?= e($dict['cart']['order_notes_placeholder']) ?>"></textarea>
      </div>
      <aside id="hv-seller-cart-summary" class="hidden min-w-0 flex flex-col" aria-label="<?= e($dict['cart']['seller_order_summary_aria'] ?? '') ?>">
        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-5 sm:p-6 shadow-sm space-y-4 flex flex-col justify-center">
          <div class="flex justify-between items-baseline gap-4 text-sm">
            <span class="font-medium uppercase tracking-wide text-xs text-gray-500"><?= e($dict['cart']['subtotal']) ?></span>
            <span id="hv-sum-sub" class="font-semibold text-gray-900 tabular-nums text-base"></span>
          </div>
          <div id="hv-sum-discount-row" class="hidden flex justify-between items-baseline gap-4 text-sm">
            <span id="hv-sum-discount-label" class="font-medium text-gray-600 text-xs uppercase tracking-wide"></span>
            <span id="hv-sum-discount-amt" class="tabular-nums text-red-600 font-semibold"></span>
          </div>
          <div class="flex justify-between items-baseline gap-4 pt-4 border-t border-gray-200/90">
            <span class="text-sm font-bold uppercase tracking-wide text-gray-900"><?= e($dict['cart']['total']) ?></span>
            <span id="hv-sum-total" class="text-lg font-bold text-gray-900 tabular-nums tracking-tight"></span>
          </div>
        </div>
      </aside>
    </div>
  </div>
  <div id="hv-seller-actions-row" class="mt-8 flex flex-nowrap gap-3 justify-end w-full">
    <?php
    $hvSellerAct = 'inline-flex items-center justify-center w-36 sm:w-40 min-h-[3rem] flex-shrink-0 rounded-xl px-2 py-2 text-center text-[10px] sm:text-xs font-semibold uppercase tracking-wide leading-tight whitespace-normal';
    ?>
    <button type="button" id="hv-seller-cart-clear" class="<?= e($hvSellerAct) ?> hidden border-2 border-gray-200 bg-white text-gray-800 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-200"><?= e($dict['cart']['clear_all']) ?></button>
    <button type="button" id="hv-seller-cart-place-order" class="<?= e($hvSellerAct) ?> hidden bg-red-700 text-white border-2 border-red-800 hover:bg-red-800 focus:outline-none focus:ring-2 focus:ring-red-200"><?= e($dict['cart']['send_order']) ?></button>
    <a href="<?= e(base_url()) ?>/<?= e($lang) ?>" class="<?= e($hvSellerAct) ?> border-2 border-red-200 bg-white text-red-800 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-100"><?= e($dict['cart']['continue_shopping']) ?></a>
  </div>
  <p id="hv-seller-cart-page-empty" class="hidden mt-8 text-sm text-gray-500 text-center py-14 px-4 border-2 border-dashed border-gray-200/80 rounded-2xl bg-gray-50/30"></p>
</div>
<script>
(function () {
  var base = (window.HV_BASE || '').replace(/\/?$/, '');
  var lang = <?= json_encode($lang) ?>;
  var dict = <?= json_encode($dict, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  var sellerCustomers = <?= $hvSellerCustomersJson ?>;
  var sellerCatalogMode = true;
  var sellerCatalogDbSync = <?= (class_exists('Db', false) && Db::enabled()) ? 'true' : 'false' ?>;

  function fmt(n) {
    return new Intl.NumberFormat(lang === 'es' ? 'es-US' : 'en-US', { style: 'currency', currency: 'USD' }).format(n);
  }

  function formatUnitPriceForInput(n) {
    var v = parseFloat(String(n).replace(',', '.'));
    if (isNaN(v) || v < 0) v = 0;
    return v.toFixed(2);
  }

  function escAttr(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function sellerNz(v) {
    if (v === null || v === undefined) return '';
    return String(v).trim();
  }

  function sellerNormalizeAddressPieces(pieces) {
    var out = [];
    var prev = '';
    var prevLow = '';
    for (var i = 0; i < pieces.length; i++) {
      var t = sellerNz(pieces[i]);
      if (!t) continue;
      var low = t.toLowerCase();
      if (low === prevLow) continue;
      if (/^[a-z]{2}$/i.test(t) && prev) {
        var esc = t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        if (new RegExp('\\b' + esc + '\\s+\\d{5}(-\\d{4})?\\b', 'i').test(prev)) continue;
      }
      out.push(t);
      prev = t;
      prevLow = low;
    }
    return out;
  }

  function sellerPrettyAddressJoined(joined) {
    if (!joined) return '';
    var pieces = joined.split(',').map(function (s) { return sellerNz(s); }).filter(Boolean);
    pieces = sellerNormalizeAddressPieces(pieces);
    return pieces.join(', ');
  }

  function sellerConcatCommercialAddress(c) {
    var fields = [
      c.commercial_address, c.commercial_delivery_address,
      c.commercial_city, c.commercial_state, c.commercial_zone,
      c.commercial_zip_code, c.commercial_country
    ];
    var merged = fields.map(function (x) { return sellerNz(x); }).filter(Boolean).join(', ');
    if (merged) return sellerPrettyAddressJoined(merged);
    fields = [
      c.dispatch_address, c.dispatch_delivery_address,
      c.dispatch_city, c.dispatch_state, c.dispatch_zone,
      c.dispatch_zip_code, c.dispatch_country
    ];
    merged = fields.map(function (x) { return sellerNz(x); }).filter(Boolean).join(', ');
    return sellerPrettyAddressJoined(merged);
  }

  function sellerUp(s) {
    return escAttr(String(s == null ? '' : s).toUpperCase());
  }

  function renderSellerCustomerDetails() {
    var wrap = document.getElementById('hv-seller-customer-detail');
    var body = document.getElementById('hv-seller-customer-detail-body');
    if (!wrap || !body) return;
    var fid = (window.HV && HV.cart && HV.cart.getSellerCustomerFvId) ? HV.cart.getSellerCustomerFvId() : '';
    if (!fid) {
      wrap.classList.add('hidden');
      return;
    }
    var c = sellerCustomers.find(function (x) { return String(x.customeridfullvendor) === String(fid); });
    if (!c) {
      wrap.classList.add('hidden');
      return;
    }
    wrap.classList.remove('hidden');
    var labels = (dict.home && dict.home.seller_detail_labels) ? dict.home.seller_detail_labels : {};
    var labBiz = labels.business_name || 'Business name';
    var labContact = labels.name || 'Contact name';
    var labPhone = labels.phone || 'Phone';
    var labAddr = (dict.home && dict.home.seller_customer_address_label) ? dict.home.seller_customer_address_label : 'Address';
    var bn = sellerNz(c.business_name);
    var nm = sellerNz(c.name);
    var phoneStr = sellerNz(c.phone) || sellerNz(c.cell_phone);
    var addrStr = sellerConcatCommercialAddress(c);
    var dash = (dict.home && dict.home.seller_customer_field_empty) ? String(dict.home.seller_customer_field_empty) : '—';
    var hasAny = !!(bn || nm || phoneStr || addrStr);
    var emptyMsg = (dict.home && dict.home.seller_customer_detail_empty) ? dict.home.seller_customer_detail_empty : '';
    var inner;
    if (!hasAny) {
      inner = '<p class="text-xs text-gray-500">' + escAttr(emptyMsg) + '</p>';
    } else {
      var rows = [
        { lab: labBiz, val: bn || dash },
        { lab: labContact, val: nm || dash },
        { lab: labPhone, val: phoneStr || dash },
        { lab: labAddr, val: addrStr || dash }
      ];
      var rowHtml = rows.map(function (r, idx) {
        var bt = idx > 0 ? 'border-top:1px solid rgba(229,231,235,0.9);' : '';
        var valHtml = r.val === dash ? escAttr(r.val) : sellerUp(r.val);
        return '<tr><td style="' + bt + 'vertical-align:top;box-sizing:border-box;padding:0.5rem 1rem 0.5rem 0.75rem;width:11.5rem;max-width:40%;font-size:10px;font-weight:600;letter-spacing:0.07em;text-transform:uppercase;color:#9ca3af;line-height:1.4">' +
          escAttr(r.lab) + '</td><td style="' + bt + 'vertical-align:top;box-sizing:border-box;padding:0.5rem 0.75rem;font-size:12px;font-weight:600;color:#111827;text-transform:uppercase;letter-spacing:0.02em;line-height:1.45;word-break:break-word">' +
          valHtml + '</td></tr>';
      }).join('');
      inner = '<div class="rounded-lg border border-gray-100 bg-[#FAFAF8]/80 overflow-hidden"><table style="width:100%;border-collapse:collapse;table-layout:fixed"><tbody>' + rowHtml + '</tbody></table></div>';
    }
    body.innerHTML = inner;
  }

  function syncSellerSelectFromStorage() {
    if (!window.HV || !HV.cart) return;
    var id = HV.cart.getSellerCustomerFvId();
    if (id && Array.isArray(sellerCustomers) && !sellerCustomers.some(function (x) { return String(x.customeridfullvendor) === String(id); })) {
      HV.cart.setSellerCustomerFvId('');
    }
  }

  var sellerCatalogSyncingFromServer = false;
  var sellerCatalogPostTimer = null;

  function postSellerCatalogStatePatch(patch) {
    if (!sellerCatalogDbSync || !sellerCatalogMode || sellerCatalogSyncingFromServer) return;
    fetch(base + '/api/seller-catalog-state', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(patch || {})
    }).catch(function () {});
  }

  function scheduleSellerCatalogStatePost() {
    if (!sellerCatalogDbSync || !sellerCatalogMode || sellerCatalogSyncingFromServer) return;
    if (sellerCatalogPostTimer) clearTimeout(sellerCatalogPostTimer);
    sellerCatalogPostTimer = setTimeout(function () {
      sellerCatalogPostTimer = null;
      if (sellerCatalogSyncingFromServer || !window.HV || !HV.cart || !HV.cart.exportSellerCartsSnapshot) return;
      var fid = HV.cart.getSellerCustomerFvId();
      var patch = {
        selectedCustomerFv: fid ? parseInt(fid, 10) : null,
        carts: HV.cart.exportSellerCartsSnapshot()
      };
      var onEl = document.getElementById('hv-seller-order-notes');
      if (onEl) patch.orderNotes = onEl.value;
      postSellerCatalogStatePatch(patch);
    }, 700);
  }

  function pullSellerCatalogStateFromServerAccount() {
    if (!sellerCatalogDbSync || !sellerCatalogMode) return;
    sellerCatalogSyncingFromServer = true;
    fetch(base + '/api/seller-catalog-state', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('http')); })
      .then(function (data) {
        if (!data || !window.HV || !HV.cart) return;
        if (data.rowExists && HV.cart.applySellerCatalogStateFromServer) {
          HV.cart.applySellerCatalogStateFromServer({
            selectedCustomerFv: data.selectedCustomerFv,
            carts: data.carts || {},
            orderNotes: data.orderNotes != null ? data.orderNotes : ''
          });
        } else if (HV.cart.exportSellerCartsSnapshot && HV.cart.getSellerCustomerFvId) {
          var snap = HV.cart.exportSellerCartsSnapshot();
          var fid = HV.cart.getSellerCustomerFvId();
          if (fid || Object.keys(snap).length > 0) {
            fetch(base + '/api/seller-catalog-state', {
              method: 'POST',
              credentials: 'same-origin',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                selectedCustomerFv: fid ? parseInt(fid, 10) : null,
                carts: snap
              })
            }).catch(function () {});
          }
        }
        syncSellerSelectFromStorage();
        renderSellerCartAccountPage();
      })
      .catch(function () {})
      .finally(function () {
        sellerCatalogSyncingFromServer = false;
      });
  }

  function setSellerPrimaryCartActionsVisible(show) {
    var c = document.getElementById('hv-seller-cart-clear');
    var p = document.getElementById('hv-seller-cart-place-order');
    if (c) c.classList.toggle('hidden', !show);
    if (p) p.classList.toggle('hidden', !show);
  }

  function renderSellerCartAccountPage() {
    var hint = document.getElementById('hv-seller-gate-hint');
    var nc = document.getElementById('hv-seller-no-customers');
    var wrap = document.getElementById('hv-seller-cart-lines-wrap');
    var notesSummaryRow = document.getElementById('hv-seller-notes-summary-row');
    var emptyEl = document.getElementById('hv-seller-cart-page-empty');
    var tbody = document.getElementById('hv-seller-cart-tbody');
    var summaryEl = document.getElementById('hv-seller-cart-summary');
    var hasOpts = Array.isArray(sellerCustomers) && sellerCustomers.length > 0;
    if (nc) nc.classList.toggle('hidden', hasOpts);
    if (!window.HV || !HV.cart) return;
    var fid = HV.cart.getSellerCustomerFvId();
    var ready = !!fid;
    renderSellerCustomerDetails();
    if (hint) hint.classList.toggle('hidden', !hasOpts || ready);
    if (notesSummaryRow) notesSummaryRow.classList.toggle('hidden', !ready);
    if (!hasOpts) {
      if (wrap) wrap.classList.add('hidden');
      setSellerPrimaryCartActionsVisible(false);
      if (emptyEl) emptyEl.classList.add('hidden');
      if (summaryEl) summaryEl.classList.add('hidden');
      return;
    }
    if (!ready) {
      if (wrap) wrap.classList.add('hidden');
      setSellerPrimaryCartActionsVisible(false);
      if (emptyEl) emptyEl.classList.add('hidden');
      if (summaryEl) summaryEl.classList.add('hidden');
      return;
    }
    var items = HV.cart.load();
    var cust = sellerCustomers.find(function (x) { return String(x.customeridfullvendor) === String(fid); });
    var discountPct = 0;
    if (cust && cust.discount != null) {
      discountPct = parseFloat(cust.discount);
      if (isNaN(discountPct) || discountPct < 0) discountPct = 0;
      if (discountPct > 100) discountPct = 100;
    }
    if (!items.length) {
      if (wrap) wrap.classList.add('hidden');
      setSellerPrimaryCartActionsVisible(false);
      if (emptyEl) {
        emptyEl.classList.remove('hidden');
        emptyEl.textContent = dict.cart.empty || '';
      }
      if (summaryEl) summaryEl.classList.add('hidden');
      return;
    }
    if (wrap) wrap.classList.remove('hidden');
    setSellerPrimaryCartActionsVisible(true);
    if (emptyEl) emptyEl.classList.add('hidden');
    var moqLab = (dict.home && dict.home.moq_label) ? dict.home.moq_label : 'MOQ:';
    var subtotal = 0;
    var rows = items.map(function (it) {
      var pid = it.productId;
      var moq = Math.max(1, parseInt(it.moq, 10) || 1);
      var up = parseFloat(it.unitPrice);
      if (isNaN(up)) up = 0;
      var q = parseInt(it.qty, 10) || 0;
      var line = up * q;
      subtotal += line;
      var upStr = formatUnitPriceForInput(up);
      var img = escAttr(it.image || '');
      var nm = sellerUp(it.name || '');
      var sku = sellerUp(it.sku || '');
      var ln = String(it.lineNote || '').slice(0, 2000);
      var ph = escAttr(dict.cart.line_notes_placeholder || '');
      return '<tr class="hover:bg-gray-50/70 transition-colors">' +
        '<td class="px-4 py-3.5"><img src="' + img + '" alt="" class="w-14 h-14 object-contain rounded-xl bg-gray-50 border border-gray-100/80"/></td>' +
        '<td class="px-4 py-3.5"><p class="font-semibold text-gray-900 leading-snug">' + nm + '</p>' +
        '<p class="text-xs text-gray-500 mt-0.5 uppercase tracking-wide">' + sku + '</p>' +
        '<p class="text-[11px] text-gray-400 mt-1">' + escAttr(moqLab) + ' ' + moq + '</p></td>' +
        '<td class="px-4 py-3.5 align-top"><input type="number" min="0" step="0.01" inputmode="decimal" class="hv-seller-line-price w-full max-w-[7.5rem] rounded-xl border border-gray-200/90 bg-white px-2.5 py-2 text-sm font-semibold tabular-nums text-right shadow-sm focus:border-red-300 focus:ring-2 focus:ring-red-500/15 focus:outline-none [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none" data-pid="' + pid + '" value="' + escAttr(upStr) + '"/></td>' +
        '<td class="px-4 py-3.5 align-top"><input type="number" min="0" step="1" inputmode="numeric" class="hv-seller-line-qty w-full max-w-[6rem] rounded-xl border border-gray-200/90 bg-white px-2.5 py-2 text-sm font-semibold tabular-nums text-right shadow-sm focus:border-red-300 focus:ring-2 focus:ring-red-500/15 focus:outline-none [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none" data-pid="' + pid + '" data-moq="' + moq + '" value="' + q + '"/></td>' +
        '<td class="px-4 py-3.5 text-right font-semibold text-gray-900 tabular-nums align-top hv-seller-line-total" data-pid="' + pid + '">' + fmt(line) + '</td>' +
        '<td class="px-4 py-3.5 align-top"><textarea rows="2" class="hv-seller-line-note w-full min-w-[8rem] max-w-xs rounded-xl border border-gray-200/90 bg-white px-2.5 py-2 text-xs text-gray-900 uppercase shadow-sm focus:border-red-300 focus:ring-2 focus:ring-red-500/15 focus:outline-none" data-pid="' + pid + '" placeholder="' + ph + '">' + escAttr(ln) + '</textarea></td>' +
        '<td class="px-4 py-3.5 align-middle text-center w-14">' +
        '<button type="button" class="hv-seller-line-remove inline-flex items-center justify-center h-10 w-10 rounded-xl border border-gray-200/90 bg-white text-red-600 hover:bg-red-50 hover:text-red-800 hover:border-red-200 focus:outline-none focus:ring-2 focus:ring-red-200/60" data-pid="' + pid + '" title="' + escAttr(dict.cart.remove || 'Remove') + '" aria-label="' + escAttr(dict.cart.remove || 'Remove') + '">' +
        '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">' +
        '<path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />' +
        '</svg></button></td></tr>';
    }).join('');
    if (tbody) tbody.innerHTML = rows;
    var discAmt = subtotal * (discountPct / 100);
    var total = subtotal - discAmt;
    if (summaryEl) {
      summaryEl.classList.remove('hidden');
      var elSub = document.getElementById('hv-sum-sub');
      var elDiscLbl = document.getElementById('hv-sum-discount-label');
      var elDiscAmt = document.getElementById('hv-sum-discount-amt');
      var elTot = document.getElementById('hv-sum-total');
      var discRow = document.getElementById('hv-sum-discount-row');
      if (elSub) elSub.textContent = fmt(subtotal);
      var pctDisp = Math.round(discountPct * 100) / 100;
      var tmpl = dict.cart.discount_percent || 'Discount ({pct}%)';
      if (elDiscLbl) elDiscLbl.textContent = tmpl.replace(/\{pct\}/g, String(pctDisp));
      if (elDiscAmt) elDiscAmt.textContent = discAmt > 0 ? ('− ' + fmt(discAmt)) : fmt(0);
      if (elTot) elTot.textContent = fmt(total);
      if (discRow) {
        if (discountPct > 0 && discAmt > 0) discRow.classList.remove('hidden');
        else discRow.classList.add('hidden');
      }
    }

    if (tbody) {
      tbody.querySelectorAll('.hv-seller-line-price').forEach(function (inp) {
        inp.addEventListener('change', function () {
          var pid = parseInt(inp.getAttribute('data-pid'), 10);
          HV.cart.setItemUnitPrice(pid, inp.value);
          scheduleSellerCatalogStatePost();
        });
      });
      tbody.querySelectorAll('.hv-seller-line-qty').forEach(function (inp) {
        inp.addEventListener('change', function () {
          var pid = parseInt(inp.getAttribute('data-pid'), 10);
          HV.cart.setItemQty(pid, inp.value);
          scheduleSellerCatalogStatePost();
        });
      });
      tbody.querySelectorAll('.hv-seller-line-note').forEach(function (ta) {
        ta.addEventListener('change', function () {
          var pid = parseInt(ta.getAttribute('data-pid'), 10);
          HV.cart.setItemLineNote(pid, ta.value);
          scheduleSellerCatalogStatePost();
        });
      });
      tbody.querySelectorAll('.hv-seller-line-remove').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var pid = parseInt(btn.getAttribute('data-pid'), 10);
          HV.cart.remove(pid);
        });
      });
    }
  }

  function placeSellerFvOrder(btn) {
    if (!window.HV || !HV.cart) return;
    var fid = HV.cart.getSellerCustomerFvId();
    if (!fid) {
      if (HV.toast && dict.cart && dict.cart.seller_cart_hint) HV.toast(dict.cart.seller_cart_hint);
      return;
    }
    var items = HV.cart.load();
    if (!items.length) return;
    var mapped = items.map(function (it) {
      return {
        product_id: it.productId,
        qty: parseInt(it.qty, 10) || 0,
        sale_price: parseFloat(it.unitPrice) || 0,
        line_note: String(it.lineNote || '').trim()
      };
    }).filter(function (x) { return x.product_id > 0 && x.qty > 0; });
    if (!mapped.length) return;
    var onTa = document.getElementById('hv-seller-order-notes');
    var general = onTa ? String(onTa.value || '').trim() : '';
    var lineBlock = items.filter(function (it) { return String(it.lineNote || '').trim(); }).map(function (it) {
      var sku = String(it.sku || '').trim() || ('#' + it.productId);
      return sku + ': ' + String(it.lineNote || '').trim();
    }).join('\n');
    var orderComment = general;
    if (lineBlock) {
      orderComment = orderComment ? orderComment + '\n\n---\n' + lineBlock : lineBlock;
    }
    var origText = btn ? btn.textContent : '';
    if (btn) {
      btn.disabled = true;
      btn.setAttribute('aria-busy', 'true');
    }
    fetch(base + '/api/seller-orders', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        customerFvId: parseInt(fid, 10),
        items: mapped,
        orderComment: orderComment,
        lang: lang
      })
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (x) {
        if (!x.ok) {
          HV.toast && HV.toast((x.j && x.j.error) || dict.common.error, { icon: 'error' });
          return;
        }
        HV.cart.clear();
        if (onTa) onTa.value = '';
        postSellerCatalogStatePatch({
          selectedCustomerFv: fid ? parseInt(fid, 10) : null,
          carts: HV.cart.exportSellerCartsSnapshot(),
          orderNotes: ''
        });
        renderSellerCartAccountPage();
        if (HV.toast && dict.toast && dict.toast.order_placed) HV.toast(dict.toast.order_placed);
        setTimeout(function () { window.location.href = base + '/' + lang + '/account/orders'; }, 800);
      })
      .catch(function () { HV.toast && HV.toast(dict.common.error, { icon: 'error' }); })
      .finally(function () {
        if (btn) {
          btn.disabled = false;
          btn.removeAttribute('aria-busy');
          if (origText) btn.textContent = origText;
        }
      });
  }

  function initSellerAccountCart() {
    var clearBtn = document.getElementById('hv-seller-cart-clear');
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        if (!window.HV || !HV.cart || !HV.cart.getSellerCustomerFvId()) return;
        if (!HV.cart.load().length) return;
        var afterClear = function () {
          HV.cart.clear();
          var onTaClear = document.getElementById('hv-seller-order-notes');
          if (onTaClear) onTaClear.value = '';
          if (HV.toast && dict.toast && dict.toast.cart_cleared) HV.toast(dict.toast.cart_cleared);
          scheduleSellerCatalogStatePost();
        };
        if (typeof HV.confirm === 'function') {
          HV.confirm({ text: dict.cart.confirm_clear, icon: 'warning' }).then(function (ok) { if (ok) afterClear(); });
        } else if (window.confirm(dict.cart.confirm_clear)) {
          afterClear();
        }
      });
    }
    var placeBtn = document.getElementById('hv-seller-cart-place-order');
    if (placeBtn) {
      placeBtn.addEventListener('click', function () {
        placeSellerFvOrder(placeBtn);
      });
    }
    var onTa = document.getElementById('hv-seller-order-notes');
    var notesDebounce;
    if (onTa) {
      onTa.addEventListener('input', function () {
        clearTimeout(notesDebounce);
        notesDebounce = setTimeout(function () { scheduleSellerCatalogStatePost(); }, 900);
      });
    }
    window.addEventListener('hv-seller-customer-change', renderSellerCartAccountPage);
    window.addEventListener('hv-cart-change', function () {
      renderSellerCartAccountPage();
      if (sellerCatalogDbSync && !sellerCatalogSyncingFromServer) scheduleSellerCatalogStatePost();
    });
    renderSellerCartAccountPage();
    pullSellerCatalogStateFromServerAccount();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSellerAccountCart);
  } else {
    initSellerAccountCart();
  }
})();
</script>
<?php } else { ?>
<div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 sm:py-10">
  <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 tracking-tight"><?= e($dict['cart']['title']) ?></h1>
  <p class="text-sm text-gray-500 mt-1"><?= e($dict['cart']['customer_cart_subtitle'] ?? '') ?></p>
  <div class="mt-3 max-w-md">
    <label for="hv-customer-seller-select" class="text-xs font-semibold uppercase tracking-wider text-gray-500">
      <?= e($lang === 'es' ? 'Seller para esta orden' : 'Seller for this order') ?>
    </label>
    <select id="hv-customer-seller-select" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-red-300 focus:ring-2 focus:ring-red-500/15 focus:outline-none"></select>
    <p id="hv-customer-seller-help" class="mt-1 text-xs text-gray-500"></p>
  </div>
  <p id="hv-acct-note" class="text-sm text-amber-800 mt-2 hidden"></p>
  <div id="hv-customer-sync" class="text-sm text-gray-500 mt-6"><?= e($dict['common']['loading']) ?></div>
  <div id="hv-customer-cart-lines-wrap" class="hidden mt-8">
    <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-lg">
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm text-left">
          <thead class="text-[10px] uppercase tracking-wider text-gray-500 bg-gray-50 border-b border-gray-100">
          <tr>
            <th class="px-4 py-3.5 font-semibold w-16"></th>
            <th class="px-4 py-3.5 font-semibold"><?= e($dict['cart']['col_product']) ?></th>
            <th class="px-4 py-3.5 font-semibold w-32 text-right"><?= e($dict['cart']['unit_price']) ?></th>
            <th class="px-4 py-3.5 font-semibold w-28 text-right"><?= e($dict['table']['qty'] ?? 'Qty') ?></th>
            <th class="px-4 py-3.5 font-semibold w-32 text-right"><?= e($dict['cart']['line_total']) ?></th>
            <th class="px-4 py-3.5 font-semibold min-w-[10rem]"><?= e($dict['cart']['line_notes']) ?></th>
            <th class="px-4 py-3.5 font-semibold w-14 text-center" aria-hidden="true"></th>
          </tr>
        </thead>
        <tbody id="hv-customer-cart-tbody" class="divide-y divide-gray-100/90"></tbody>
      </table>
      </div>
    </div>
  </div>
  <div id="hv-customer-notes-summary-row" class="hidden mt-8 w-full">
    <div class="hv-seller-notes-summary-grid grid">
      <div class="hv-seller-notes-col min-w-0 flex flex-col">
        <label for="hv-order-comment" class="text-xs font-semibold uppercase tracking-wider text-gray-500 mb-2"><?= e($dict['cart']['order_comments']) ?></label>
        <textarea id="hv-order-comment" rows="5" class="hv-seller-order-notes-ta rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm" placeholder="<?= e($dict['cart']['order_notes_placeholder'] ?? '') ?>"></textarea>
      </div>
      <aside id="hv-customer-cart-summary" class="hidden min-w-0 flex flex-col" aria-label="<?= e($dict['cart']['seller_order_summary_aria'] ?? '') ?>">
        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-5 sm:p-6 shadow-sm space-y-4 flex flex-col justify-center">
          <div class="flex justify-between items-baseline gap-4 text-sm">
            <span class="font-medium uppercase tracking-wide text-xs text-gray-500"><?= e($dict['cart']['subtotal']) ?></span>
            <span id="hv-customer-sum-sub" class="font-semibold text-gray-900 tabular-nums text-base"></span>
          </div>
          <div id="hv-customer-sum-discount-row" class="hidden flex justify-between items-baseline gap-4 text-sm">
            <span id="hv-customer-sum-discount-label" class="font-medium text-gray-600 text-xs uppercase tracking-wide"></span>
            <span id="hv-customer-sum-discount-amt" class="tabular-nums text-red-600 font-semibold"></span>
          </div>
          <div class="flex justify-between items-baseline gap-4 pt-4 border-t border-gray-200/90">
            <span class="text-sm font-bold uppercase tracking-wide text-gray-900"><?= e($dict['cart']['total']) ?></span>
            <span id="hv-customer-sum-total" class="text-lg font-bold text-gray-900 tabular-nums tracking-tight"></span>
          </div>
        </div>
      </aside>
    </div>
  </div>
  <div id="hv-customer-actions-row" class="mt-8 flex flex-nowrap gap-3 justify-end w-full">
    <?php
    $hvCustAct = 'inline-flex items-center justify-center w-36 sm:w-40 min-h-[3rem] flex-shrink-0 rounded-xl px-2 py-2 text-center text-[10px] sm:text-xs font-semibold uppercase tracking-wide leading-tight whitespace-normal';
    ?>
    <button type="button" id="hv-customer-cart-clear" class="<?= e($hvCustAct) ?> hidden border-2 border-gray-200 bg-white text-gray-800 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-200"><?= e($dict['cart']['clear_all']) ?></button>
    <button type="button" id="hv-place-order" class="<?= e($hvCustAct) ?> hidden bg-red-700 text-white border-2 border-red-800 hover:bg-red-800 focus:outline-none focus:ring-2 focus:ring-red-200"><?= e($dict['cart']['send_order']) ?></button>
    <a href="<?= e(base_url()) ?>/<?= e($lang) ?>/account/catalog" class="<?= e($hvCustAct) ?> border-2 border-red-200 bg-white text-red-800 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-100"><?= e($dict['cart']['continue_shopping']) ?></a>
  </div>
  <p id="hv-customer-cart-page-empty" class="hidden mt-8 text-sm text-gray-500 text-center py-14 px-4 border-2 border-dashed border-gray-200/80 rounded-2xl bg-gray-50/30"></p>
</div>
<script>
(function () {
  var base = (window.HV_BASE || '').replace(/\/?$/, '');
  var lang = <?= json_encode($lang) ?>;
  var dict = <?= json_encode($dict, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  var customerId = <?= (int) ($session['customerId'] ?? 0) ?>;
  var resolvedCustomerFvId = <?= (int) $hvCustomerResolvedFvId ?>;
  var customerContactName = <?= json_encode($hvCustomerContactName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  var customerBussName = <?= json_encode($hvCustomerBussName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  var fvCompanyId = <?= json_encode($hvCompanyIdExport, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  var fvLanguageId = <?= (int) lang_to_id($lang) ?>;
  var customerDiscountPct = <?= json_encode((float) $hvCustomerDiscountPct) ?>;
  var customerSellers = <?= $hvCustomerSellersJson ?>;
  var selectedCustomerSellerId = customerSellers.length ? (parseInt(customerSellers[0].id, 10) || 0) : 0;
  var syncEl = document.getElementById('hv-customer-sync');
  var note = document.getElementById('hv-acct-note');

  function setupCustomerSellerSelect() {
    var sel = document.getElementById('hv-customer-seller-select');
    var help = document.getElementById('hv-customer-seller-help');
    if (!sel) return;
    var noneText = lang === 'es' ? 'Sin sellers asignados para este customer.' : 'No sellers assigned for this customer.';
    var pickText = lang === 'es' ? 'Selecciona el seller que recibirá esta orden.' : 'Select the seller that will receive this order.';
    sel.innerHTML = '';
    if (!customerSellers.length) {
      sel.disabled = true;
      var op = document.createElement('option');
      op.value = '';
      op.textContent = noneText;
      sel.appendChild(op);
      if (help) help.textContent = noneText;
      selectedCustomerSellerId = 0;
      return;
    }
    sel.disabled = false;
    customerSellers.forEach(function (s) {
      var sid = parseInt(s.id, 10) || 0;
      if (sid <= 0) return;
      var op = document.createElement('option');
      op.value = String(sid);
      op.textContent = String(s.name || ('Seller #' + sid));
      if (sid === selectedCustomerSellerId) op.selected = true;
      sel.appendChild(op);
    });
    selectedCustomerSellerId = parseInt(sel.value, 10) || selectedCustomerSellerId || 0;
    sel.addEventListener('change', function () {
      selectedCustomerSellerId = parseInt(sel.value, 10) || 0;
    });
    if (help) help.textContent = pickText;
  }

  function fmt(n) {
    return new Intl.NumberFormat(lang === 'es' ? 'es-US' : 'en-US', { style: 'currency', currency: 'USD' }).format(n);
  }

  function escAttr(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function custUp(s) {
    return escAttr(String(s == null ? '' : s).toUpperCase());
  }

  function hvRound2(n) {
    return Math.round((Number(n) + Number.EPSILON) * 100) / 100;
  }

  function hvMysqlNow() {
    var d = new Date();
    function p(x) { return String(x).padStart(2, '0'); }
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
  }

  function hvUuidV4() {
    if (window.crypto && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = Math.random() * 16 | 0;
      var v = c === 'x' ? r : (r & 0x3 | 0x8);
      return v.toString(16);
    });
  }

  function buildCustomerOrderPlacePayload(cartItems, orderCommentText, sellerUserId) {
    var pct = customerDiscountPct;
    if (isNaN(pct) || pct < 0) pct = 0;
    if (pct > 100) pct = 100;
    var lineDiscStr = pct > 0 ? String(hvRound2(pct)) : '0';
    var rawSubtotal = 0;
    var itemList = [];
    for (var i = 0; i < cartItems.length; i++) {
      var it = cartItems[i];
      var pid = parseInt(it.productId, 10) || 0;
      var qty = parseInt(it.qty, 10) || 0;
      if (pid <= 0 || qty <= 0) continue;
      var unit = parseFloat(it.unitPrice) || 0;
      if (!isFinite(unit) || unit < 0) unit = 0;
      var lineTotal = hvRound2(qty * unit);
      rawSubtotal += qty * unit;
      itemList.push({
        product_id: pid,
        qty: qty,
        discount: lineDiscStr,
        discount_type: 1,
        comment: String(it.lineNote || '').trim(),
        groupcustomer: '',
        tipolista: '',
        perc_price: 0,
        salesp: unit,
        impprice: lineTotal,
        totalprice: lineTotal
      });
    }
    var subtotal = hvRound2(rawSubtotal);
    var discountAmt = hvRound2(subtotal * (pct / 100));
    return {
      Id: 0,
      created: hvMysqlNow(),
      contactName: customerContactName,
      bussName: customerBussName,
      tipo_d: 'D',
      order_status: 1,
      user_id: sellerUserId,
      language_id: fvLanguageId,
      customer_id: resolvedCustomerFvId,
      order_comment: orderCommentText,
      discount: String(hvRound2(discountAmt)),
      discount_type: 1,
      amount: String(hvRound2(subtotal)),
      company_id: fvCompanyId,
      uuid: hvUuidV4(),
      itemList: itemList
    };
  }

  function setCustomerPrimaryCartActionsVisible(show) {
    var c = document.getElementById('hv-customer-cart-clear');
    var p = document.getElementById('hv-place-order');
    if (c) c.classList.toggle('hidden', !show);
    if (p) p.classList.toggle('hidden', !show);
  }

  function renderCustomerCartAccountPage() {
    var wrap = document.getElementById('hv-customer-cart-lines-wrap');
    var notesRow = document.getElementById('hv-customer-notes-summary-row');
    var emptyEl = document.getElementById('hv-customer-cart-page-empty');
    var tbody = document.getElementById('hv-customer-cart-tbody');
    var summaryEl = document.getElementById('hv-customer-cart-summary');
    if (!window.HV || !HV.cart) return;
    var items = HV.cart.load();
    var discountPct = customerDiscountPct;
    if (isNaN(discountPct) || discountPct < 0) discountPct = 0;
    if (discountPct > 100) discountPct = 100;
    if (!items.length) {
      if (wrap) wrap.classList.add('hidden');
      if (notesRow) notesRow.classList.add('hidden');
      setCustomerPrimaryCartActionsVisible(false);
      if (emptyEl) {
        emptyEl.classList.remove('hidden');
        emptyEl.textContent = dict.cart.empty || '';
      }
      if (summaryEl) summaryEl.classList.add('hidden');
      return;
    }
    if (wrap) wrap.classList.remove('hidden');
    if (notesRow) notesRow.classList.remove('hidden');
    setCustomerPrimaryCartActionsVisible(true);
    if (emptyEl) emptyEl.classList.add('hidden');
    var moqLab = (dict.home && dict.home.moq_label) ? dict.home.moq_label : 'MOQ:';
    var subtotal = 0;
    var rows = items.map(function (it) {
      var pid = it.productId;
      var moq = Math.max(1, parseInt(it.moq, 10) || 1);
      var up = parseFloat(it.unitPrice);
      if (isNaN(up)) up = 0;
      var q = parseInt(it.qty, 10) || 0;
      var line = up * q;
      subtotal += line;
      var img = escAttr(it.image || '');
      var nm = custUp(it.name || '');
      var sku = custUp(it.sku || '');
      var ln = String(it.lineNote || '').slice(0, 2000);
      var ph = escAttr(dict.cart.line_notes_placeholder || '');
      return '<tr class="hover:bg-gray-50/70 transition-colors">' +
        '<td class="px-4 py-3.5"><img src="' + img + '" alt="" class="w-14 h-14 object-contain rounded-xl bg-gray-50 border border-gray-100/80"/></td>' +
        '<td class="px-4 py-3.5"><p class="font-semibold text-gray-900 leading-snug">' + nm + '</p>' +
        '<p class="text-xs text-gray-500 mt-0.5 uppercase tracking-wide">' + sku + '</p>' +
        '<p class="text-[11px] text-gray-400 mt-1">' + escAttr(moqLab) + ' ' + moq + '</p></td>' +
        '<td class="px-4 py-3.5 align-top text-right"><span class="inline-block text-sm font-semibold tabular-nums text-gray-900 py-2 px-1">' + escAttr(fmt(up)) + '</span></td>' +
        '<td class="px-4 py-3.5 align-top"><input type="number" min="0" step="1" inputmode="numeric" class="hv-customer-line-qty w-full max-w-[6rem] rounded-xl border border-gray-200/90 bg-white px-2.5 py-2 text-sm font-semibold tabular-nums text-right shadow-sm focus:border-red-300 focus:ring-2 focus:ring-red-500/15 focus:outline-none [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none" data-pid="' + pid + '" data-moq="' + moq + '" value="' + q + '"/></td>' +
        '<td class="px-4 py-3.5 text-right font-semibold text-gray-900 tabular-nums align-top hv-customer-line-total" data-pid="' + pid + '">' + fmt(line) + '</td>' +
        '<td class="px-4 py-3.5 align-top"><textarea rows="2" class="hv-customer-line-note w-full min-w-[8rem] max-w-xs rounded-xl border border-gray-200/90 bg-white px-2.5 py-2 text-xs text-gray-900 shadow-sm focus:border-red-300 focus:ring-2 focus:ring-red-500/15 focus:outline-none" data-pid="' + pid + '" placeholder="' + ph + '">' + escAttr(ln) + '</textarea></td>' +
        '<td class="px-4 py-3.5 align-middle text-center w-14">' +
        '<button type="button" class="hv-customer-line-remove inline-flex items-center justify-center h-10 w-10 rounded-xl border border-gray-200/90 bg-white text-red-600 hover:bg-red-50 hover:text-red-800 hover:border-red-200 focus:outline-none focus:ring-2 focus:ring-red-200/60" data-pid="' + pid + '" title="' + escAttr(dict.cart.remove || 'Remove') + '" aria-label="' + escAttr(dict.cart.remove || 'Remove') + '">' +
        '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">' +
        '<path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />' +
        '</svg></button></td></tr>';
    }).join('');
    if (tbody) tbody.innerHTML = rows;
    var discAmt = subtotal * (discountPct / 100);
    var total = subtotal - discAmt;
    if (summaryEl) {
      summaryEl.classList.remove('hidden');
      var elSub = document.getElementById('hv-customer-sum-sub');
      var elDiscLbl = document.getElementById('hv-customer-sum-discount-label');
      var elDiscAmt = document.getElementById('hv-customer-sum-discount-amt');
      var elTot = document.getElementById('hv-customer-sum-total');
      var discRow = document.getElementById('hv-customer-sum-discount-row');
      if (elSub) elSub.textContent = fmt(subtotal);
      var pctDisp = Math.round(discountPct * 100) / 100;
      var tmpl = dict.cart.discount_percent || 'Discount ({pct}%)';
      if (elDiscLbl) elDiscLbl.textContent = tmpl.replace(/\{pct\}/g, String(pctDisp));
      if (elDiscAmt) elDiscAmt.textContent = discAmt > 0 ? ('− ' + fmt(discAmt)) : fmt(0);
      if (elTot) elTot.textContent = fmt(total);
      if (discRow) {
        if (discountPct > 0 && discAmt > 0) discRow.classList.remove('hidden');
        else discRow.classList.add('hidden');
      }
    }
    if (tbody) {
      tbody.querySelectorAll('.hv-customer-line-qty').forEach(function (inp) {
        inp.addEventListener('change', function () {
          var pid = parseInt(inp.getAttribute('data-pid'), 10);
          HV.cart.setItemQty(pid, inp.value);
        });
      });
      tbody.querySelectorAll('.hv-customer-line-note').forEach(function (ta) {
        ta.addEventListener('change', function () {
          var pid = parseInt(ta.getAttribute('data-pid'), 10);
          HV.cart.setItemLineNote(pid, ta.value);
        });
      });
      tbody.querySelectorAll('.hv-customer-line-remove').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var pid = parseInt(btn.getAttribute('data-pid'), 10);
          HV.cart.remove(pid);
        });
      });
    }
  }

  function loadCart() {
    return fetch(base + '/' + lang + '/account/cart-db?lang=' + encodeURIComponent(lang), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.error) throw new Error(data.error);
        return data.items || [];
      });
  }

  function initCustomerAccountCart() {
    function hideSyncOk() {
      if (syncEl) {
        syncEl.classList.add('hidden');
      }
    }
    function showSyncError(msg) {
      if (!syncEl) return;
      syncEl.classList.remove('hidden');
      syncEl.textContent = msg || dict.common.error;
      syncEl.classList.remove('text-gray-500');
      syncEl.classList.add('text-red-700');
    }
    var boot = Promise.resolve();
    if (window.HV && HV.cart && typeof HV.cart.refreshPortalFromServer === 'function') {
      boot = HV.cart.refreshPortalFromServer();
    } else {
      boot = loadCart().then(function () {});
    }
    boot
      .then(function () {
        hideSyncOk();
        renderCustomerCartAccountPage();
      })
      .catch(function (e) {
        console.error(e);
        showSyncError(dict.common.error);
      });

    window.addEventListener('hv-cart-change', function () {
      hideSyncOk();
      renderCustomerCartAccountPage();
    });

    var clearBtn = document.getElementById('hv-customer-cart-clear');
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        if (!window.HV || !HV.cart || !HV.cart.load().length) return;
        var afterClear = function () {
          HV.cart.clear();
          if (HV.toast && dict.toast && dict.toast.cart_cleared) HV.toast(dict.toast.cart_cleared);
        };
        if (typeof HV.confirm === 'function') {
          HV.confirm({ text: dict.cart.confirm_clear, icon: 'warning' }).then(function (ok) { if (ok) afterClear(); });
        } else if (window.confirm(dict.cart.confirm_clear)) {
          afterClear();
        }
      });
    }

    document.getElementById('hv-place-order').addEventListener('click', function () {
      if (customerId <= 0) {
        note.textContent = <?= json_encode($lang === 'es' ? 'Tu cuenta aún no tiene cliente mayorista vinculado. Contacta soporte.' : 'Your account has no wholesale customer linked. Please contact support.') ?>;
        note.classList.remove('hidden');
        return;
      }
      var placeBtn = this;
      var sync = Promise.resolve();
      if (window.HV && HV.cart && typeof HV.cart.refreshPortalFromServer === 'function') {
        sync = HV.cart.refreshPortalFromServer();
      }
      if (placeBtn) {
        placeBtn.disabled = true;
        placeBtn.setAttribute('aria-busy', 'true');
      }
      sync
        .then(function () {
          var items = HV.cart && typeof HV.cart.load === 'function' ? HV.cart.load() : [];
          if (!items.length) {
            if (HV.toast) HV.toast(dict.cart.empty || dict.common.error, { icon: 'warning' });
            return null;
          }
          var hasLine = items.some(function (it) {
            return (parseInt(it.productId, 10) || 0) > 0 && (parseInt(it.qty, 10) || 0) > 0;
          });
          if (!hasLine) {
            if (HV.toast) HV.toast(dict.cart.empty || dict.common.error, { icon: 'warning' });
            return null;
          }
          var sellerUid = parseInt(selectedCustomerSellerId, 10) || 0;
          if (sellerUid <= 0) {
            if (HV.toast) HV.toast(lang === 'es' ? 'Selecciona un seller para enviar la orden.' : 'Select a seller to place the order.', { icon: 'warning' });
            return null;
          }
          var general = String(document.getElementById('hv-order-comment').value || '').trim();
          var lineBlock = items
            .filter(function (it) { return String(it.lineNote || '').trim(); })
            .map(function (it) {
              var sku = String(it.sku || '').trim() || ('#' + it.productId);
              return sku + ': ' + String(it.lineNote || '').trim();
            })
            .join('\n');
          var orderComment = general;
          if (lineBlock) {
            orderComment = orderComment ? orderComment + '\n\n---\n' + lineBlock : lineBlock;
          }
          var orderPlace = buildCustomerOrderPlacePayload(items, orderComment, sellerUid);
          if (!orderPlace.itemList.length) {
            if (HV.toast) HV.toast(dict.cart.empty || dict.common.error, { icon: 'warning' });
            return null;
          }
          try { console.log('[HV] payload /api/customer-orders (OrderPlace):', JSON.stringify(orderPlace)); } catch (e) {}
          return fetch(base + '/api/customer-orders', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(orderPlace)
          }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); });
        })
        .then(function (x) {
          if (!x) return;
          if (!x.ok) {
            HV.toast && HV.toast(x.j.error || dict.common.error, { icon: 'error' });
            return;
          }
          if (window.HV && HV.cart && typeof HV.cart.clear === 'function') {
            HV.cart.clear();
          }
          var oc = document.getElementById('hv-order-comment');
          if (oc) oc.value = '';
          renderCustomerCartAccountPage();
          HV.toast && HV.toast(dict.toast.order_placed);
          try { console.log('[HV] /api/customer-orders response OK:', JSON.stringify(x.j)); } catch (e) {}
          setTimeout(function () {
            window.location.href = base + '/' + lang + '/account/catalog';
          }, 800);
        })
        .catch(function (e) {
          console.error(e);
          HV.toast && HV.toast(dict.common.error, { icon: 'error' });
        })
        .finally(function () {
          if (placeBtn) {
            placeBtn.disabled = false;
            placeBtn.removeAttribute('aria-busy');
          }
        });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      setupCustomerSellerSelect();
      initCustomerAccountCart();
    });
  } else {
    setupCustomerSellerSelect();
    initCustomerAccountCart();
  }
})();
</script>
<?php } ?>
<?php
$content = ob_get_clean();
$pageTitle = $dict['cart']['title'] . ' — ' . ($dict['seo']['title'] ?? '');
require __DIR__ . '/../layout.php';
