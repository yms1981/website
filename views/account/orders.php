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
        $hvOrdersList = FullVendorOrdersDb::listForAccount($rol, $fvUid, $fvCid, $lang);
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
            <th class="py-3 px-2 text-blue-600 font-medium"><?= e($d['warehouse'] ?? '') ?></th>
            <th class="py-3 px-2 text-blue-600 font-medium"><?= e($d['date'] ?? '') ?></th>
            <th class="py-3 px-2 text-blue-600 font-medium"><?= e($d['total'] ?? '') ?></th>
            <th class="py-3 px-2 text-blue-600 font-medium"><?= e($d['comments'] ?? '') ?></th>
            <th class="py-3 pr-4 pl-2"><?= e($d['status'] ?? '') ?></th>
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
              $wh = (string) ($o['warehouse_name'] ?? '');
              $created = (string) ($o['order_date'] ?? $o['created'] ?? $o['date'] ?? '');
              $upd = (string) ($o['updated'] ?? '');
              $comments = (string) ($o['order_comments'] ?? $o['comments'] ?? '');
              $stLabel = (string) ($o['status_label'] ?? $o['status'] ?? '');
              $dotCol = $safeHex((string) ($o['status_icon_color'] ?? $o['status_color'] ?? ''));
              $totalVal = isset($o['total_value']) && is_numeric($o['total_value']) ? (float) $o['total_value'] : 0.0;
              $asgVal = isset($o['assigned_value']) && is_numeric($o['assigned_value']) ? (float) $o['assigned_value'] : 0.0;
              $mobile = !empty($o['is_mobile_order']);
              $detailHref = base_url() . '/' . $lang . '/account/orders/' . $oid;
              ?>
          <tr class="border-b border-gray-100 hover:bg-gray-50/80 align-top">
            <td class="py-4 pl-4 pr-2"><input type="checkbox" class="hv-order-cb rounded border-gray-300 text-blue-600" /></td>
            <td class="py-4 px-2">
              <?php if ($oid > 0) { ?>
              <a href="<?= e($detailHref) ?>" class="inline-flex items-center font-bold text-emerald-700 hover:text-emerald-900 hover:underline focus:outline-none focus:ring-2 focus:ring-emerald-500 rounded">
                <?php if ($mobile) { ?>
                <span title="<?= e($d['mobile_order_hint'] ?? '') ?>">
                  <svg class="inline-block w-4 h-4 mr-1 text-emerald-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
                </span>
                <?php } ?>
                <?= e($num) ?>
              </a>
              <?php } else { ?>
              <span class="inline-flex items-center font-bold text-emerald-700"><?= e($num) ?></span>
              <?php } ?>
            </td>
            <td class="py-4 px-2 font-bold text-emerald-700"><?= e($cust) ?></td>
            <td class="py-4 px-2 text-emerald-700 font-medium"><?= e($seller) ?></td>
            <td class="py-4 px-2 text-emerald-700"><?= e($wh) ?></td>
            <td class="py-4 px-2 text-emerald-700">
              <div><?= e($fmtDate($created !== '' ? $created : null, $lang)) ?></div>
              <?php if ($upd !== '') { ?>
              <div class="mt-1 inline-block rounded px-2 py-0.5 text-xs font-medium bg-amber-100 text-gray-900 border border-amber-200">
                <?= e($d['updated_badge'] ?? 'Updated') ?>: <?= e($fmtDate($upd, $lang)) ?>
              </div>
              <?php } ?>
            </td>
            <td class="py-4 px-2">
              <div class="inline-block rounded-md bg-gray-900 text-white text-xs font-semibold px-2.5 py-1 mb-1"><?= e($d['badge_total'] ?? 'Total') ?> <?= e($fmtMoney($totalVal, $lang)) ?></div>
              <div class="block rounded-md bg-emerald-600 text-white text-xs font-semibold px-2.5 py-1"><?= e($d['badge_assigned'] ?? 'Assigned') ?> <?= e($fmtMoney($asgVal, $lang)) ?></div>
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
          </tr>
          <?php } ?>
        </tbody>
      </table>
    </div>
    <script>
    (function () {
      var all = document.getElementById('hv-orders-select-all');
      if (!all) return;
      all.addEventListener('change', function () {
        document.querySelectorAll('.hv-order-cb').forEach(function (cb) { cb.checked = all.checked; });
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
