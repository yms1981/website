<?php

declare(strict_types=1);

$session = require_account_session($lang, true);
require_once dirname(__DIR__, 2) . '/lib/FullVendorOrdersDb.php';

$rol = (int) ($session['rolId'] ?? 0);
$fvUid = (int) ($session['userId'] ?? 0);
$fvCid = (int) ($session['customerId'] ?? 0);

/** @var list<array<string, mixed>> */
$hvOrdersList = [];
$hvOrdersDbError = false;

if (fullvendor_db_configured()) {
    try {
        $hvOrdersList = array_values(array_filter(
            FullVendorOrdersDb::listForAccount($rol, $fvUid, $fvCid, $lang),
            static function (array $row): bool {
                return HvOrderUi::orderRowIsCompleted($row);
            }
        ));
    } catch (Throwable $e) {
        $hvOrdersDbError = true;
        if (function_exists('app_debug') && app_debug()) {
            AppLog::appException($e, 'orders list db');
        }
    }
}

$fmtDate = static function (?string $raw, string $lang): string {
    if ($raw === null || trim($raw) === '') {
        return '';
    }
    $t = trim($raw);
    $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $t)
        ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $t);
    if ($dt === false) {
        $dt = date_create_immutable(str_replace(' ', 'T', $t));
    }
    if ($dt === false) {
        return $t;
    }
    $ts = $dt->getTimestamp();
    if (class_exists(IntlDateFormatter::class)) {
        $locale = str_starts_with($lang, 'es') ? 'es_ES' : 'en_US';
        $fmt = new IntlDateFormatter($locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE);
        $out = $fmt->format($ts);

        return $out !== false ? (string) $out : $dt->format('Y-m-d');
    }

    return $dt->format(str_starts_with($lang, 'es') ? 'd/m/Y' : 'M j, Y');
};

$fmtMoney = static function (float $n, string $lang): string {
    if (class_exists(NumberFormatter::class)) {
        $loc = str_starts_with($lang, 'es') ? 'es_US' : 'en_US';
        $f = new NumberFormatter($loc, NumberFormatter::CURRENCY);
        $s = $f->formatCurrency($n, 'USD');

        return $s !== false ? $s : '$' . number_format($n, 2, '.', ',');
    }

    return '$' . number_format($n, 2, '.', ',');
};

$safeHex = static function (?string $c): string {
    $c = trim((string) $c);

    return preg_match('/^#[0-9A-Fa-f]{3,8}$/', $c) ? $c : '';
};

$d = $dict['orders'] ?? [];
$od = $dict['order_detail'] ?? [];
$hvWaDigits = preg_replace('/\D/', '', whatsapp_business_number());
$hvOrderPayUrlTpl = trim(config('HV_ORDER_PAY_URL', ''));
$hvOrdersUiLabels = [
    'modal_products_title' => (string) ($d['modal_products_title'] ?? ''),
    'loading_lines' => (string) ($d['loading_lines'] ?? ''),
    'lines_load_error' => (string) ($d['lines_load_error'] ?? ''),
    'pay_modal_title' => (string) ($d['pay_modal_title'] ?? ''),
    'pay_modal_intro' => (string) ($d['pay_modal_intro'] ?? ''),
    'pay_wa_hint' => (string) ($d['pay_wa_hint'] ?? ''),
    'pay_wa_button' => (string) ($d['pay_wa_button'] ?? ''),
    'pay_wa_text' => (string) ($d['pay_wa_text'] ?? ''),
    'pay_open_link' => (string) ($d['pay_open_link'] ?? ''),
    'col_num' => (string) ($od['po_col_item'] ?? $od['col_num'] ?? '#'),
    'description' => (string) ($od['invoice_table_description'] ?? ''),
    'sku' => (string) ($od['sku'] ?? 'SKU'),
    'qty' => (string) ($od['po_col_qty'] ?? $od['quantity'] ?? 'Qty'),
    'unit' => (string) ($od['po_col_unit'] ?? $od['invoice_table_unit_price'] ?? ''),
    'line_total' => (string) ($od['po_col_line_total'] ?? $od['invoice_table_amount'] ?? ''),
    'no_lines' => (string) ($od['no_lines'] ?? ''),
    'col_image' => (string) ($od['col_image'] ?? 'Photo'),
    'modal_order_total' => (string) ($d['modal_order_total'] ?? ''),
];
ob_start();
?>
<div class="max-w-[1400px] mx-auto px-4 py-8">
  <h1 class="text-2xl font-bold text-gray-900"><?= e($dict['orders']['title']) ?></h1>
  <div id="hv-orders" class="mt-6 text-sm">
    <?php if (!fullvendor_db_configured()) { ?>
      <p class="text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-4 py-3"><?= e($dict['common']['error'] ?? 'Error') ?> — FullVendor DB</p>
    <?php } elseif ($hvOrdersDbError) { ?>
      <p class="text-red-700 bg-red-50 border border-red-200 rounded-lg px-4 py-3"><?= e($dict['common']['error']) ?></p>
    <?php } elseif ($hvOrdersList === []) { ?>
      <p class="text-gray-500"><?= e($dict['orders']['no_orders']) ?></p>
    <?php } else { ?>
    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
      <table class="min-w-[1100px] w-full text-left text-sm">
        <thead>
          <tr class="border-b border-gray-200 bg-gray-50 text-gray-700">
            <th class="py-3 pl-4 pr-2 w-12">
              <label class="inline-flex items-center gap-2 text-blue-600 font-medium cursor-default">
                <input type="checkbox" id="hv-orders-select-all" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                <span><?= e($d['select_all'] ?? 'All') ?></span>
              </label>
            </th>
            <th class="py-3 px-2 text-blue-600 font-medium"><?= e($d['order_number'] ?? '#') ?></th>
            <th class="py-3 px-2 text-blue-600 font-medium"><?= e($d['customer'] ?? '') ?></th>
            <th class="py-3 px-2 text-blue-600 font-medium"><?= e($d['seller'] ?? '') ?></th>
            <th class="py-3 px-2 text-blue-600 font-medium"><?= e($d['date'] ?? '') ?></th>
            <th class="py-3 px-2 text-blue-600 font-medium"><?= e($d['total'] ?? '') ?></th>
            <th class="py-3 px-2 text-blue-600 font-medium"><?= e($d['comments'] ?? '') ?></th>
            <th class="py-3 px-2 text-blue-600 font-medium"><?= e($d['status'] ?? '') ?></th>
            <th class="py-3 pr-4 pl-2 text-blue-600 font-medium whitespace-nowrap"><?= e($d['actions'] ?? 'Actions') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($hvOrdersList as $o) {
              if (!is_array($o)) {
                  continue;
              }
              $oid = isset($o['order_id']) && is_numeric($o['order_id']) ? (int) $o['order_id'] : 0;
              $num = (string) ($o['order_number'] ?? $o['order_id'] ?? '');
              $cust = (string) ($o['customer_name'] ?? '');
              $seller = (string) ($o['seller_name'] ?? '');
              $created = (string) ($o['order_date'] ?? $o['created'] ?? $o['date'] ?? '');
              $upd = (string) ($o['updated'] ?? '');
              $comments = (string) ($o['order_comments'] ?? $o['comments'] ?? '');
              $stLabel = (string) ($o['status_label'] ?? $o['status'] ?? '');
              $dotCol = $safeHex((string) ($o['status_icon_color'] ?? $o['status_color'] ?? ''));
              $totalVal = isset($o['total_value']) && is_numeric($o['total_value']) ? (float) $o['total_value'] : 0.0;
              $mobile = !empty($o['is_mobile_order']);
              $totalFmt = $fmtMoney($totalVal, $lang);
              $payHref = '';
              if ($hvOrderPayUrlTpl !== '' && $oid > 0) {
                  $payHref = str_replace(
                      ['{order_id}', '{order_number}'],
                      [(string) $oid, rawurlencode($num)],
                      $hvOrderPayUrlTpl
                  );
              }
              ?>
          <tr class="border-b border-gray-100 hover:bg-gray-50/80 align-top">
            <td class="py-4 pl-4 pr-2"><input type="checkbox" class="hv-order-cb rounded border-gray-300 text-blue-600" /></td>
            <td class="py-4 px-2">
              <span class="inline-flex items-center font-bold text-emerald-700">
                <?php if ($mobile) { ?>
                <span class="inline-flex items-center" title="<?= e($d['mobile_order_hint'] ?? '') ?>">
                  <svg class="inline-block w-4 h-4 mr-1 text-emerald-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
                </span>
                <?php } ?>
                <?= e($num) ?>
              </span>
            </td>
            <td class="py-4 px-2 font-bold text-emerald-700"><?= e($cust) ?></td>
            <td class="py-4 px-2 text-emerald-700 font-medium"><?= e($seller) ?></td>
            <td class="py-4 px-2 text-emerald-700">
              <div><?= e($fmtDate($created !== '' ? $created : null, $lang)) ?></div>
              <?php if ($upd !== '') { ?>
              <div class="mt-1 inline-block rounded px-2 py-0.5 text-xs font-medium bg-amber-100 text-gray-900 border border-amber-200">
                <?= e($d['updated_badge'] ?? 'Updated') ?>: <?= e($fmtDate($upd, $lang)) ?>
              </div>
              <?php } ?>
            </td>
            <td class="py-4 px-2">
              <div class="inline-block rounded-md bg-gray-900 text-white text-xs font-semibold px-2.5 py-1"><?= e($d['badge_total'] ?? 'Total') ?> <?= e($fmtMoney($totalVal, $lang)) ?></div>
            </td>
            <td class="py-4 px-2 text-gray-600 max-w-[200px]"><?= e($comments) ?></td>
            <td class="py-4 pr-4 pl-2">
              <span class="inline-flex items-center gap-2 rounded-full bg-gray-200 text-gray-800 px-3 py-1.5 text-xs font-medium">
                <?php if ($dotCol !== '') { ?>
                <span class="w-2 h-2 rounded-full flex-shrink-0" style="background-color:<?= e($dotCol) ?>" aria-hidden="true"></span>
                <?php } else { ?>
                <span class="w-2 h-2 rounded-full bg-emerald-500 flex-shrink-0" aria-hidden="true"></span>
                <?php } ?>
                <?= e($stLabel) ?>
              </span>
            </td>
            <td class="py-4 pr-4 pl-2 whitespace-nowrap">
              <div class="flex flex-wrap items-center gap-1.5">
                <?php if ($oid > 0) { ?>
                <button type="button" class="hv-order-btn-view px-2.5 py-1.5 rounded-lg border border-gray-200 bg-white text-xs font-semibold text-gray-800 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500" data-order-id="<?= (int) $oid ?>"><?= e($d['btn_view'] ?? 'View') ?></button>
                <?php if ($payHref !== '') { ?>
                <a href="<?= e($payHref) ?>" class="inline-flex px-2.5 py-1.5 rounded-lg bg-red-700 text-xs font-semibold text-white hover:bg-red-800 focus:outline-none focus:ring-2 focus:ring-red-500" target="_blank" rel="noopener noreferrer"><?= e($d['btn_pay'] ?? 'Pay') ?></a>
                <?php } else { ?>
                <button type="button" class="hv-order-btn-pay px-2.5 py-1.5 rounded-lg bg-red-700 text-xs font-semibold text-white hover:bg-red-800 focus:outline-none focus:ring-2 focus:ring-red-500" data-order-id="<?= (int) $oid ?>" data-order-num="<?= e($num) ?>" data-order-total="<?= e($totalFmt) ?>"><?= e($d['btn_pay'] ?? 'Pay') ?></button>
                <?php } ?>
                <?php } ?>
              </div>
            </td>
          </tr>
          <?php } ?>
        </tbody>
      </table>
    </div>
    <script>
    (function () {
      var all = document.getElementById('hv-orders-select-all');
      if (all) {
        all.addEventListener('change', function () {
          document.querySelectorAll('.hv-order-cb').forEach(function (cb) { cb.checked = all.checked; });
        });
      }
      var base = <?= json_encode(rtrim(base_url(), '/'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
      var lang = <?= json_encode($lang, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
      var L = <?= json_encode($hvOrdersUiLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
      var waDigits = <?= json_encode($hvWaDigits, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
      function esc(s) {
        return String(s == null ? '' : s)
          .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
      }
      var HV_NO_IMG = 'https://app.fullvendor.com/uploads/noimg.png';
      function moneyFmt(n) {
        var x = Number(n);
        if (isNaN(x)) x = 0;
        if (window.Intl && Intl.NumberFormat) {
          return new Intl.NumberFormat(lang === 'es' ? 'es-US' : 'en-US', { style: 'currency', currency: 'USD' }).format(x);
        }
        return '$' + x.toFixed(2);
      }
      function moneyLine(qty, sp, line) {
        var q = Number(qty);
        var p = Number(sp);
        var ln = Number(line);
        if (isNaN(q)) q = 0;
        if (isNaN(p)) p = 0;
        if (isNaN(ln)) ln = q * p;
        return {
          unit: moneyFmt(p),
          line: moneyFmt(ln),
          qty: q.toLocaleString(lang === 'es' ? 'es-US' : 'en-US', { maximumFractionDigits: 2 })
        };
      }
      /** Convierte total_order_formatted (p. ej. "24960.00") o importes con $ / miles a número. */
      function parseAmountForDisplay(v) {
        if (v == null || v === '') return NaN;
        var t = String(v).trim().replace(/\$/g, '').replace(/,/g, '');
        var n = parseFloat(t);
        return n;
      }
      function orderGrandTotalFormatted(ord) {
        if (!ord || typeof ord !== 'object') return '';
        var s = ord.summary;
        if (s && s.total_order_formatted != null && String(s.total_order_formatted).trim() !== '') {
          var n0 = parseAmountForDisplay(s.total_order_formatted);
          if (!isNaN(n0)) return moneyFmt(n0);
        }
        if (s && s.total_order != null && String(s.total_order) !== '') {
          var n1 = parseFloat(String(s.total_order).replace(',', '.'));
          if (!isNaN(n1)) return moneyFmt(n1);
        }
        var tv = parseFloat(String(ord.total_value != null ? ord.total_value : '').replace(',', '.'));
        if (!isNaN(tv) && tv > 0) return moneyFmt(tv);
        var ta = ord.total_amount;
        if (ta != null && String(ta).trim() !== '') {
          var n2 = parseAmountForDisplay(ta);
          if (!isNaN(n2) && n2 > 0) return moneyFmt(n2);
        }
        return '';
      }
      function normalizeRowsFromOrder(ord) {
        if (!ord || typeof ord !== 'object') return [];
        if (Array.isArray(ord.detail_lines) && ord.detail_lines.length > 0) {
          return ord.detail_lines.map(function (dl) {
            if (!dl || typeof dl !== 'object') return null;
            var qty = parseFloat(String(dl.quantity != null ? dl.quantity : dl.quantity_formatted || '0').replace(',', '.'));
            var sp = parseFloat(String(dl.sale_price != null ? dl.sale_price : dl.sale_price_formatted || '0').replace(',', '.'));
            if (isNaN(qty)) qty = 0;
            if (isNaN(sp)) sp = 0;
            var lineRaw = parseFloat(String(dl.total_order != null ? dl.total_order : '').replace(',', '.'));
            var lineTot = !isNaN(lineRaw) && lineRaw > 0 ? lineRaw : qty * sp;
            return {
              image_url: String(dl.image_url || '').trim(),
              name: String(dl.name || '').trim(),
              sku: String(dl.sku || '').trim(),
              qty: qty,
              sale_price: sp,
              line_total: lineTot
            };
          }).filter(Boolean);
        }
        var pl = ord.product_list;
        if (!Array.isArray(pl) || pl.length === 0) return [];
        return pl.map(function (r) {
          if (!r || typeof r !== 'object') return null;
          var qty = parseFloat(String(r.qty != null ? r.qty : '0').replace(',', '.'));
          var sp = parseFloat(String(r.sale_price != null ? r.sale_price : '0').replace(',', '.'));
          if (isNaN(qty)) qty = 0;
          if (isNaN(sp)) sp = 0;
          return {
            image_url: '',
            name: String(r.name != null ? r.name : '').trim(),
            sku: String(r.sku != null ? r.sku : '').trim(),
            qty: qty,
            sale_price: sp,
            line_total: qty * sp
          };
        }).filter(Boolean);
      }
      /** Tabla con estilos en línea: SweetAlert no hereda bien las clases Tailwind del HTML inyectado. */
      function buildProductsTableHtml(rows, totalFormatted) {
        if (!Array.isArray(rows) || rows.length === 0) {
          return '<p style="margin:0;padding:12px 0;color:#6b7280;font-size:14px;">' + esc(L.no_lines || '') + '</p>';
        }
        var th = 'padding:11px 10px;text-align:left;font-weight:700;font-size:10px;letter-spacing:0.08em;text-transform:uppercase;color:#fff;border-bottom:1px solid rgba(255,255,255,0.12);';
        var thC = th + 'text-align:center;';
        var thR = th + 'text-align:right;';
        var td = 'padding:12px 10px;vertical-align:middle;border-bottom:1px solid #f1f5f9;color:#334155;font-size:13px;';
        var tdR = td + 'text-align:right;font-variant-numeric:tabular-nums;';
        var tdC = td + 'text-align:center;font-variant-numeric:tabular-nums;';
        var h = '';
        h += '<div style="border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;max-height:min(58vh,440px);overflow-y:auto;text-align:left;background:#fff;box-shadow:0 1px 3px rgba(15,23,42,0.06);">';
        h += '<table style="width:100%;border-collapse:collapse;font-family:ui-sans-serif,system-ui,-apple-system,sans-serif;">';
        h += '<thead><tr style="background:linear-gradient(135deg,#4f46e5 0%,#4338ca 55%,#312e81 100%);">';
        h += '<th style="' + thC + 'width:44px;">' + esc(L.col_num) + '</th>';
        h += '<th style="' + thC + 'width:64px;">' + esc(L.col_image) + '</th>';
        h += '<th style="' + th + 'min-width:140px;">' + esc(L.description) + '</th>';
        h += '<th style="' + th + 'width:88px;">' + esc(L.sku) + '</th>';
        h += '<th style="' + thC + 'width:72px;">' + esc(L.qty) + '</th>';
        h += '<th style="' + thR + 'width:96px;">' + esc(L.unit) + '</th>';
        h += '<th style="' + thR + 'width:104px;">' + esc(L.line_total) + '</th>';
        h += '</tr></thead><tbody>';
        for (var i = 0; i < rows.length; i++) {
          var r = rows[i];
          if (!r || typeof r !== 'object') continue;
          var m = moneyLine(r.qty, r.sale_price, r.line_total);
          var imgSrc = (r.image_url && String(r.image_url).trim()) ? String(r.image_url).trim() : HV_NO_IMG;
          h += '<tr style="background:' + (i % 2 === 0 ? '#fff' : '#fafbfc') + ';">';
          h += '<td style="' + tdC + 'color:#64748b;font-weight:600;">' + (i + 1) + '</td>';
          h += '<td style="' + tdC + '">';
          h += '<img src="' + esc(imgSrc) + '" alt="" width="52" height="52" style="width:52px;height:52px;object-fit:contain;border-radius:10px;background:#f8fafc;border:1px solid #e2e8f0;display:block;margin:0 auto;" loading="lazy" onerror="this.onerror=null;this.src=\'' + HV_NO_IMG.replace(/'/g, '%27') + '\'" />';
          h += '</td>';
          h += '<td style="' + td + 'font-weight:600;color:#0f172a;line-height:1.35;">' + esc(r.name) + '</td>';
          h += '<td style="' + td + 'font-family:ui-monospace,monospace;font-size:12px;color:#64748b;">' + esc(r.sku) + '</td>';
          h += '<td style="' + tdC + 'font-weight:600;">' + esc(m.qty) + '</td>';
          h += '<td style="' + tdR + '">' + esc(m.unit) + '</td>';
          h += '<td style="' + tdR + 'font-weight:700;color:#0f172a;">' + esc(m.line) + '</td>';
          h += '</tr>';
        }
        h += '</tbody>';
        if (totalFormatted) {
          h += '<tfoot><tr style="background:linear-gradient(180deg,#f8fafc 0%,#f1f5f9 100%);border-top:2px solid #cbd5e1;">';
          h += '<td colspan="6" style="padding:14px 12px;text-align:right;font-size:11px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:#475569;">' + esc(L.modal_order_total) + '</td>';
          h += '<td style="padding:14px 12px;text-align:right;font-size:17px;font-weight:800;color:#1e1b4b;font-variant-numeric:tabular-nums;white-space:nowrap;">' + esc(totalFormatted) + '</td>';
          h += '</tr></tfoot>';
        }
        h += '</table></div>';
        return h;
      }
      function openViewModal(orderId) {
        if (typeof Swal === 'undefined') return;
        var okLbl = (window.HV_SWAL_I18N && window.HV_SWAL_I18N.confirm) ? window.HV_SWAL_I18N.confirm : 'OK';
        Swal.fire({
          title: esc(L.modal_products_title),
          html: '<p style="margin:0 0 8px;color:#64748b;font-size:14px;">' + esc(L.loading_lines) + '</p>',
          width: 'min(720px, 96vw)',
          showConfirmButton: true,
          confirmButtonText: okLbl
        });
        fetch(base + '/api/orders/from-db/' + encodeURIComponent(String(orderId)) + '?lang=' + encodeURIComponent(lang), {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' }
        })
          .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
          .then(function (data) {
            var ord = data && data.order;
            var rows = normalizeRowsFromOrder(ord);
            var totalFmt = orderGrandTotalFormatted(ord);
            if (!totalFmt && rows.length) {
              var sum = 0;
              for (var j = 0; j < rows.length; j++) {
                var lt = parseFloat(rows[j].line_total);
                if (!isNaN(lt)) sum += lt;
              }
              totalFmt = moneyFmt(sum);
            }
            Swal.update({ html: buildProductsTableHtml(rows, totalFmt) });
          })
          .catch(function () {
            Swal.update({
              html: '<p class="text-red-700 text-sm">' + esc(L.lines_load_error) + '</p>',
              icon: 'error'
            });
          });
      }
      function openPayModal(orderNum, totalFmt) {
        if (typeof Swal === 'undefined') return;
        var intro = String(L.pay_modal_intro || '')
          .replace(/\{number\}/g, orderNum)
          .replace(/\{total\}/g, totalFmt);
        var waText = String(L.pay_wa_text || '')
          .replace(/\{number\}/g, orderNum)
          .replace(/\{total\}/g, totalFmt);
        var waUrl = '';
        if (waDigits && waDigits.length > 6) {
          waUrl = 'https://wa.me/' + waDigits + '?text=' + encodeURIComponent(waText);
        }
        var html = '<p class="text-gray-800 text-sm text-left">' + esc(intro) + '</p>';
        if (waUrl) {
          html += '<p class="text-gray-600 text-sm text-left mt-3">' + esc(L.pay_wa_hint) + '</p>';
          html += '<p class="mt-4 text-center"><a href="' + esc(waUrl) + '" target="_blank" rel="noopener noreferrer" class="inline-flex items-center justify-center px-4 py-2.5 rounded-xl bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700">' + esc(L.pay_wa_button) + '</a></p>';
        }
        Swal.fire({
          title: esc(L.pay_modal_title),
          html: html,
          icon: 'info',
          width: 'min(28rem, 96vw)',
          confirmButtonText: (window.HV_SWAL_I18N && window.HV_SWAL_I18N.confirm) ? window.HV_SWAL_I18N.confirm : 'OK'
        });
      }
      var wrap = document.getElementById('hv-orders');
      if (!wrap) return;
      wrap.addEventListener('click', function (e) {
        var v = e.target && e.target.closest ? e.target.closest('.hv-order-btn-view') : null;
        if (v) {
          e.preventDefault();
          var id = parseInt(v.getAttribute('data-order-id'), 10);
          if (id) openViewModal(id);
          return;
        }
        var p = e.target && e.target.closest ? e.target.closest('.hv-order-btn-pay') : null;
        if (p) {
          e.preventDefault();
          var num = p.getAttribute('data-order-num') || '';
          var tot = p.getAttribute('data-order-total') || '';
          openPayModal(num, tot);
        }
      });
    })();
    </script>
    <?php } ?>
  </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = $dict['orders']['title'] . ' — ' . ($dict['seo']['title'] ?? '');
require __DIR__ . '/../layout.php';
