<?php
$showPrice = $showPrice ?? false;
$showFvWarning = $showFvWarning ?? false;
$deferHomeCatalog = $deferHomeCatalog ?? false;
$showCatalogLoader = $deferHomeCatalog && !$showFvWarning;
$hvCatalogLogoPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'logo.png';
$hvHasCatalogLogo = is_file($hvCatalogLogoPath);
$hvBrandName = $dict['nav']['brand'] ?? 'Home Value';

$hvSessionGreeting = null;
$hvSess = Auth::getSession();
if (is_array($hvSess) && !empty($hvSess['approved'])) {
    require_once dirname(__DIR__) . '/lib/UserProfile.php';
    $sessEmail = (string) ($hvSess['email'] ?? '');
    $sessRol = (int) ($hvSess['rolId'] ?? 0);
    $hvGd = UserProfile::catalogGreetingData($sessEmail, $sessRol > 0 ? $sessRol : null);
    $hvRol = $sessRol > 0 ? $sessRol : (int) ($hvGd['rolId'] ?? 0);
    $hvHi = $lang === 'es' ? 'Hola' : 'Hi';
    $hvProf = $dict['profile'] ?? [];
    $hvRoleLabel = '';
    if ($hvRol === 1) {
        $hvRoleLabel = (string) ($hvProf['role_admin'] ?? ($lang === 'es' ? 'Administrador' : 'Administrator'));
    } elseif ($hvRol === 2) {
        $hvRoleLabel = (string) ($hvProf['role_seller'] ?? ($lang === 'es' ? 'Vendedor' : 'Seller'));
    } elseif ($hvRol === 3) {
        $hvRoleLabel = (string) ($hvProf['role_customer'] ?? ($lang === 'es' ? 'Cliente' : 'Customer'));
    }
    if ($hvRol === 1) {
        $hvSessionGreeting = [
            'hi' => $hvHi,
            'rolId' => 1,
            'roleLabel' => $hvRoleLabel,
            'email' => $sessEmail,
            'sellerLine' => '',
            'customerName' => '',
            'businessName' => '',
            'customerLine' => '',
        ];
    } elseif ($hvRol === 2) {
        $fn = (string) ($hvGd['firstName'] ?? '');
        $ln = (string) ($hvGd['lastName'] ?? '');
        $hvSessionGreeting = [
            'hi' => $hvHi,
            'rolId' => 2,
            'roleLabel' => $hvRoleLabel,
            'email' => '',
            'sellerLine' => trim($fn . ' ' . $ln),
            'customerName' => '',
            'businessName' => '',
            'customerLine' => '',
        ];
    } elseif ($hvRol === 3) {
        $hvBn = trim((string) ($hvGd['businessName'] ?? ''));
        $hvNm = trim((string) ($hvGd['customerName'] ?? ''));
        $hvCustomerLine = trim((string) ($hvGd['customerLine'] ?? ''));
        $hvSessionGreeting = [
            'hi' => $hvHi,
            'rolId' => 3,
            'roleLabel' => $hvRoleLabel,
            'email' => '',
            'sellerLine' => '',
            'customerName' => $hvNm,
            'businessName' => $hvBn,
            'customerLine' => $hvCustomerLine,
        ];
    } else {
        $hvSessionGreeting = [
            'hi' => $hvHi,
            'rolId' => $hvRol,
            'roleLabel' => '',
            'email' => $sessEmail,
            'sellerLine' => '',
            'customerName' => '',
            'businessName' => '',
            'customerLine' => '',
        ];
    }
}

$hvSellerCatalog = false;
$hvSellerCustomers = [];
$hvSellerCustomersJson = '[]';
$hvSellerFvUid = 0;
if (is_array($hvSess) && !empty($hvSess['approved']) && (int) ($hvSess['rolId'] ?? 0) === 2 && Db::enabled()) {
    require_once dirname(__DIR__) . '/lib/SellerCustomers.php';
    $hvSellerFvUid = (int) ($hvSess['userId'] ?? 0);
    $hvSellerCompanyId = (int) config('FULLVENDOR_COMPANY_ID', '0');
    $hvSellerCustomers = SellerCustomers::listForSellerFvUserId(Db::pdo(), $hvSellerFvUid, $hvSellerCompanyId);
    $hvSellerCatalog = true;
    $hvSellerCustomersJson = json_encode($hvSellerCustomers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
}

$hvCustomerPriceAdjustmentJson = 'null';
if (is_array($hvSess) && !empty($hvSess['approved']) && (int) ($hvSess['rolId'] ?? 0) === 3 && Db::enabled()) {
    $hvCustFv = (int) ($hvSess['customerId'] ?? 0);
    if ($hvCustFv > 0) {
        require_once dirname(__DIR__) . '/lib/CustomerPriceAdjustment.php';
        $hvCustAdj = CustomerPriceAdjustment::forCustomerFv(Db::pdo(), $hvCustFv, (int) config('FULLVENDOR_COMPANY_ID', '0'));
        if (($hvCustAdj['kind'] ?? 'none') !== 'none') {
            $hvCustomerPriceAdjustmentJson = json_encode($hvCustAdj, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
        }
    }
}

/** Cliente mayorista (rol 3): `companies.show_inventory` controla si se muestran cantidades en el catálogo. */
$hvCatalogShowInventoryQty = true;
if (is_array($hvSess) && !empty($hvSess['approved']) && (int) ($hvSess['rolId'] ?? 0) === 3 && function_exists('fullvendor_db_configured') && fullvendor_db_configured()) {
    require_once dirname(__DIR__) . '/lib/FullVendorDb.php';
    $hvCatalogShowInventoryQty = FullVendorDb::companyShowsProductInventory();
}

ob_start();
$PAGE_SIZE = 40;
?>
<div class="max-w-7xl mx-auto min-w-0 px-4 sm:px-6 lg:px-8 py-8 hv-catalog-page" id="hv-catalog-page" data-hv-seller-catalog="<?= $hvSellerCatalog ? '1' : '0' ?>">
  <?php if ($showFvWarning) { ?>
  <div class="mb-6 p-4 bg-amber-50 border border-amber-200 rounded-xl text-sm text-amber-900">
    <?= $lang === 'es'
      ? 'Modo demostración: falta configurar FullVendor en <code class="bg-amber-100 px-1 rounded">.env</code> (FULLVENDOR_BASE_URL, FULLVENDOR_TOKEN, FULLVENDOR_COMPANY_ID). El catálogo aparecerá vacío hasta entonces.'
      : 'Demo mode: configure FullVendor in <code class="bg-amber-100 px-1 rounded">.env</code> (FULLVENDOR_BASE_URL, FULLVENDOR_TOKEN, FULLVENDOR_COMPANY_ID). The catalog stays empty until then.' ?>
  </div>
  <?php } ?>
  <div class="mb-10 pt-4">
    <p class="text-xs font-semibold text-red-700 uppercase tracking-[0.2em] mb-3"><?= $lang === 'es' ? 'Catálogo' : 'Catalog' ?></p>
    <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 tracking-tight leading-[1.1]"><?= e($dict['home']['hero_title']) ?></h1>
    <p class="text-gray-500 text-sm sm:text-base mt-3 max-w-md leading-relaxed"><?= e($dict['home']['hero_subtitle']) ?></p>
  </div>

  <?php if (!empty($hvSessionGreeting)) { ?>
  <?php
    $hvGreetRid = (int) ($hvSessionGreeting['rolId'] ?? 0);
    $hvGreetRole = trim((string) ($hvSessionGreeting['roleLabel'] ?? ''));
    $hvGreetHi = (string) ($hvSessionGreeting['hi'] ?? '');
    $hvGreetName = '';
    if ($hvGreetRid === 1) {
        $hvGreetName = trim((string) ($hvSessionGreeting['email'] ?? ''));
    } elseif ($hvGreetRid === 2) {
        $hvGreetName = trim((string) ($hvSessionGreeting['sellerLine'] ?? ''));
    } elseif ($hvGreetRid === 3) {
        $hvGreetName = trim((string) ($hvSessionGreeting['customerLine'] ?? ''));
    } else {
        $hvGreetName = trim((string) ($hvSessionGreeting['email'] ?? ''));
    }
  ?>
  <div class="mb-8 max-w-xl" id="hv-account-greeting">
    <p class="text-lg font-semibold text-gray-900 flex flex-wrap items-center gap-x-2 gap-y-1.5">
      <span class="shrink-0"><?= e($hvGreetHi) ?>,</span>
      <?php if ($hvGreetRole !== '') { ?>
      <span class="inline-flex items-center rounded-full border border-red-200 bg-red-50 text-red-800 px-2.5 py-0.5 text-xs font-semibold tracking-wide" role="status"><?= e($hvGreetRole) ?></span>
      <?php } ?>
      <span class="min-w-0 font-semibold text-gray-900"><?= $hvGreetName !== '' ? e($hvGreetName) : '—' ?></span>
    </p>
  </div>
  <?php } ?>

  <?php if ($hvSellerCatalog) { ?>
  <div id="hv-seller-workspace" class="mb-8 space-y-4">
    <div class="rounded-xl border border-gray-200 bg-white p-3 sm:p-4 shadow-sm">
      <label for="hv-seller-customer-select" class="block text-sm font-semibold text-gray-900 mb-1.5"><?= e($dict['home']['seller_select_customer']) ?></label>
      <select id="hv-seller-customer-select" class="w-full max-w-xl rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-semibold uppercase tracking-wide text-gray-900 shadow-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100">
        <option value=""><?= e($dict['home']['seller_customer_placeholder']) ?></option>
        <?php foreach ($hvSellerCustomers as $sc) {
            $scFv = (int) ($sc['customeridfullvendor'] ?? 0);
            if ($scFv <= 0) {
                continue;
            }
            $scLabel = (string) ($sc['label'] ?? '#' . $scFv);
            $scLabelUp = function_exists('mb_strtoupper') ? mb_strtoupper($scLabel, 'UTF-8') : strtoupper($scLabel);
            ?>
        <option value="<?= $scFv ?>"><?= e($scLabelUp) ?></option>
        <?php } ?>
      </select>
      <style>
        #hv-seller-workspace .select2-container {
          width: 100% !important;
          max-width: 36rem;
        }
        #hv-seller-workspace .select2-container--default .select2-selection--single {
          border: 1px solid rgb(229 231 235);
          border-radius: 0.5rem;
          min-height: 2.75rem;
          box-shadow: 0 1px 2px rgb(0 0 0 / 0.05);
        }
        #hv-seller-workspace .select2-container--default .select2-selection--single .select2-selection__rendered {
          line-height: 2.5rem;
          padding-left: 0.75rem;
          padding-right: 2rem;
          font-size: 0.875rem;
          font-weight: 600;
          text-transform: uppercase;
          letter-spacing: 0.025em;
          color: rgb(17 24 39);
        }
        #hv-seller-workspace .select2-container--default .select2-selection--single .select2-selection__arrow {
          height: 2.65rem;
        }
        #hv-seller-workspace .select2-container--default.select2-container--focus .select2-selection--single {
          border-color: rgb(248 113 113);
          box-shadow: 0 0 0 2px rgb(254 226 226);
        }
        #hv-seller-workspace .select2-dropdown {
          border-radius: 0.5rem;
          border-color: rgb(229 231 235);
        }
        #hv-seller-workspace .select2-results__option {
          font-size: 0.8125rem;
          font-weight: 600;
          text-transform: uppercase;
          letter-spacing: 0.02em;
        }
        #hv-seller-workspace .select2-container--default .select2-results > .select2-results__options {
          max-height: min(50vh, 22rem);
        }
      </style>
      <p id="hv-seller-gate-hint" class="mt-3 text-sm text-amber-800 rounded-lg bg-amber-50 border border-amber-100 px-3 py-2"><?= e($dict['home']['seller_pick_customer_hint']) ?></p>
      <p id="hv-seller-no-customers" class="hidden mt-3 text-sm text-gray-600"><?= e($dict['home']['seller_no_customers']) ?></p>
    </div>
    <div id="hv-seller-customer-detail" class="hidden rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
      <div class="h-0.5 bg-gradient-to-r from-red-600/90 to-red-500/70"></div>
      <div class="px-3 py-3 sm:px-4 sm:py-3">
        <h2 class="text-[10px] font-semibold uppercase tracking-widest text-gray-400 mb-2"><?= e($dict['home']['seller_customer_details_title']) ?></h2>
        <div id="hv-seller-customer-detail-body" class="text-xs"></div>
      </div>
    </div>
  </div>
  <?php } ?>

  <div id="hv-logged-banner" class="hidden mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 bg-emerald-50 border border-emerald-200 rounded-xl px-5 py-4">
    <div>
      <p class="text-sm font-semibold text-emerald-800"><?= $lang === 'es' ? '¡Bienvenido! Ya puedes ver precios.' : 'Welcome! You can view wholesale prices.' ?></p>
      <p class="text-xs text-emerald-600 mt-0.5"><?= $lang === 'es' ? 'Accede al catálogo completo con precios y pedidos.' : 'Access the full catalog with pricing and ordering.' ?></p>
    </div>
    <a href="<?= e(base_url()) ?>/<?= e($lang) ?>/account/catalog" class="flex-shrink-0 bg-emerald-700 text-white px-5 py-2.5 rounded-xl text-sm font-semibold hover:bg-emerald-800 transition text-center"><?= $lang === 'es' ? 'Ver Catálogo con Precios →' : 'View Catalog with Prices →' ?></a>
  </div>

  <div class="hv-catalog-layout<?= $hvSellerCatalog ? ' hidden' : '' ?>" id="hv-catalog-layout">
    <div id="hv-catalog-sidebar-backdrop" class="hv-catalog-sidebar-backdrop hidden" aria-hidden="true"></div>
    <aside id="hv-catalog-sidebar" class="hv-catalog-sidebar" aria-label="<?= e($lang === 'es' ? 'Filtros del catálogo' : 'Catalog filters') ?>">
      <div class="hv-catalog-sidebar-header">
        <span class="hv-catalog-sidebar-title"><?= e($dict['home']['filter_panel_title']) ?></span>
        <div class="flex items-center gap-1">
          <button type="button" id="hv-sidebar-collapse" class="hv-sidebar-icon-btn hv-sidebar-collapse-btn" aria-label="<?= e($dict['home']['filters_hide']) ?>">‹</button>
          <button type="button" id="hv-sidebar-close-mobile" class="hv-sidebar-icon-btn md:hidden" aria-label="<?= e($lang === 'es' ? 'Cerrar' : 'Close') ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>
      </div>
      <div class="hv-catalog-sidebar-body">
        <div class="hv-filter-section">
          <h3><?= e($dict['home']['filter_search']) ?></h3>
          <div class="relative w-full hv-sidebar-search-wrap">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="search" id="hv-search" placeholder="<?= e($dict['home']['search_placeholder']) ?>" class="hv-hero-search hv-sidebar-search-input w-full pl-10 pr-3 py-2.5 bg-white border border-gray-200 rounded-xl text-sm text-gray-900 shadow-sm" autocomplete="off" />
          </div>
        </div>
        <div class="hv-filter-section">
          <h3><?= e($dict['home']['filter_categories']) ?></h3>
          <div id="hv-sidebar-cats" class="hv-sidebar-cat-scroll"></div>
        </div>
        <div class="hv-filter-section">
          <h3><?= e($dict['home']['filter_price']) ?></h3>
          <p id="hv-filter-price-hint" class="hv-filter-price-hint<?= $showPrice ? ' hidden' : '' ?>"><?= e($dict['home']['filter_price_login_hint']) ?></p>
          <div class="hv-sidebar-price-row">
            <input type="number" id="hv-price-min" inputmode="decimal" step="0.01" min="0" placeholder="<?= e($dict['home']['price_min']) ?>" class="tabular-nums"<?= $showPrice ? '' : ' disabled' ?> />
            <input type="number" id="hv-price-max" inputmode="decimal" step="0.01" min="0" placeholder="<?= e($dict['home']['price_max']) ?>" class="tabular-nums"<?= $showPrice ? '' : ' disabled' ?> />
          </div>
          <button type="button" id="hv-price-apply" class="hv-filter-apply-btn"<?= $showPrice ? '' : ' disabled' ?>><?= e($dict['home']['filter_apply_price']) ?></button>
        </div>
        <div class="hv-filter-section">
          <h3><?= e($dict['home']['filter_stock']) ?></h3>
          <div class="hv-stock-segment" role="group" aria-label="<?= e($dict['home']['filter_stock']) ?>">
            <button type="button" id="hv-sidebar-stock-all" data-hv-stock="all"><?= e($dict['home']['all_products']) ?> (<span id="hv-sidebar-c-all">0</span>)</button>
            <button type="button" id="hv-sidebar-stock-in" data-hv-stock="in"><?= e($dict['home']['in_stock']) ?> (<span id="hv-sidebar-c-in">0</span>)</button>
            <button type="button" id="hv-sidebar-stock-out" data-hv-stock="out"><?= e($dict['home']['stock_filter_out']) ?> (<span id="hv-sidebar-c-out">0</span>)</button>
          </div>
        </div>
        <button type="button" id="hv-sidebar-reset" class="hv-filter-reset-btn"><?= e($dict['home']['filter_reset_all']) ?></button>
      </div>
    </aside>
    <button type="button" id="hv-sidebar-reopen-tab" class="hv-sidebar-reopen-tab hidden"><?= e($dict['home']['filters_show']) ?></button>
    <div class="hv-catalog-main">
  <div class="flex md:hidden mb-4">
    <button type="button" id="hv-mobile-filter-open" class="hv-mobile-filter-open" aria-label="<?= e($dict['home']['filters_show']) ?>">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
    </button>
  </div>

  <div id="hv-drawer" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/40" id="hv-drawer-back"></div>
    <div class="absolute left-0 top-0 h-full w-80 max-w-[85vw] bg-gray-950 text-white overflow-y-auto p-3" id="hv-drawer-panel">
      <div class="flex justify-between items-center mb-3 border-b border-white/10 pb-2">
        <span class="font-semibold text-sm"><?= $lang === 'es' ? 'Categorías' : 'Categories' ?></span>
        <button type="button" id="hv-drawer-close" class="text-gray-400 hover:text-white p-1" aria-label="<?= $lang === 'es' ? 'Cerrar' : 'Close' ?>">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
      <div id="hv-drawer-nav" class="hv-drawer-nav space-y-2"></div>
    </div>
  </div>

  <div id="hv-catalog-loading" class="<?= $showCatalogLoader ? '' : 'hidden ' ?>hv-catalog-load-outer" aria-busy="<?= $showCatalogLoader ? 'true' : 'false' ?>">
    <div class="hv-catalog-load-panel">
      <div class="hv-catalog-load-card">
        <div id="hv-catalog-load-main">
          <div class="hv-catalog-load-brand">
            <?php if ($hvHasCatalogLogo) { ?>
            <img src="<?= e(base_url()) ?>/assets/logo.png" alt="<?= e($hvBrandName) ?>" class="hv-catalog-load-logo" width="200" height="64" decoding="async" />
            <?php } else { ?>
            <p class="hv-catalog-load-brand-text"><?= e($hvBrandName) ?></p>
            <?php } ?>
          </div>
          <div class="hv-catalog-load-visual" aria-hidden="true">
            <div class="hv-catalog-orbit">
              <span class="hv-catalog-orbit-ring"></span>
              <span class="hv-catalog-orbit-ring hv-catalog-orbit-ring--delay"></span>
            </div>
            <div class="hv-catalog-load-pulse"></div>
          </div>
          <p class="hv-catalog-load-eyebrow"><?= $lang === 'es' ? 'Catálogo' : 'Catalog' ?></p>
          <div class="hv-catalog-load-bar-wrap">
            <div class="hv-catalog-load-bar-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="12" id="hv-catalog-load-bar-track">
              <div id="hv-catalog-load-bar-fill" class="hv-catalog-load-bar-fill" style="width:12%"></div>
            </div>
          </div>
          <p id="hv-catalog-load-status" class="hv-catalog-load-status"><?= e($dict['home']['catalog_loading_start']) ?></p>
          <div id="hv-catalog-skeleton" class="hv-catalog-mini-skel" aria-hidden="true">
            <?php for ($sk = 0; $sk < 6; $sk++) { ?>
            <div class="hv-mini-skel hv-skeleton-shine"></div>
            <?php } ?>
          </div>
        </div>
        <div id="hv-catalog-load-success" class="hv-catalog-load-success" aria-live="polite">
          <?php if ($hvHasCatalogLogo) { ?>
          <img src="<?= e(base_url()) ?>/assets/logo.png" alt="" class="hv-catalog-load-logo hv-catalog-load-logo--success" width="160" height="52" decoding="async" aria-hidden="true" />
          <?php } ?>
          <div class="hv-catalog-load-success-icon" aria-hidden="true">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
          </div>
          <p class="hv-catalog-load-success-title"><?= e($dict['home']['catalog_ready_title']) ?></p>
        </div>
      </div>
    </div>
  </div>

  <p id="hv-found" class="text-center text-sm text-gray-500 mb-4 hidden"></p>
  <div id="hv-catalog-root" class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4 sm:gap-5"></div>
  <div id="hv-load-wrap" class="hidden flex justify-center py-8">
    <button type="button" id="hv-load-more" class="px-6 py-3 border-2 border-gray-300 rounded-xl font-semibold"><?= e($dict['home']['load_more']) ?></button>
  </div>
  <p id="hv-empty" class="hidden text-center text-gray-400 py-16"><?= e($dict['home']['no_products']) ?></p>
    </div>
  </div>
</div>

<?php if ($hvSellerCatalog) { ?>
<script src="<?= e(base_url()) ?>/assets/vendor/jquery/jquery-3.7.1.min.js?v=1"></script>
<script src="<?= e(base_url()) ?>/assets/vendor/select2/select2.min.js?v=1"></script>
<?php } ?>
<script>
(function(){
  var products = <?= $productsJson ?? '[]' ?>;
  var categories = <?= $categoriesJson ?? '[]' ?>;
  var dict = <?= json_encode($dict, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  var lang = <?= json_encode($lang) ?>;
  var base = (window.HV_BASE || '').replace(/\/?$/, '');
  var showPrice = <?= $showPrice ? 'true' : 'false' ?>;
  var PAGE = <?= (int) $PAGE_SIZE ?>;
  var deferCatalog = <?= !empty($deferHomeCatalog) ? 'true' : 'false' ?>;
  var showCatalogLoader = <?= !empty($showCatalogLoader) ? 'true' : 'false' ?>;
  var fvConfigured = <?= $showFvWarning ? 'false' : 'true' ?>;
  var catalogReady = !deferCatalog || !showCatalogLoader;
  var sellerCatalogMode = <?= $hvSellerCatalog ? 'true' : 'false' ?>;
  var sellerCustomers = <?= $hvSellerCustomersJson ?? '[]' ?>;
  var sellerCatalogDbSync = <?= ($hvSellerCatalog && class_exists('Db', false) && Db::enabled()) ? 'true' : 'false' ?>;
  var customerPriceAdjustment = <?= $hvCustomerPriceAdjustmentJson ?>;
  var catalogShowInventoryQty = <?= json_encode((bool) $hvCatalogShowInventoryQty) ?>;

  if (document.body && document.body.getAttribute('data-hv-auth-approved') === '1') {
    var b = document.getElementById('hv-logged-banner');
    if (b) b.classList.remove('hidden');
  }
  if (document.body && document.body.getAttribute('data-hv-auth-approved') !== '1') {
    var g0 = document.getElementById('hv-account-greeting');
    if (g0) g0.style.display = 'none';
  }

  function imgUrl(p) {
    if (!p.images || !p.images.length) return 'https://app.fullvendor.com/uploads/noimg.png';
    var im = p.images.find(function(x){ return x.img_default == 1; }) || p.images[0];
    return im.pic || 'https://app.fullvendor.com/uploads/noimg.png';
  }
  function fmt(n) {
    return new Intl.NumberFormat(lang === 'es' ? 'es-US' : 'en-US', { style: 'currency', currency: 'USD' }).format(n);
  }
  function moqOf(p) {
    var m = parseInt(p.minimum_stock, 10);
    return isNaN(m) || m < 1 ? 1 : m;
  }
  /** Alinea una cantidad escrita con saltos de MOQ (misma lógica que +/-). */
  function normalizeMoqTypedQty(raw, moq) {
    moq = Math.max(1, moq || 1);
    var s = String(raw == null ? '' : raw).trim();
    if (s === '') return 0;
    var n = parseInt(s, 10);
    if (isNaN(n) || n < 1) return 0;
    if (n < moq) return moq;
    var k = Math.round(n / moq);
    return Math.max(moq, k * moq);
  }
  function hvWriteQtyEl(el, n) {
    if (!el) return;
    var s = String(n);
    if (el.tagName === 'INPUT') el.value = s;
    else el.textContent = s;
  }
  function getHomeCartQty(pid) {
    if (!window.HV || !HV.cart) return 0;
    var it = HV.cart.load().find(function (x) { return x.productId == pid; });
    return it ? (parseInt(it.qty, 10) || 0) : 0;
  }
  function applyHomeCatalogLineQty(pid, pr, desired) {
    if (!window.HV || !HV.cart || !pr) return;
    var moq = moqOf(pr);
    var img = imgUrl(pr);
    var unitP = null;
    if (catalogShowsPrices() && pr.sale_price != null && pr.sale_price !== '') {
      var b0 = parseFloat(pr.sale_price);
      if (!isNaN(b0)) unitP = catalogDisplayUnitPrice(b0);
    }
    if (desired < 1) {
      HV.cart.remove(pid);
      return;
    }
    var curIt = HV.cart.load().find(function (x) { return x.productId == pid; });
    if (curIt) HV.cart.setQty(pid, desired);
    else HV.cart.add(pid, pr.name, pr.sku, img, desired, moq, unitP);
  }
  function commitHomeCatalogQtyInput(inp) {
    if (!inp || !window.HV || !HV.cart) return;
    var pid = parseInt(inp.getAttribute('data-pid'), 10);
    if (!pid) return;
    var pr = products.find(function (x) { return x.product_id == pid; });
    if (!pr) return;
    var moq = moqOf(pr);
    var desired = normalizeMoqTypedQty(inp.value, moq);
    applyHomeCatalogLineQty(pid, pr, desired);
    var actual = getHomeCartQty(pid);
    document.querySelectorAll('.hv-qty-val[data-pid="' + pid + '"]').forEach(function (el) {
      hvWriteQtyEl(el, actual);
    });
    window.dispatchEvent(new CustomEvent('hv-cart-change'));
  }
  function isLoggedIn() {
    return !!(document.body && document.body.getAttribute('data-hv-auth-approved') === '1');
  }

  /**
   * En /es la home usa showPrice=false aunque /api/products devuelve sale_price con sesión.
   * Vendedor: mostrar precios de lista (sale_price) sin cliente; con cliente, ajuste de grupo en catalogDisplayUnitPrice.
   */
  function catalogShowsPrices() {
    if (!isLoggedIn()) return false;
    if (sellerCatalogMode) {
      return products.some(function (p) {
        var sp = p.sale_price;
        return sp != null && sp !== '';
      });
    }
    if (showPrice) return true;
    return products.some(function (p) {
      var sp = p.sale_price;
      return sp != null && sp !== '';
    });
  }

  function syncPriceFilterUi() {
    var hint = document.getElementById('hv-filter-price-hint');
    var pm = document.getElementById('hv-price-min');
    var px = document.getElementById('hv-price-max');
    var pa = document.getElementById('hv-price-apply');
    var on = catalogShowsPrices();
    if (hint) hint.classList.toggle('hidden', on);
    if (pm) pm.disabled = !on;
    if (px) px.disabled = !on;
    if (pa) pa.disabled = !on;
  }

  var categoryFilterSelected = new Set();
  var search = '', searchInput = '', stockFilter = 'all', visibleCount = PAGE;
  var priceMinApplied = null, priceMaxApplied = null;

  var categoryCounts = {};
  var inStockCount = 0;
  var outOfStockCount = 0;
  var countLocale = lang === 'es' ? 'es-US' : 'en-US';

  function recomputeAggregates() {
    categoryCounts = {};
    products.forEach(function(p) {
      String(p.category_id || '').split(',').map(function(s){ return s.trim(); }).forEach(function(id) {
        if (!id) return;
        categoryCounts[id] = (categoryCounts[id] || 0) + 1;
      });
    });
    inStockCount = products.filter(function(p) { return parseInt(p.available_stock, 10) > 0; }).length;
    outOfStockCount = products.filter(function(p) { return parseInt(p.available_stock, 10) <= 0; }).length;
  }
  recomputeAggregates();

  var dnav = document.getElementById('hv-drawer-nav');
  var bar = document.getElementById('hv-cat-bar');
  var sidebarEl = document.getElementById('hv-catalog-sidebar');
  var sidebarBackdrop = document.getElementById('hv-catalog-sidebar-backdrop');
  var sidebarReopenTab = document.getElementById('hv-sidebar-reopen-tab');
  var catalogLayout = document.getElementById('hv-catalog-layout');
  function setDesktopSidebarCollapsed(collapsed) {
    if (catalogLayout) catalogLayout.classList.toggle('hv-catalog-layout--sidebar-collapsed', collapsed);
    var pg = document.getElementById('hv-catalog-page');
    if (pg) pg.classList.toggle('hv-catalog-page--filters-collapsed', collapsed);
  }

  function openMobileSidebar() {
    if (!sidebarEl || !window.matchMedia('(max-width: 767.98px)').matches) return;
    sidebarEl.classList.add('hv-catalog-sidebar--open');
    if (sidebarBackdrop) {
      sidebarBackdrop.classList.remove('hidden');
      sidebarBackdrop.setAttribute('aria-hidden', 'false');
    }
  }
  function closeMobileSidebar() {
    if (sidebarEl) sidebarEl.classList.remove('hv-catalog-sidebar--open');
    if (sidebarBackdrop) {
      sidebarBackdrop.classList.add('hidden');
      sidebarBackdrop.setAttribute('aria-hidden', 'true');
    }
  }

  function categoryPlaceholder() {
    return 'https://app.fullvendor.com/uploads/noimg.png';
  }
  function categoryImageUrl(c) {
    if (!c) return '';
    var im = c.images;
    if (typeof im === 'string' && im.trim()) return im.trim();
    if (im && typeof im === 'object' && !Array.isArray(im) && im.pic) return String(im.pic);
    if (Array.isArray(im) && im[0]) {
      if (typeof im[0] === 'string') return im[0];
      if (im[0].pic) return String(im[0].pic);
    }
    return '';
  }
  function catAvatarEl(url) {
    var img = document.createElement('img');
    img.className = 'hv-cat-avatar';
    img.alt = '';
    img.loading = 'lazy';
    img.decoding = 'async';
    img.src = url && String(url).trim() ? String(url).trim() : categoryPlaceholder();
    img.addEventListener('error', function once() {
      img.removeEventListener('error', once);
      img.src = categoryPlaceholder();
    });
    return img;
  }

  function filtered() {
    var list = products;
    if (stockFilter === 'available') list = list.filter(function(p) { return parseInt(p.available_stock, 10) > 0; });
    if (stockFilter === 'out') list = list.filter(function(p) { return parseInt(p.available_stock, 10) <= 0; });
    if (catalogShowsPrices() && (priceMinApplied != null || priceMaxApplied != null)) {
      list = list.filter(function(p) {
        var pr = parseFloat(p.sale_price);
        if (isNaN(pr)) return false;
        var eff = catalogDisplayUnitPrice(pr);
        if (priceMinApplied != null && eff < priceMinApplied) return false;
        if (priceMaxApplied != null && eff > priceMaxApplied) return false;
        return true;
      });
    }
    if (categoryFilterSelected.size > 0) {
      list = list.filter(function(p) {
        var ids = String(p.category_id || '').split(',').map(function(s){ return s.trim(); }).filter(Boolean);
        return ids.some(function(cid) { return categoryFilterSelected.has(cid); });
      });
    }
    if (search.trim()) {
      var q = search.toLowerCase();
      list = list.filter(function(p) {
        return (p.name && p.name.toLowerCase().indexOf(q) !== -1) || (p.sku && p.sku.toLowerCase().indexOf(q) !== -1) ||
          (p.descriptions && p.descriptions.toLowerCase().indexOf(q) !== -1) || (p.tags && p.tags.toLowerCase().indexOf(q) !== -1);
      });
    }
    return list;
  }

  function getSelectedSellerPriceAdjustment() {
    if (!sellerCatalogMode || !window.HV || !HV.cart || typeof HV.cart.getSellerCustomerFvId !== 'function') return null;
    var fid = HV.cart.getSellerCustomerFvId();
    if (!fid) return null;
    var c = sellerCustomers.find(function (x) { return String(x.customeridfullvendor) === String(fid); });
    return (c && c.price_adjustment) ? c.price_adjustment : null;
  }

  function applyPriceAdjustmentFromGroup(n, adj) {
    if (adj == null || adj.kind === 'none') return n;
    var p = parseFloat(String(adj.percent));
    if (isNaN(p) || p <= 0) return n;
    if (adj.kind === 'decrease') return Math.max(0, n * (1 - p / 100));
    if (adj.kind === 'increase') return n * (1 + p / 100);
    return n;
  }

  /** Precio unitario en catálogo: vendedor (cliente elegido) o cliente mayorista (rol 3) según grupo. */
  function catalogDisplayUnitPrice(original) {
    var n = parseFloat(String(original));
    if (isNaN(n)) return n;
    if (sellerCatalogMode) {
      return applyPriceAdjustmentFromGroup(n, getSelectedSellerPriceAdjustment());
    }
    if (customerPriceAdjustment && customerPriceAdjustment.kind !== 'none') {
      return applyPriceAdjustmentFromGroup(n, customerPriceAdjustment);
    }
    return n;
  }

  function sellerCatalogUnitPrice(original) {
    return catalogDisplayUnitPrice(original);
  }

  function escAttr(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function sellerNz(v) {
    if (v === null || v === undefined) return '';
    var s = String(v).trim();
    return s;
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
        return (
          '<tr>' +
          '<td style="' + bt + 'vertical-align:top;box-sizing:border-box;padding:0.5rem 1rem 0.5rem 0.75rem;width:11.5rem;max-width:40%;font-size:10px;font-weight:600;letter-spacing:0.07em;text-transform:uppercase;color:#9ca3af;line-height:1.4">' +
          escAttr(r.lab) +
          '</td>' +
          '<td style="' + bt + 'vertical-align:top;box-sizing:border-box;padding:0.5rem 0.75rem;font-size:12px;font-weight:600;color:#111827;text-transform:uppercase;letter-spacing:0.02em;line-height:1.45;word-break:break-word">' +
          valHtml +
          '</td>' +
          '</tr>'
        );
      }).join('');
      inner =
        '<div class="rounded-lg border border-gray-100 bg-[#FAFAF8]/80 overflow-hidden">' +
        '<table style="width:100%;border-collapse:collapse;table-layout:fixed">' +
        '<tbody>' + rowHtml + '</tbody></table></div>';
    }
    body.innerHTML = inner;
  }

  function updateSellerGate() {
    if (!sellerCatalogMode) return;
    var layout = document.getElementById('hv-catalog-layout');
    var hint = document.getElementById('hv-seller-gate-hint');
    var nc = document.getElementById('hv-seller-no-customers');
    var hasOpts = Array.isArray(sellerCustomers) && sellerCustomers.length > 0;
    if (nc) nc.classList.toggle('hidden', hasOpts);
    if (!window.HV || !HV.cart) return;
    var ready = !!HV.cart.getSellerCustomerFvId();
    if (!hasOpts) {
      if (layout) layout.classList.add('hidden');
      var det0 = document.getElementById('hv-seller-customer-detail');
      if (det0) det0.classList.add('hidden');
      return;
    }
    if (layout) layout.classList.remove('hidden');
    if (hint) hint.classList.toggle('hidden', ready);
    renderSellerCustomerDetails();
    syncPriceFilterUi();
    render();
  }

  function syncSellerSelectFromStorage() {
    var sel = document.getElementById('hv-seller-customer-select');
    if (!sel || !window.HV || !HV.cart) return;
    var id = HV.cart.getSellerCustomerFvId();
    if (id && Array.isArray(sellerCustomers) && !sellerCustomers.some(function (x) { return String(x.customeridfullvendor) === String(id); })) {
      HV.cart.setSellerCustomerFvId('');
      id = '';
    }
    var next = id || '';
    sel.value = next;
    if (typeof jQuery !== 'undefined' && jQuery(sel).data('select2')) {
      jQuery(sel).val(next || null).trigger('change');
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
      postSellerCatalogStatePatch({
        selectedCustomerFv: fid ? parseInt(fid, 10) : null,
        carts: HV.cart.exportSellerCartsSnapshot()
      });
    }, 700);
  }

  function pullSellerCatalogStateFromServer() {
    if (!sellerCatalogDbSync || !sellerCatalogMode) return;
    sellerCatalogSyncingFromServer = true;
    fetch(base + '/api/seller-catalog-state', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('http')); })
      .then(function (data) {
        if (!data || !window.HV || !HV.cart) return;
        if (data.rowExists && HV.cart.applySellerCatalogStateFromServer) {
          HV.cart.applySellerCatalogStateFromServer({
            selectedCustomerFv: data.selectedCustomerFv,
            carts: data.carts || {}
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
        updateSellerGate();
      })
      .catch(function () {})
      .finally(function () {
        sellerCatalogSyncingFromServer = false;
      });
  }

  function initHomeSellerCatalogControls() {
    if (!sellerCatalogMode) return;
    var selCust = document.getElementById('hv-seller-customer-select');
    if (selCust) {
      syncSellerSelectFromStorage();
      if (typeof jQuery !== 'undefined' && jQuery.fn.select2 && !jQuery(selCust).data('select2')) {
        var ph = (dict.home && dict.home.seller_customer_placeholder) ? String(dict.home.seller_customer_placeholder) : '';
        jQuery(selCust).select2({
          theme: 'default',
          width: '100%',
          placeholder: ph,
          allowClear: true,
          dropdownParent: jQuery(document.body),
          minimumResultsForSearch: 0
        });
        jQuery(selCust).on('change', function () {
          if (!window.HV || !HV.cart) return;
          HV.cart.setSellerCustomerFvId(jQuery(this).val() || '');
          updateSellerGate();
        });
      } else if (typeof jQuery === 'undefined' || !jQuery.fn.select2) {
        selCust.addEventListener('change', function () {
          if (!window.HV || !HV.cart) return;
          HV.cart.setSellerCustomerFvId(selCust.value || '');
          updateSellerGate();
        });
      }
    }
    window.addEventListener('hv-seller-customer-change', updateSellerGate);
    window.addEventListener('hv-cart-change', function () {
      if (sellerCatalogMode) render();
      if (sellerCatalogMode && sellerCatalogDbSync && !sellerCatalogSyncingFromServer) {
        scheduleSellerCatalogStatePost();
      }
    });
    updateSellerGate();
    pullSellerCatalogStateFromServer();
  }

  if (sellerCatalogMode) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initHomeSellerCatalogControls);
    } else {
      initHomeSellerCatalogControls();
    }
  }

  function render() {
    if (!catalogReady) return;
    var list = filtered();
    var root = document.getElementById('hv-catalog-root');
    var empty = document.getElementById('hv-empty');
    var found = document.getElementById('hv-found');
    var loadWrap = document.getElementById('hv-load-wrap');
    root.innerHTML = '';
    if (!list.length) { empty.classList.remove('hidden'); found.classList.add('hidden'); loadWrap.classList.add('hidden'); return; }
    empty.classList.add('hidden');
    found.classList.remove('hidden');
    found.textContent = dict.home.products_found.replace('{count}', String(list.length));
    var vis = list.slice(0, visibleCount);
    vis.forEach(function(p, i) {
      var card = document.createElement('div');
      card.className = 'group bg-white rounded-2xl overflow-hidden border border-gray-100 shadow-card-hv hover:shadow-card-hv hover:border-gray-200 card-lift flex flex-col';
      var moq = moqOf(p);
      var q = 0;
      if (window.HV && HV.cart) {
        var it = HV.cart.load().find(function(x){ return x.productId == p.product_id; });
        if (it) q = it.qty;
      }
      var priceHtml = '';
      if (catalogShowsPrices() && p.sale_price != null && p.sale_price !== '') {
        var basePr = parseFloat(p.sale_price);
        var dispPr = catalogDisplayUnitPrice(basePr);
        priceHtml = '<p class="text-red-800 font-semibold mt-1">' + fmt(dispPr) + '</p>';
      } else priceHtml = '<p class="text-xs text-gray-400 mt-1">' + (dict.product.login_to_see_prices || '') + '</p>';
      var stockN = parseInt(p.available_stock, 10);
      var hasStock = !isNaN(stockN) && stockN > 0;
      var oos = !isNaN(stockN) && stockN <= 0;
      var badge = '';
      if (hasStock) {
        var stockLbl = (dict.home && dict.home.in_stock_badge) ? dict.home.in_stock_badge : (lang === 'es' ? 'En Existencia:' : 'In Stock:');
        if (catalogShowInventoryQty) {
          var rawSt = parseFloat(String(p.stock != null ? p.stock : '').replace(',', '.'));
          var stockShow = (!isNaN(rawSt) && rawSt > 0)
            ? (Math.abs(rawSt - Math.round(rawSt)) < 1e-9 ? String(Math.round(rawSt)) : String(rawSt))
            : String(stockN);
          badge = '<span class="hv-catalog-stock-badge hv-catalog-stock-badge--in">' + stockLbl + ' ' + stockShow + '</span>';
        } else {
          var inOnly = (dict.product && dict.product.in_stock) ? dict.product.in_stock : (lang === 'es' ? 'En existencia' : 'In stock');
          badge = '<span class="hv-catalog-stock-badge hv-catalog-stock-badge--in">' + inOnly + '</span>';
        }
      } else if (oos) {
        badge = '<span class="hv-catalog-stock-badge hv-catalog-stock-badge--out">' + (dict.home.out_of_stock_badge||'') + '</span>';
      }
      var qtyRow = '';
      if (isLoggedIn()) {
        var moqLabel = (dict.home && dict.home.moq_label) ? dict.home.moq_label : 'MOQ:';
        qtyRow =
          '<div class="mt-auto pt-3">' +
          '<div class="flex items-center justify-between gap-2">' +
          '<button type="button" class="hv-qty-min w-8 h-8 border rounded-lg text-gray-600" data-pid="'+p.product_id+'">−</button>' +
          '<input type="number" min="0" step="1" inputmode="numeric" autocomplete="off" aria-label="' + escAttr((dict.cart && dict.cart.quantity_label) ? dict.cart.quantity_label : (lang === 'es' ? 'Cantidad' : 'Quantity')) + '" class="hv-qty-val hv-qty-input min-w-[2.5rem] w-14 max-w-[4.5rem] shrink-0 rounded-lg border border-transparent bg-white py-1 px-1 text-center text-sm font-bold tabular-nums text-gray-900 focus:border-red-300 focus:outline-none focus:ring-2 focus:ring-red-100" data-pid="'+p.product_id+'" value="'+q+'" />' +
          '<button type="button" class="hv-qty-plus w-8 h-8 border rounded-lg text-gray-600" data-pid="'+p.product_id+'">+</button>' +
          '</div>' +
          '<p class="text-xs text-gray-500 mt-1.5 hv-catalog-card-moq"><span class="text-gray-500">'+moqLabel+'</span> <span class="font-medium text-gray-700">'+moq+'</span></p>' +
          '</div>';
      }
      card.innerHTML =
        '<a href="'+base+'/'+lang+'/products/'+p.product_id+'" class="block relative aspect-square bg-[#FAFAF8] overflow-hidden">' + badge +
        '<img src="'+imgUrl(p)+'" alt="" class="w-full h-full object-contain p-4" loading="'+(i<8?'eager':'lazy')+'"/></a>' +
        '<div class="p-4 flex-1 flex flex-col">' +
        '<a href="'+base+'/'+lang+'/products/'+p.product_id+'" class="text-sm font-semibold text-gray-900 line-clamp-2 leading-snug tracking-tight">'+ (p.name||'') +'</a>' +
        '<p class="text-xs text-gray-500 mt-1 font-medium tracking-wide uppercase">'+ (p.sku||'') +'</p>' + priceHtml +
        qtyRow +
        '</div>';
      root.appendChild(card);
      card.querySelectorAll('.hv-qty-min, .hv-qty-plus').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
          e.preventDefault(); e.stopPropagation();
          var pid = parseInt(btn.getAttribute('data-pid'), 10);
          var pr = products.find(function(x){ return x.product_id == pid; });
          if (!pr || !window.HV) return;
          var moq = moqOf(pr), img = imgUrl(pr);
          var delta = btn.classList.contains('hv-qty-plus') ? 1 : -1;
          var unitP = null;
          if (catalogShowsPrices() && pr.sale_price != null && pr.sale_price !== '') {
            var b0 = parseFloat(pr.sale_price);
            if (!isNaN(b0)) unitP = catalogDisplayUnitPrice(b0);
          }
          var nq = HV.cart.stepQty(pid, pr.name, pr.sku, img, moq, delta, unitP);
          document.querySelectorAll('.hv-qty-val[data-pid="'+pid+'"]').forEach(function (el) { hvWriteQtyEl(el, nq); });
          if (delta > 0 && nq === moq) HV.toast && HV.toast(dict.toast.added_to_cart);
          window.dispatchEvent(new CustomEvent('hv-cart-change'));
        });
      });
      card.querySelectorAll('.hv-qty-input').forEach(function (inp) {
        inp.addEventListener('change', function () { commitHomeCatalogQtyInput(inp); });
        inp.addEventListener('blur', function () { commitHomeCatalogQtyInput(inp); });
        inp.addEventListener('keydown', function (e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            inp.blur();
          }
        });
      });
    });
    if (visibleCount < list.length) loadWrap.classList.remove('hidden'); else loadWrap.classList.add('hidden');
  }

  var debounce;
  document.getElementById('hv-search').addEventListener('input', function() {
    clearTimeout(debounce);
    var v = this.value;
    debounce = setTimeout(function(){ search = v; visibleCount = PAGE; render(); }, 300);
  });
  document.getElementById('hv-load-more').addEventListener('click', function() { visibleCount += PAGE; render(); });

  function setStockFilter(mode) {
    stockFilter = mode;
    visibleCount = PAGE;
    refreshFilterPills();
    render();
  }
  function refreshCategoryBarPills() {
    if (!bar) return;
    bar.querySelectorAll('.hv-cat-chip').forEach(function(el) {
      var isAll = el.getAttribute('data-cat-all') === '1';
      var id = el.getAttribute('data-cat-id');
      var active = isAll ? (categoryFilterSelected.size === 0) : (id && categoryFilterSelected.has(String(id)));
      el.classList.toggle('is-active', active);
    });
  }

  function rebuildDrawerNav() {
    if (!dnav) return;
    dnav.innerHTML = '';
    dnav.appendChild(catDrawerBtn(null, dict.home.all_products, products.length, null));
    categories.forEach(function(c) {
      var id = String(c.category_id);
      dnav.appendChild(catDrawerBtn(id, c.category_name, categoryCounts[id] || 0, categoryImageUrl(c)));
    });
  }

  function refreshFilterPills() {
    rebuildDrawerNav();
    refreshCategoryBarPills();
    refreshSidebarStockSeg();
    refreshSidebarCatRows();
  }

  function applyCategoryFilterChange() {
    visibleCount = PAGE;
    var dr = document.getElementById('hv-drawer');
    if (dr) dr.classList.add('hidden');
    refreshFilterPills();
    render();
  }
  function clearCategoryFilter() {
    categoryFilterSelected.clear();
    applyCategoryFilterChange();
  }
  function toggleCategoryFilter(rawId) {
    var sid = String(rawId);
    if (categoryFilterSelected.has(sid)) {
      categoryFilterSelected.delete(sid);
    } else {
      categoryFilterSelected.add(sid);
    }
    applyCategoryFilterChange();
  }
  document.getElementById('hv-drawer-back').addEventListener('click', function() { document.getElementById('hv-drawer').classList.add('hidden'); });
  document.getElementById('hv-drawer-close').addEventListener('click', function() { document.getElementById('hv-drawer').classList.add('hidden'); });

  function resetAllCatalogFilters() {
    categoryFilterSelected.clear();
    stockFilter = 'all';
    priceMinApplied = null;
    priceMaxApplied = null;
    var pm = document.getElementById('hv-price-min');
    var px = document.getElementById('hv-price-max');
    var si = document.getElementById('hv-search');
    if (pm) pm.value = '';
    if (px) px.value = '';
    if (si) si.value = '';
    search = '';
    visibleCount = PAGE;
    var dr = document.getElementById('hv-drawer');
    if (dr) dr.classList.add('hidden');
    closeMobileSidebar();
    refreshFilterPills();
    render();
  }
  if (sidebarBackdrop) sidebarBackdrop.addEventListener('click', closeMobileSidebar);
  var mfo = document.getElementById('hv-mobile-filter-open');
  if (mfo) mfo.addEventListener('click', openMobileSidebar);
  var scm = document.getElementById('hv-sidebar-close-mobile');
  if (scm) scm.addEventListener('click', closeMobileSidebar);
  var scc = document.getElementById('hv-sidebar-collapse');
  if (scc) scc.addEventListener('click', function() {
    if (window.matchMedia('(min-width: 768px)').matches && sidebarEl) {
      sidebarEl.classList.add('hv-catalog-sidebar--collapsed');
      setDesktopSidebarCollapsed(true);
      if (sidebarReopenTab) sidebarReopenTab.classList.remove('hidden');
    }
  });
  if (sidebarReopenTab) sidebarReopenTab.addEventListener('click', function() {
    if (sidebarEl) sidebarEl.classList.remove('hv-catalog-sidebar--collapsed');
    setDesktopSidebarCollapsed(false);
    sidebarReopenTab.classList.add('hidden');
  });
  var sreset = document.getElementById('hv-sidebar-reset');
  if (sreset) sreset.addEventListener('click', resetAllCatalogFilters);
  var pApply = document.getElementById('hv-price-apply');
  if (pApply) pApply.addEventListener('click', applyPriceFilterFromInputs);
  var ssAll = document.getElementById('hv-sidebar-stock-all');
  var ssIn = document.getElementById('hv-sidebar-stock-in');
  var ssOut = document.getElementById('hv-sidebar-stock-out');
  if (ssAll) ssAll.addEventListener('click', function() { setStockFilter('all'); });
  if (ssIn) ssIn.addEventListener('click', function() { setStockFilter('available'); });
  if (ssOut) ssOut.addEventListener('click', function() { setStockFilter('out'); });

  window.addEventListener('resize', function() {
    if (window.matchMedia('(max-width: 767.98px)').matches && sidebarEl) {
      sidebarEl.classList.remove('hv-catalog-sidebar--collapsed');
      setDesktopSidebarCollapsed(false);
      if (sidebarReopenTab) sidebarReopenTab.classList.add('hidden');
    }
  });

  function sidebarCatRowAll() {
    var row = document.createElement('button');
    row.type = 'button';
    row.className = 'hv-sidebar-cat-row' + (categoryFilterSelected.size === 0 ? ' hv-sidebar-cat-row--active' : '');
    row.setAttribute('data-sidebar-cat', 'all');
    var ph = document.createElement('span');
    ph.className = 'hv-cat-avatar hv-cat-avatar-placeholder hv-sidebar-cat-thumb';
    ph.setAttribute('aria-hidden', 'true');
    row.appendChild(ph);
    var chk = document.createElement('span');
    chk.className = 'hv-sidebar-cat-check';
    chk.setAttribute('aria-hidden', 'true');
    row.appendChild(chk);
    var lab = document.createElement('span');
    lab.className = 'hv-sidebar-cat-label truncate';
    lab.textContent = dict.home.all_products + (products.length != null ? ' (' + products.length + ')' : '');
    row.appendChild(lab);
    row.addEventListener('click', function() { clearCategoryFilter(); });
    return row;
  }
  function sidebarCatRow(cat) {
    var id = String(cat.category_id);
    var row = document.createElement('button');
    row.type = 'button';
    var sel = categoryFilterSelected.has(id);
    row.className = 'hv-sidebar-cat-row' + (sel ? ' hv-sidebar-cat-row--active' : '');
    row.setAttribute('data-cat-id', id);
    var thumb = catAvatarEl(categoryImageUrl(cat));
    thumb.classList.add('hv-sidebar-cat-thumb');
    row.appendChild(thumb);
    var chk = document.createElement('span');
    chk.className = 'hv-sidebar-cat-check';
    chk.setAttribute('aria-hidden', 'true');
    chk.textContent = sel ? '✓' : '';
    row.appendChild(chk);
    var lab = document.createElement('span');
    lab.className = 'hv-sidebar-cat-label truncate';
    lab.textContent = cat.category_name + ' (' + (categoryCounts[id] || 0) + ')';
    row.appendChild(lab);
    row.addEventListener('click', function() { toggleCategoryFilter(id); });
    return row;
  }
  function rebuildSidebarCats() {
    var wrap = document.getElementById('hv-sidebar-cats');
    if (!wrap) return;
    wrap.innerHTML = '';
    wrap.appendChild(sidebarCatRowAll());
    categories.forEach(function(c) {
      wrap.appendChild(sidebarCatRow(c));
    });
  }
  function refreshSidebarCatRows() {
    var wrap = document.getElementById('hv-sidebar-cats');
    if (!wrap) return;
    wrap.querySelectorAll('.hv-sidebar-cat-row').forEach(function(row) {
      var isAll = row.getAttribute('data-sidebar-cat') === 'all';
      var id = row.getAttribute('data-cat-id');
      var active = isAll ? (categoryFilterSelected.size === 0) : (id && categoryFilterSelected.has(String(id)));
      row.classList.toggle('hv-sidebar-cat-row--active', active);
      var chk = row.querySelector('.hv-sidebar-cat-check');
      if (chk) {
        if (isAll) chk.textContent = '';
        else chk.textContent = (id && categoryFilterSelected.has(String(id))) ? '✓' : '';
      }
    });
  }
  function refreshSidebarStockSeg() {
    var sa = document.getElementById('hv-sidebar-stock-all');
    var si = document.getElementById('hv-sidebar-stock-in');
    var so = document.getElementById('hv-sidebar-stock-out');
    [sa, si, so].forEach(function(el) {
      if (!el) return;
      el.classList.remove('hv-stock-seg--on', 'hv-stock-seg--in', 'hv-stock-seg--out');
    });
    if (stockFilter === 'all' && sa) sa.classList.add('hv-stock-seg--on');
    if (stockFilter === 'available' && si) si.classList.add('hv-stock-seg--on', 'hv-stock-seg--in');
    if (stockFilter === 'out' && so) so.classList.add('hv-stock-seg--on', 'hv-stock-seg--out');
  }
  function applyPriceFilterFromInputs() {
    if (!catalogShowsPrices()) return;
    var elMin = document.getElementById('hv-price-min');
    var elMax = document.getElementById('hv-price-max');
    var rawMin = elMin ? elMin.value.trim() : '';
    var rawMax = elMax ? elMax.value.trim() : '';
    var mn = rawMin === '' ? NaN : parseFloat(rawMin);
    var mx = rawMax === '' ? NaN : parseFloat(rawMax);
    priceMinApplied = !isNaN(mn) ? mn : null;
    priceMaxApplied = !isNaN(mx) ? mx : null;
    if (priceMinApplied != null && priceMaxApplied != null && priceMinApplied > priceMaxApplied) {
      var t = priceMinApplied;
      priceMinApplied = priceMaxApplied;
      priceMaxApplied = t;
      if (elMin && elMax) {
        elMin.value = priceMinApplied != null ? String(priceMinApplied) : '';
        elMax.value = priceMaxApplied != null ? String(priceMaxApplied) : '';
      }
    }
    visibleCount = PAGE;
    render();
  }

  function catDrawerBtn(id, label, count, imageUrl) {
    var b = document.createElement('button');
    b.type = 'button';
    var active = (id === null && categoryFilterSelected.size === 0) || (id !== null && categoryFilterSelected.has(String(id)));
    b.className = 'hv-drawer-pill w-full text-left text-sm transition flex items-center gap-2 ' + (active ? 'hv-drawer-pill-active' : 'hv-drawer-pill-idle');
    var inner = document.createElement('span');
    inner.className = 'flex items-center gap-2 min-w-0 w-full';
    if (id === null) {
      var ph = document.createElement('span');
      ph.className = 'hv-cat-avatar hv-cat-avatar-placeholder flex-shrink-0';
      ph.setAttribute('aria-hidden', 'true');
      inner.appendChild(ph);
    } else {
      inner.appendChild(catAvatarEl(imageUrl));
    }
    var txt = document.createElement('span');
    txt.className = 'truncate';
    txt.textContent = label + (count != null ? ' ('+count+')' : '');
    inner.appendChild(txt);
    b.appendChild(inner);
    b.addEventListener('click', function() {
      if (id === null) clearCategoryFilter();
      else toggleCategoryFilter(String(id));
    });
    return b;
  }

  function rebuildCategoryNavAndBar() {
    rebuildDrawerNav();
    if (bar) {
      bar.innerHTML = '';
      var allChip = document.createElement('button');
      allChip.type = 'button';
      allChip.className = 'hv-cat-chip';
      allChip.setAttribute('data-cat-all', '1');
      allChip.textContent = dict.home.all_categories;
      allChip.addEventListener('click', function() { clearCategoryFilter(); });
      bar.appendChild(allChip);
      categories.forEach(function(c) {
        var id = String(c.category_id);
        var bb = document.createElement('button');
        bb.type = 'button';
        bb.className = 'hv-cat-chip';
        bb.setAttribute('data-cat-id', id);
        var inner = document.createElement('span');
        inner.className = 'hv-cat-chip-inner';
        inner.appendChild(catAvatarEl(categoryImageUrl(c)));
        var lab = document.createElement('span');
        lab.className = 'hv-cat-chip-label';
        lab.textContent = c.category_name + ' (' + (categoryCounts[id] || 0) + ')';
        inner.appendChild(lab);
        bb.appendChild(inner);
        bb.addEventListener('click', function() { toggleCategoryFilter(id); });
        bar.appendChild(bb);
      });
    }
    var sAll = document.getElementById('hv-sidebar-c-all');
    var sIn = document.getElementById('hv-sidebar-c-in');
    var sOut = document.getElementById('hv-sidebar-c-out');
    if (sAll) sAll.textContent = products.length.toLocaleString(countLocale);
    if (sIn) sIn.textContent = inStockCount.toLocaleString(countLocale);
    if (sOut) sOut.textContent = outOfStockCount.toLocaleString(countLocale);
    rebuildSidebarCats();
  }

  var pendingProductsArr = [];
  var pendingCategoriesArr = [];

  function setLoadProgress(pct, msg) {
    var fill = document.getElementById('hv-catalog-load-bar-fill');
    var st = document.getElementById('hv-catalog-load-status');
    var tr = document.getElementById('hv-catalog-load-bar-track');
    if (fill && !fill.classList.contains('hv-catalog-load-bar--done') && !fill.classList.contains('hv-catalog-load-bar--error')) {
      var w = Math.min(100, Math.max(6, pct));
      fill.style.width = w + '%';
      if (tr) tr.setAttribute('aria-valuenow', String(Math.round(w)));
    }
    if (st && msg) st.textContent = msg;
  }

  function finishCatalogLoad(showReadyToast) {
    var loadEl = document.getElementById('hv-catalog-loading');
    var main = document.getElementById('hv-catalog-load-main');
    var ok = document.getElementById('hv-catalog-load-success');
    var st = document.getElementById('hv-catalog-load-status');
    var mini = document.getElementById('hv-catalog-skeleton');
    var card = document.querySelector('.hv-catalog-load-card');
    var fill = document.getElementById('hv-catalog-load-bar-fill');
    if (main) main.style.display = '';
    if (mini) mini.style.display = '';
    if (card) card.classList.remove('hv-catalog-load-card--error');
    if (fill) {
      fill.classList.remove('hv-catalog-load-bar--done', 'hv-catalog-load-bar--error');
      fill.style.width = '12%';
    }
    if (ok) ok.classList.remove('hv-catalog-load-success--on');
    if (st) st.classList.remove('hidden');
    if (loadEl) {
      loadEl.classList.remove('hv-catalog-loading--exit');
      loadEl.classList.add('hidden');
      loadEl.setAttribute('aria-busy', 'false');
    }
    document.documentElement.classList.remove('hv-catalog-load-lock');
    document.body.classList.remove('hv-catalog-load-lock');
    catalogReady = true;
    recomputeAggregates();
    rebuildCategoryNavAndBar();
    refreshFilterPills();
    syncPriceFilterUi();
    render();
    if (showReadyToast && window.HV && HV.toast && dict.home.catalog_ready_toast) {
      HV.toast(dict.home.catalog_ready_toast);
    }
  }

  function failCatalogLoad() {
    var loadEl = document.getElementById('hv-catalog-loading');
    var fill = document.getElementById('hv-catalog-load-bar-fill');
    var mini = document.getElementById('hv-catalog-skeleton');
    var card = document.querySelector('.hv-catalog-load-card');
    if (fill) {
      fill.classList.add('hv-catalog-load-bar--error');
      fill.style.width = '100%';
    }
    if (mini) mini.style.display = 'none';
    if (card) card.classList.add('hv-catalog-load-card--error');
    setLoadProgress(100, dict.home.catalog_load_error || 'Error');
    if (loadEl) loadEl.setAttribute('aria-busy', 'false');
    catalogReady = true;
    products = [];
    categories = [];
    recomputeAggregates();
    rebuildCategoryNavAndBar();
    refreshFilterPills();
    syncPriceFilterUi();
    render();
  }

  function runCatalogSuccessAnimation() {
    var fill = document.getElementById('hv-catalog-load-bar-fill');
    var main = document.getElementById('hv-catalog-load-main');
    var ok = document.getElementById('hv-catalog-load-success');
    var st = document.getElementById('hv-catalog-load-status');
    if (fill) {
      fill.classList.add('hv-catalog-load-bar--done');
      fill.style.width = '100%';
    }
    if (st) st.textContent = dict.home.catalog_loading_almost;
    setTimeout(function() {
      if (main) main.style.display = 'none';
      if (st) st.classList.add('hidden');
      if (ok) ok.classList.add('hv-catalog-load-success--on');
      setTimeout(function() {
        var loadEl = document.getElementById('hv-catalog-loading');
        if (loadEl) loadEl.classList.add('hv-catalog-loading--exit');
        setTimeout(function() {
          products = pendingProductsArr;
          categories = pendingCategoriesArr;
          if (st) st.classList.remove('hidden');
          finishCatalogLoad(true);
        }, 470);
      }, 1050);
    }, 380);
  }

  if (deferCatalog) {
    if (!showCatalogLoader) {
      finishCatalogLoad(false);
    } else {
      setLoadProgress(14, dict.home.catalog_loading_start);
      var settled = { p: false, c: false };
      var fetchErr = false;

      function bumpCatalogFetch() {
        if (fetchErr) {
          if (settled.p && settled.c) failCatalogLoad();
          return;
        }
        if (!settled.p || !settled.c) {
          if (settled.p && !settled.c) setLoadProgress(52, dict.home.catalog_loading_categories_pending);
          else if (settled.c && !settled.p) setLoadProgress(52, dict.home.catalog_loading_products_pending);
          return;
        }
        runCatalogSuccessAnimation();
      }

      fetch(base + '/api/products?lang=' + encodeURIComponent(lang), { credentials: 'same-origin', hvNoLoader: true })
        .then(function(r) { return r.ok ? r.json() : Promise.reject(); })
        .then(function(pj) {
          pendingProductsArr = Array.isArray(pj.products) ? pj.products : [];
          settled.p = true;
          bumpCatalogFetch();
        })
        .catch(function() {
          fetchErr = true;
          pendingProductsArr = [];
          settled.p = true;
          bumpCatalogFetch();
        });

      fetch(base + '/api/categorylist?lang=' + encodeURIComponent(lang), { credentials: 'same-origin', hvNoLoader: true })
        .then(function(r) { return r.ok ? r.json() : Promise.reject(); })
        .then(function(cj) {
          pendingCategoriesArr = (cj && Array.isArray(cj.list)) ? cj.list : [];
          settled.c = true;
          bumpCatalogFetch();
        })
        .catch(function() {
          fetchErr = true;
          pendingCategoriesArr = [];
          settled.c = true;
          bumpCatalogFetch();
        });
    }
  } else {
    rebuildCategoryNavAndBar();
    refreshFilterPills();
    syncPriceFilterUi();
    render();
  }

  var catPrev = document.getElementById('hv-cat-prev');
  var catNext = document.getElementById('hv-cat-next');
  function updateCatNudges() {
    if (!bar || !catPrev || !catNext) return;
    var maxScroll = Math.max(0, bar.scrollWidth - bar.clientWidth);
    var sl = bar.scrollLeft;
    catPrev.disabled = sl <= 2;
    catNext.disabled = sl >= maxScroll - 2;
  }
  if (bar && catPrev && catNext) {
    catPrev.addEventListener('click', function () {
      bar.scrollBy({ left: -Math.min(320, bar.clientWidth * 0.85), behavior: 'smooth' });
    });
    catNext.addEventListener('click', function () {
      bar.scrollBy({ left: Math.min(320, bar.clientWidth * 0.85), behavior: 'smooth' });
    });
    bar.addEventListener('scroll', updateCatNudges);
    window.addEventListener('resize', updateCatNudges);
    updateCatNudges();
    setTimeout(updateCatNudges, 100);
    bar.addEventListener('wheel', function (e) {
      if (Math.abs(e.deltaY) < Math.abs(e.deltaX)) return;
      if (bar.scrollWidth <= bar.clientWidth + 2) return;
      e.preventDefault();
      bar.scrollLeft += e.deltaY;
      updateCatNudges();
    }, { passive: false });
  }
})();
</script>
<?php
$content = ob_get_clean();
$pageTitle = $dict['seo']['title'];
$layoutHeadExtra = !empty($hvSellerCatalog)
  ? '<link rel="stylesheet" href="' . e(base_url()) . '/assets/vendor/select2/select2.min.css?v=1">'
  : '';
require __DIR__ . '/layout.php';
