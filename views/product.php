<?php
/** @var array<string, mixed> $product */
/** @var array<int, array<string, mixed>> $relatedProducts */
$relatedProducts = $relatedProducts ?? [];
$hv_session = Auth::getSession();
$hv_logged_in = is_array($hv_session) && !empty($hv_session['approved']);
$hv_show_inventory_qty = true;
if ($hv_logged_in && (int) ($hv_session['rolId'] ?? 0) === 3 && function_exists('fullvendor_db_configured') && fullvendor_db_configured()) {
    require_once dirname(__DIR__) . '/lib/FullVendorDb.php';
    $hv_show_inventory_qty = FullVendorDb::companyShowsProductInventory();
}
$hv_customer_price_adj = null;
if ($hv_logged_in && (int) ($hv_session['rolId'] ?? 0) === 3 && class_exists('Db', false) && Db::enabled()) {
    $hv_cfv = (int) ($hv_session['customerId'] ?? 0);
    if ($hv_cfv > 0) {
        require_once dirname(__DIR__) . '/lib/CustomerPriceAdjustment.php';
        $hv_customer_price_adj = CustomerPriceAdjustment::forCustomerFv(Db::pdo(), $hv_cfv, (int) config('FULLVENDOR_COMPANY_ID', '0'));
        if (($hv_customer_price_adj['kind'] ?? 'none') === 'none') {
            $hv_customer_price_adj = null;
        }
    }
}
$img = get_product_image($product);
$images = $product['images'] ?? [];
if (!is_array($images)) {
    $images = [];
}
$showPrice = isset($product['sale_price']) && $product['sale_price'] !== '' && $product['sale_price'] !== null;
$price = $showPrice ? (float) $product['sale_price'] : null;
$pdp_display_price = $price;
$pdp_cart_unit_price = $price;
if ($price !== null && $hv_customer_price_adj !== null) {
    $pdp_display_price = CustomerPriceAdjustment::applyToUnitPrice($price, $hv_customer_price_adj);
    $pdp_cart_unit_price = $pdp_display_price;
}
$moq = max(1, (int) ($product['minimum_stock'] ?? 1));
$stock = (int) ($product['available_stock'] ?? 0);
$pid = (int) ($product['product_id'] ?? 0);
$productJson = json_encode([
    'product_id' => $pid,
    'name' => (string) ($product['name'] ?? ''),
    'sku' => (string) ($product['sku'] ?? ''),
    'minimum_stock' => $moq,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
$bp = base_url();
ob_start();
?>
<div class="hv-pdp">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10 lg:py-12">
    <nav class="hv-pdp-breadcrumb text-xs sm:text-sm text-gray-500 mb-8" aria-label="Breadcrumb">
      <a href="<?= e($bp) ?>/<?= e($lang) ?>" class="hover:text-red-800 transition-colors"><?= e($dict['breadcrumb']['home']) ?></a>
      <span class="hv-pdp-breadcrumb__sep" aria-hidden="true">/</span>
      <span class="text-gray-900 font-medium"><?= e($dict['breadcrumb']['products']) ?></span>
    </nav>

    <div class="hv-pdp-grid">
      <div class="hv-pdp-gallery">
        <div class="hv-pdp-main-photo">
          <img id="hv-p-main" src="<?= e($img) ?>" alt="<?= e((string) ($product['name'] ?? '')) ?>" class="hv-pdp-main-photo__img" />
        </div>
        <?php if (count($images) > 1) { ?>
        <div class="hv-pdp-thumbs" role="tablist" aria-label="Product images">
          <?php
            $ti = 0;
            foreach ($images as $im) {
                $pic = (string) ($im['pic'] ?? '');
                if ($pic === '') {
                    continue;
                }
                $isFirst = $ti === 0;
                $ti++;
                ?>
          <button type="button" class="hv-thumb<?= $isFirst ? ' hv-thumb--active' : '' ?>" data-src="<?= e($pic) ?>" aria-label="Image <?= $ti ?>">
            <img src="<?= e($pic) ?>" alt="" class="hv-pdp-thumb__img" />
          </button>
          <?php } ?>
        </div>
        <?php } ?>
      </div>

      <div class="hv-pdp-info">
        <p class="hv-pdp-eyebrow"><?= e($dict['product']['pdp_eyebrow']) ?></p>
        <h1 class="hv-pdp-title"><?= e((string) ($product['name'] ?? '')) ?></h1>

        <div class="hv-pdp-meta">
          <span class="hv-pdp-sku"><span class="hv-pdp-sku__label"><?= e($dict['product']['sku']) ?></span> <?= e((string) ($product['sku'] ?? '—')) ?></span>
          <span class="hv-pdp-stock-badge<?= $stock > 0 ? ' hv-pdp-stock-badge--in' : ' hv-pdp-stock-badge--out' ?>">
            <?= $stock > 0 ? e($dict['product']['in_stock']) : e($dict['product']['out_of_stock']) ?>
            <?php if ($hv_show_inventory_qty) { ?>
            <span class="hv-pdp-stock-badge__qty"><?= (int) $stock ?></span>
            <?php } ?>
          </span>
        </div>

        <?php if ($showPrice && $pdp_display_price !== null) { ?>
        <div class="hv-pdp-price-block">
          <span class="hv-pdp-price-label"><?= e($dict['product']['price']) ?></span>
          <p class="hv-pdp-price"><?= e(format_price($pdp_display_price, $lang)) ?></p>
        </div>
        <?php } else { ?>
        <div class="hv-pdp-pricing-gate">
          <p class="hv-pdp-pricing-gate__text"><?= e($dict['product']['pricing_gate_hint']) ?></p>
          <a href="<?= e($bp) ?>/<?= e($lang) ?>/login" class="hv-pdp-pricing-gate__btn"><?= e($dict['product']['login_for_pricing']) ?></a>
        </div>
        <?php } ?>

        <?php if ($hv_logged_in) { ?>
        <div class="hv-pdp-qty">
          <span class="hv-pdp-qty__label"><?= e($dict['cart']['quantity_label'] ?? 'Quantity') ?></span>
          <div class="hv-pdp-qty__controls">
            <button type="button" id="hv-p-min" class="hv-pdp-qty__btn" aria-label="−">−</button>
            <input type="number" id="hv-p-qty" class="hv-pdp-qty__val hv-qty-input" min="0" step="1" inputmode="numeric" autocomplete="off" value="0" aria-label="<?= e($dict['cart']['quantity_label'] ?? 'Quantity') ?>" />
            <button type="button" id="hv-p-plus" class="hv-pdp-qty__btn" aria-label="+">+</button>
          </div>
          <p class="hv-pdp-qty__moq"><?= e($dict['product']['moq_label'] ?? 'MOQ:') ?> <?= (int) $moq ?></p>
        </div>
        <?php } ?>

        <?php if (!empty($product['descriptions'])) { ?>
        <div class="hv-pdp-desc">
          <h2 class="hv-pdp-desc__title"><?= e($dict['product']['description']) ?></h2>
          <div class="hv-pdp-desc__body"><?= nl2br(e((string) $product['descriptions'])) ?></div>
        </div>
        <?php } ?>
        <?php
          $tags = parse_tags((string) ($product['tags'] ?? ''));
        if (count($tags) > 0) { ?>
        <div class="hv-pdp-tags">
          <span class="hv-pdp-tags__label"><?= e($dict['product']['tags']) ?></span>
          <div class="hv-pdp-tags__list">
            <?php foreach ($tags as $t) { ?>
            <span class="hv-pdp-tag"><?= e($t) ?></span>
            <?php } ?>
          </div>
        </div>
        <?php } ?>
      </div>
    </div>
  <?php
    $relatedSlim = $relatedProducts !== [] ? slim_products($relatedProducts) : [];
    $hvStockLbl = (string) ($dict['home']['in_stock_badge'] ?? ($lang === 'es' ? 'En Existencia:' : 'In Stock:'));
    $hvOosBadge = (string) ($dict['home']['out_of_stock_badge'] ?? '');
    $hvMoqLbl = (string) ($dict['home']['moq_label'] ?? 'MOQ:');
    $hvRelatedJs = [];
  ?>
  <?php if (count($relatedSlim) > 0) { ?>
  <section class="hv-related-section" aria-labelledby="hv-related-heading">
    <div class="hv-related-section__head">
      <div>
        <p class="hv-related-section__eyebrow"><?= e($dict['product']['related_eyebrow']) ?></p>
        <h2 id="hv-related-heading" class="hv-related-section__title"><?= e($dict['product']['related_products']) ?></h2>
      </div>
    </div>
    <div class="hv-related-viewport" role="region" aria-roledescription="carousel" aria-label="<?= e($dict['product']['related_products']) ?>">
      <button type="button" class="hv-related-edge-btn hv-related-edge-btn--prev" id="hv-related-prev" aria-label="<?= e($dict['product']['related_carousel_prev']) ?>">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 18l-6-6 6-6"/></svg>
      </button>
      <div class="hv-related-track-shell">
        <div class="hv-related-track flex flex-nowrap flex-row gap-3 overflow-x-auto overflow-y-hidden w-full" id="hv-related-track" tabindex="0">
        <?php foreach ($relatedSlim as $rp) {
            $rpid = (int) ($rp['product_id'] ?? 0);
            if ($rpid <= 0) {
                continue;
            }
            $rimg = get_product_image($rp);
            $rSku = trim((string) ($rp['sku'] ?? ''));
            if ($rSku === '') {
                $rSku = '—';
            }
            $rName = trim((string) ($rp['name'] ?? ''));
            $rMoq = max(1, (int) ($rp['minimum_stock'] ?? 1));
            $rStockN = (int) ($rp['available_stock'] ?? 0);
            $rHasStock = $rStockN > 0;
            $rOos = $rStockN <= 0;
            $rStockShow = (string) $rStockN;
            if ($rHasStock) {
                $rawSt = $rp['stock'] ?? null;
                if ($rawSt !== null && $rawSt !== '') {
                    $rawF = (float) str_replace(',', '.', (string) $rawSt);
                    if (is_finite($rawF) && $rawF > 0) {
                        $rStockShow = (abs($rawF - round($rawF)) < 1e-9) ? (string) (int) round($rawF) : (string) $rawF;
                    }
                }
            }
            $rShowPrice = $hv_logged_in && isset($rp['sale_price']) && $rp['sale_price'] !== '' && $rp['sale_price'] !== null;
            $rPrice = $rShowPrice ? (float) $rp['sale_price'] : null;
            if ($rPrice !== null && $hv_customer_price_adj !== null) {
                $rPrice = CustomerPriceAdjustment::applyToUnitPrice($rPrice, $hv_customer_price_adj);
            }
            $hvRelatedJs[] = [
                'product_id' => $rpid,
                'name' => (string) ($rp['name'] ?? ''),
                'sku' => (string) ($rp['sku'] ?? ''),
                'minimum_stock' => $rMoq,
                'image' => $rimg,
                'display_unit_price' => ($rShowPrice && $rPrice !== null) ? $rPrice : null,
            ];
            ?>
        <article class="hv-related-card flex-shrink-0">
          <div class="group bg-white rounded-2xl overflow-hidden border border-gray-100 shadow-card-hv hover:shadow-card-hv hover:border-gray-200 card-lift flex flex-col h-full hv-related-card__inner">
            <a href="<?= e($bp) ?>/<?= e($lang) ?>/products/<?= $rpid ?>" class="block relative aspect-square bg-[#FAFAF8] overflow-hidden">
              <?php if ($rHasStock) { ?>
              <span class="hv-catalog-stock-badge hv-catalog-stock-badge--in"><?= $hv_show_inventory_qty ? e($hvStockLbl) . ' ' . e($rStockShow) : e($dict['product']['in_stock'] ?? '') ?></span>
              <?php } elseif ($rOos && $hvOosBadge !== '') { ?>
              <span class="hv-catalog-stock-badge hv-catalog-stock-badge--out"><?= e($hvOosBadge) ?></span>
              <?php } ?>
              <img src="<?= e($rimg) ?>" alt="" class="w-full h-full object-contain p-4" loading="lazy" />
            </a>
            <div class="p-4 flex-1 flex flex-col min-h-0">
              <a href="<?= e($bp) ?>/<?= e($lang) ?>/products/<?= $rpid ?>" class="text-sm font-semibold text-gray-900 line-clamp-2 leading-snug tracking-tight hover:text-red-800"><?= e($rName !== '' ? $rName : $rSku) ?></a>
              <p class="text-xs text-gray-500 mt-1 font-medium tracking-wide uppercase"><?= e($rSku) ?></p>
              <?php if ($rShowPrice && $rPrice !== null) { ?>
              <p class="text-red-800 font-semibold mt-1"><?= e(format_price($rPrice, $lang)) ?></p>
              <?php } else { ?>
              <p class="text-xs text-gray-400 mt-1"><?= e($dict['product']['login_to_see_prices']) ?></p>
              <?php } ?>
              <?php if ($hv_logged_in) { ?>
              <div class="mt-auto pt-3">
                <div class="flex items-center justify-between gap-2">
                  <button type="button" class="hv-qty-min w-8 h-8 border rounded-lg text-gray-600 flex items-center justify-center touch-manipulation" data-pid="<?= $rpid ?>" aria-label="−">−</button>
                  <input type="number" min="0" step="1" inputmode="numeric" autocomplete="off" class="text-sm font-bold min-w-[2.5rem] w-14 max-w-[4.5rem] shrink-0 text-center rounded-lg border border-transparent bg-white py-1 px-1 tabular-nums text-gray-900 focus:border-red-300 focus:outline-none focus:ring-2 focus:ring-red-100 hv-qty-val hv-related-qty-val hv-qty-input" data-pid="<?= $rpid ?>" value="0" aria-label="<?= e($dict['cart']['quantity_label'] ?? 'Quantity') ?>" />
                  <button type="button" class="hv-qty-plus w-8 h-8 border rounded-lg text-gray-600 flex items-center justify-center touch-manipulation" data-pid="<?= $rpid ?>" aria-label="+">+</button>
                </div>
                <p class="text-xs text-gray-500 mt-1.5 hv-catalog-card-moq"><span class="text-gray-500"><?= e($hvMoqLbl) ?></span> <span class="font-medium text-gray-700"><?= (int) $rMoq ?></span></p>
              </div>
              <?php } ?>
            </div>
          </div>
        </article>
        <?php } ?>
        </div>
      </div>
      <button type="button" class="hv-related-edge-btn hv-related-edge-btn--next" id="hv-related-next" aria-label="<?= e($dict['product']['related_carousel_next']) ?>">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 18l6-6-6-6"/></svg>
      </button>
    </div>
  </section>
  <?php } ?>
  </div>
</div>
<?php if ($showPrice && $pdp_display_price !== null) { ?>
<script type="application/ld+json"><?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'Product',
    'name' => (string) ($product['name'] ?? ''),
    'sku' => (string) ($product['sku'] ?? ''),
    'image' => $img,
    'offers' => [
        '@type' => 'Offer',
        'priceCurrency' => 'USD',
        'price' => round($pdp_display_price, 2),
        'availability' => $stock > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
    ],
], JSON_UNESCAPED_UNICODE) ?></script>
<?php } ?>
<script>
(function(){
  var p = <?= $productJson ?>;
  var moq = <?= (int) $moq ?>;
  var lang = <?= json_encode($lang) ?>;
  var dict = <?= json_encode($dict, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  var qtyEnabled = <?= $hv_logged_in ? 'true' : 'false' ?>;
  var pdpUnitPrice = <?= $pdp_cart_unit_price !== null ? json_encode((float) $pdp_cart_unit_price) : 'null' ?>;
  var relatedProducts = <?= json_encode($hvRelatedJs ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  function normalizeMoqTypedQty(raw, moqVal) {
    moqVal = Math.max(1, moqVal || 1);
    var s = String(raw == null ? '' : raw).trim();
    if (s === '') return 0;
    var n = parseInt(s, 10);
    if (isNaN(n) || n < 1) return 0;
    if (n < moqVal) return moqVal;
    var k = Math.round(n / moqVal);
    return Math.max(moqVal, k * moqVal);
  }
  function hvWriteQtyEl(el, n) {
    if (!el) return;
    var s = String(n);
    if (el.tagName === 'INPUT') el.value = s;
    else el.textContent = s;
  }
  function imgUrl() {
    var el = document.getElementById('hv-p-main');
    return el ? el.src : '';
  }
  function syncQty() {
    if (!qtyEnabled) return;
    var q = 0;
    if (window.HV && HV.cart) {
      var it = HV.cart.load().find(function(x){ return x.productId == p.product_id; });
      if (it) q = it.qty;
    }
    var el = document.getElementById('hv-p-qty');
    hvWriteQtyEl(el, q);
  }
  function commitPdpQtyFromInput() {
    if (!window.HV || !HV.cart) return;
    var inp = document.getElementById('hv-p-qty');
    if (!inp) return;
    var desired = normalizeMoqTypedQty(inp.value, moq);
    var up = (pdpUnitPrice != null && !isNaN(parseFloat(pdpUnitPrice))) ? parseFloat(pdpUnitPrice) : null;
    if (desired < 1) {
      HV.cart.remove(p.product_id);
    } else {
      var cur = HV.cart.load().find(function (x) { return x.productId == p.product_id; });
      if (cur) HV.cart.setQty(p.product_id, desired);
      else HV.cart.add(p.product_id, p.name, p.sku, imgUrl(), desired, moq, up);
    }
    syncQty();
    window.dispatchEvent(new CustomEvent('hv-cart-change'));
  }
  document.querySelectorAll('.hv-thumb').forEach(function(b) {
    b.addEventListener('click', function() {
      var s = b.getAttribute('data-src');
      var m = document.getElementById('hv-p-main');
      if (s && m) m.src = s;
      document.querySelectorAll('.hv-thumb').forEach(function(x) { x.classList.remove('hv-thumb--active'); });
      b.classList.add('hv-thumb--active');
    });
  });
  if (qtyEnabled) {
    var plus = document.getElementById('hv-p-plus');
    var minus = document.getElementById('hv-p-min');
    if (plus) plus.addEventListener('click', function() {
      if (!window.HV) return;
      var up = (pdpUnitPrice != null && !isNaN(parseFloat(pdpUnitPrice))) ? parseFloat(pdpUnitPrice) : null;
      var nq = HV.cart.stepQty(p.product_id, p.name, p.sku, imgUrl(), moq, 1, up);
      syncQty();
      if (nq === moq) HV.toast && HV.toast(dict.toast.added_to_cart);
    });
    if (minus) minus.addEventListener('click', function() {
      if (!window.HV) return;
      var up = (pdpUnitPrice != null && !isNaN(parseFloat(pdpUnitPrice))) ? parseFloat(pdpUnitPrice) : null;
      HV.cart.stepQty(p.product_id, p.name, p.sku, imgUrl(), moq, -1, up);
      syncQty();
    });
    syncQty();
    window.addEventListener('hv-cart-change', syncQty);
    var pQtyInp = document.getElementById('hv-p-qty');
    if (pQtyInp) {
      pQtyInp.addEventListener('change', commitPdpQtyFromInput);
      pQtyInp.addEventListener('blur', commitPdpQtyFromInput);
      pQtyInp.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          pQtyInp.blur();
        }
      });
    }
  }
  function relatedByPid(pid) {
    return relatedProducts.find(function (x) { return x.product_id == pid; });
  }
  function syncRelatedQtys() {
    if (!qtyEnabled || !relatedProducts.length) return;
    relatedProducts.forEach(function (pr) {
      var q = 0;
      if (window.HV && HV.cart) {
        var it = HV.cart.load().find(function (x) { return x.productId == pr.product_id; });
        if (it) q = it.qty;
      }
      document.querySelectorAll('#hv-related-track .hv-qty-val[data-pid="' + pr.product_id + '"]').forEach(function (el) {
        hvWriteQtyEl(el, q);
      });
    });
  }
  function initRelatedCarouselCart() {
    var relTrack = document.getElementById('hv-related-track');
    if (!relTrack || !qtyEnabled || !relatedProducts.length) return;
    relTrack.addEventListener('click', function (e) {
      var btn = e.target.closest('.hv-qty-min, .hv-qty-plus');
      if (!btn || !relTrack.contains(btn)) return;
      e.preventDefault();
      e.stopPropagation();
      var pid = parseInt(btn.getAttribute('data-pid'), 10);
      var pr = relatedByPid(pid);
      if (!pr || !window.HV || !HV.cart) return;
      var delta = btn.classList.contains('hv-qty-plus') ? 1 : -1;
      var moq = Math.max(1, parseInt(pr.minimum_stock, 10) || 1);
      var up = (pr.display_unit_price != null && !isNaN(parseFloat(pr.display_unit_price))) ? parseFloat(pr.display_unit_price) : null;
      var nq = HV.cart.stepQty(pid, pr.name, pr.sku, pr.image || '', moq, delta, up);
      document.querySelectorAll('#hv-related-track .hv-qty-val[data-pid="' + pid + '"]').forEach(function (el) {
        hvWriteQtyEl(el, nq);
      });
      if (delta > 0 && nq === moq) HV.toast && HV.toast(dict.toast.added_to_cart);
      window.dispatchEvent(new CustomEvent('hv-cart-change'));
    });
    function commitRelatedQtyInput(inp) {
      if (!inp || !window.HV || !HV.cart) return;
      var pid = parseInt(inp.getAttribute('data-pid'), 10);
      var pr = relatedByPid(pid);
      if (!pr) return;
      var moqR = Math.max(1, parseInt(pr.minimum_stock, 10) || 1);
      var desired = normalizeMoqTypedQty(inp.value, moqR);
      var up = (pr.display_unit_price != null && !isNaN(parseFloat(pr.display_unit_price))) ? parseFloat(pr.display_unit_price) : null;
      if (desired < 1) HV.cart.remove(pid);
      else {
        var cur = HV.cart.load().find(function (x) { return x.productId == pid; });
        if (cur) HV.cart.setQty(pid, desired);
        else HV.cart.add(pid, pr.name, pr.sku, pr.image || '', desired, moqR, up);
      }
      var actual = 0;
      var itA = HV.cart.load().find(function (x) { return x.productId == pid; });
      if (itA) actual = itA.qty;
      document.querySelectorAll('#hv-related-track .hv-qty-val[data-pid="' + pid + '"]').forEach(function (el) {
        hvWriteQtyEl(el, actual);
      });
      window.dispatchEvent(new CustomEvent('hv-cart-change'));
    }
    relTrack.addEventListener('change', function (e) {
      var inp = e.target && e.target.closest && e.target.closest('.hv-related-qty-val');
      if (!inp || !relTrack.contains(inp)) return;
      commitRelatedQtyInput(inp);
    });
    relTrack.addEventListener('blur', function (e) {
      var inp = e.target && e.target.closest && e.target.closest('.hv-related-qty-val');
      if (!inp || !relTrack.contains(inp)) return;
      commitRelatedQtyInput(inp);
    }, true);
    relTrack.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      var inp = e.target && e.target.closest && e.target.closest('.hv-related-qty-val');
      if (!inp || !relTrack.contains(inp)) return;
      e.preventDefault();
      inp.blur();
    });
    function pullRelatedQtys() {
      if (window.HV && HV.cart && typeof HV.cart.refreshPortalFromServer === 'function' &&
          document.body && document.body.getAttribute('data-hv-portal-cart') === '1') {
        HV.cart.refreshPortalFromServer().finally(function () { syncRelatedQtys(); });
      } else {
        syncRelatedQtys();
      }
    }
    pullRelatedQtys();
    window.addEventListener('hv-cart-change', syncRelatedQtys);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initRelatedCarouselCart);
  } else {
    initRelatedCarouselCart();
  }
  var track = document.getElementById('hv-related-track');
  var prev = document.getElementById('hv-related-prev');
  var next = document.getElementById('hv-related-next');
  if (track && prev && next) {
    function scrollRelated(dir) {
      var card = track.querySelector('.hv-related-card');
      var step = card ? Math.min(card.getBoundingClientRect().width + 16, track.clientWidth * 0.9) : track.clientWidth * 0.85;
      track.scrollBy({ left: dir * step, behavior: 'smooth' });
    }
    prev.addEventListener('click', function () { scrollRelated(-1); });
    next.addEventListener('click', function () { scrollRelated(1); });
  }
})();
</script>
<?php
$content = ob_get_clean();
$pageTitle = (string) ($product['name'] ?? '') . ' — ' . ($dict['seo']['title'] ?? '');
require __DIR__ . '/layout.php';
