<?php

declare(strict_types=1);

$session = require_account_session($lang, true);
require_once dirname(__DIR__, 2) . '/lib/FullVendorOrdersDb.php';

$orderId = (int) ($hv_order_detail_id ?? 0);
if ($orderId <= 0) {
    http_response_code(404);
    $dict = load_dictionary($lang);
    require __DIR__ . '/../errors/404.php';
    exit;
}

$od = $dict['order_detail'] ?? [];
$rol = (int) ($session['rolId'] ?? 0);
$fvUid = (int) ($session['userId'] ?? 0);
$fvCid = (int) ($session['customerId'] ?? 0);

/** @var array<string, mixed>|null */
$hvOrder = null;
$hvOrderErr = false;
$hvOrderErrDetail = '';

if (fullvendor_db_configured()) {
    try {
        $hvOrder = FullVendorOrdersDb::getOrderDetail($orderId, $rol, $fvUid, $fvCid, $lang);
    } catch (Throwable $e) {
        $hvOrderErr = true;
        if (function_exists('app_debug') && app_debug()) {
            AppLog::appException($e, 'order detail');
            $hvOrderErrDetail = $e->getMessage();
        }
    }
}

/**
 * @param array<string, mixed> $o
 * @return list<array<string, mixed>>
 */
$linesForDisplay = static function (array $o): array {
    $dl = $o['detail_lines'] ?? null;
    if (is_array($dl) && $dl !== []) {
        return $dl;
    }
    $pl = $o['product_list'] ?? [];
    if (!is_array($pl) || $pl === []) {
        return [];
    }
    $out = [];
    $i = 0;
    foreach ($pl as $row) {
        if (!is_array($row)) {
            continue;
        }
        ++$i;
        $qty = (float) str_replace(',', '', (string) ($row['qty'] ?? '0'));
        $sp = (float) str_replace(',', '', (string) ($row['sale_price'] ?? '0'));
        $out[] = [
            'line_no' => $i,
            'image_url' => '',
            'name' => (string) ($row['name'] ?? ''),
            'sku' => (string) ($row['sku'] ?? ''),
            'quantity_formatted' => (string) ($row['qty'] ?? number_format($qty, 2, '.', '')),
            'quantity' => $qty,
            'pack_formatted' => '0.00',
            'quantity_assigned_formatted' => '0.00',
            'stock_formatted' => '—',
            'sale_price_formatted' => (string) ($row['sale_price'] ?? number_format($sp, 2, '.', '')),
            'total_order_formatted' => number_format($qty * $sp, 2, '.', ''),
            'total_assigned_formatted' => '0.00',
            'line_comment' => (string) ($row['comment'] ?? ''),
            'price_modified' => false,
            'price_fixed' => false,
        ];
    }

    return $out;
};

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

ob_start();
?>
<?php
$invBp = base_url();
?>
<div class="min-h-[calc(100vh-8rem)] bg-gradient-to-b from-slate-200/60 via-slate-100/80 to-slate-200/40">
<div class="max-w-[920px] mx-auto px-4 py-8 sm:py-10">
  <a href="<?= e($invBp . '/' . $lang . '/account/orders') ?>" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-600 hover:text-slate-900 mb-6 transition-colors">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
    <?= e($od['back'] ?? 'Back') ?>
  </a>

  <div class="text-sm">
    <?php if (!fullvendor_db_configured()) { ?>
      <p class="text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-4 py-3"><?= e($od['need_db'] ?? $dict['common']['error'] ?? '') ?></p>
    <?php } elseif ($hvOrderErr) { ?>
      <div class="text-red-700 bg-red-50 border border-red-200 rounded-lg px-4 py-3 space-y-1">
        <p><?= e($dict['common']['error'] ?? 'Error') ?></p>
        <?php if ($hvOrderErrDetail !== '') { ?>
        <pre class="text-xs whitespace-pre-wrap break-words text-red-900/90 font-mono"><?= e($hvOrderErrDetail) ?></pre>
        <?php } ?>
      </div>
    <?php } elseif ($hvOrder === null) { ?>
      <p class="text-gray-600"><?= e($od['not_found'] ?? '') ?></p>
    <?php } else {
        $o = $hvOrder;
        $lines = $linesForDisplay($o);
        $custNotes = trim(implode("\n\n", array_filter([
            trim((string) ($o['order_comments'] ?? '')),
            trim((string) ($o['delivery_notes'] ?? '')),
        ], static fn (string $x): bool => $x !== '')));
        $cust = (string) ($o['customer_display_name'] ?? $o['customer_name'] ?? $o['business_name'] ?? $o['name'] ?? '');
        $addr = (string) ($o['customer_address_line'] ?? '');
        $phone = (string) ($o['phone'] ?? '');
        $cell = (string) ($o['customer_cell_phone'] ?? '');
        $email = (string) ($o['email'] ?? '');
        $seller = (string) ($o['seller_name'] ?? '');
        $wh = (string) ($o['warehouse_name'] ?? '');
        $origin = (string) ($o['source'] ?? '');
        $created = (string) ($o['created'] ?? $o['order_date'] ?? '');
        $num = (string) ($o['order_number'] ?? $o['order_id'] ?? '');
        $st = (string) ($o['status_label'] ?? $o['status'] ?? '');
        $sum = is_array($o['summary'] ?? null) ? $o['summary'] : [];
        $subO = (string) ($sum['subtotal_order_formatted'] ?? $fmtMoney((float) ($sum['subtotal_order'] ?? 0), $lang));
        $discSuffix = isset($sum['discount_label_suffix']) && (string) $sum['discount_label_suffix'] !== ''
            ? ' (' . e((string) $sum['discount_label_suffix']) . ')' : '';
        $discO = (string) ($sum['discount_order_formatted'] ?? $fmtMoney((float) ($sum['discount_order'] ?? 0), $lang));
        $totO = (string) ($sum['total_order_formatted'] ?? $fmtMoney((float) ($sum['total_order'] ?? $o['total_value'] ?? 0), $lang));
        $poNum = $num !== '' ? $num : ('#' . (int) $orderId);
        $poDateStr = $fmtDate($created !== '' ? $created : null, $lang);
        $shipVia = $wh !== '' ? $wh : ($origin !== '' ? strtoupper($origin) : '—');
        $taxZero = $fmtMoney(0.0, $lang);
        $shipZero = $fmtMoney(0.0, $lang);
        $stLower = strtolower($st);
        ?>
    <article class="max-w-[900px] mx-auto overflow-hidden rounded-2xl bg-white text-slate-900 shadow-xl shadow-slate-900/[0.08] ring-1 ring-slate-200/90 print:rounded-none print:shadow-none print:ring-0">
      <header class="relative bg-gradient-to-br from-indigo-600 via-violet-600 to-indigo-800 px-5 py-4 sm:px-6 sm:py-5 text-white print:bg-indigo-700">
        <div class="absolute inset-0 bg-[radial-gradient(ellipse_120%_80%_at_100%_0%,rgba(255,255,255,0.18),transparent_50%)] pointer-events-none" aria-hidden="true"></div>
        <div class="relative flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <h1 class="text-2xl font-bold tracking-tight sm:text-[1.65rem]"><?= e($od['po_title'] ?? '') ?></h1>
          </div>
          <dl class="flex flex-wrap gap-2 sm:justify-end">
            <div class="flex overflow-hidden rounded-lg bg-white/10 ring-1 ring-white/20 backdrop-blur-sm">
              <dt class="px-2.5 py-1.5 text-[10px] font-semibold uppercase tracking-wide text-white/80"><?= e($od['po_date'] ?? '') ?></dt>
              <dd class="bg-white/95 px-3 py-1.5 text-sm font-medium tabular-nums text-indigo-950"><?= e($poDateStr) ?></dd>
            </div>
            <div class="flex overflow-hidden rounded-lg bg-white/10 ring-1 ring-white/20 backdrop-blur-sm">
              <dt class="px-2.5 py-1.5 text-[10px] font-semibold uppercase tracking-wide text-white/80"><?= e($od['po_number'] ?? '') ?></dt>
              <dd class="bg-white/95 px-3 py-1.5 text-sm font-semibold tabular-nums tracking-tight text-indigo-950"><?= e($poNum) ?></dd>
            </div>
          </dl>
        </div>
      </header>

      <section class="border-b border-slate-200/90 px-5 py-4 sm:px-6 sm:py-4">
        <div class="rounded-xl border border-slate-200/90 bg-gradient-to-b from-slate-50 to-white p-4 shadow-sm shadow-slate-900/[0.03]">
          <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-indigo-600"><?= e($od['po_ship_to'] ?? '') ?></p>
          <div class="mt-2 space-y-0.5 text-sm leading-snug text-slate-800">
            <?php if ($cust !== '') { ?><p class="text-base font-semibold text-slate-900"><?= e($cust) ?></p><?php } ?>
            <?php if ($addr !== '') { ?><p class="text-slate-600"><?= e($addr) ?></p><?php } ?>
            <?php
            $phoneLine = [];
        if ($phone !== '') {
            $phoneLine[] = $phone;
        }
        if ($cell !== '' && $cell !== $phone) {
            $phoneLine[] = $cell;
        }
        if ($phoneLine !== []) {
            ?><p class="text-slate-600"><?= e(implode(' · ', $phoneLine)) ?></p><?php
        }
            ?>
            <?php if ($email !== '') { ?><p class="text-slate-600"><?= e($email) ?></p><?php } ?>
          </div>
        </div>
      </section>

      <div class="grid grid-cols-2 gap-px border-b border-slate-200/90 bg-slate-200/90 text-xs sm:grid-cols-4">
        <div class="bg-white px-3 py-2.5">
          <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-500"><?= e($od['po_requisitioner'] ?? '') ?></p>
          <p class="mt-0.5 font-medium text-slate-900"><?= e($seller !== '' ? $seller : '—') ?></p>
        </div>
        <div class="bg-white px-3 py-2.5">
          <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-500"><?= e($od['po_ship_via'] ?? '') ?></p>
          <p class="mt-0.5 font-medium text-slate-900"><?= e($shipVia) ?></p>
        </div>
        <div class="bg-white px-3 py-2.5">
          <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-500"><?= e($od['po_fob'] ?? '') ?></p>
          <p class="mt-0.5 font-medium text-slate-900">—</p>
        </div>
        <div class="col-span-2 bg-white px-3 py-2.5 sm:col-span-1">
          <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-500"><?= e($od['po_shipping_terms'] ?? '') ?></p>
          <p class="mt-0.5"><?php if ($st !== '') { ?>
            <?php if (str_contains($stLower, 'reject') || str_contains($stLower, 'cancel') || str_contains($stLower, 'denied')) { ?>
            <span class="inline-flex items-center rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-semibold text-rose-800 ring-1 ring-inset ring-rose-200/80"><?= e($st) ?></span>
            <?php } elseif (str_contains($stLower, 'pend') || str_contains($stLower, 'open') || str_contains($stLower, 'process')) { ?>
            <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-900 ring-1 ring-inset ring-amber-200/80"><?= e($st) ?></span>
            <?php } elseif (str_contains($stLower, 'complet') || str_contains($stLower, 'ship') || str_contains($stLower, 'closed')) { ?>
            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-800 ring-1 ring-inset ring-emerald-200/80"><?= e($st) ?></span>
            <?php } else { ?>
            <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-700 ring-1 ring-inset ring-slate-200/80"><?= e($st) ?></span>
            <?php } ?>
          <?php } else { ?><span class="font-medium text-slate-900">—</span><?php } ?></p>
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full min-w-[560px] border-collapse text-sm">
          <thead>
            <tr class="bg-slate-800 text-left text-white">
              <th class="w-10 px-2 py-2 text-center text-[10px] font-bold uppercase tracking-wider"><?= e($od['po_col_item'] ?? '') ?></th>
              <th class="px-3 py-2 text-[10px] font-bold uppercase tracking-wider"><?= e($od['invoice_table_description'] ?? '') ?></th>
              <th class="w-[4.5rem] px-2 py-2 text-center text-[10px] font-bold uppercase tracking-wider"><?= e($od['po_col_qty'] ?? $od['quantity'] ?? '') ?></th>
              <th class="w-[5.5rem] px-2 py-2 text-right text-[10px] font-bold uppercase tracking-wider"><?= e($od['po_col_unit'] ?? $od['invoice_table_unit_price'] ?? '') ?></th>
              <th class="w-[6rem] px-3 py-2 text-right text-[10px] font-bold uppercase tracking-wider"><?= e($od['po_col_line_total'] ?? $od['invoice_table_amount'] ?? '') ?></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php
            $ix = 0;
        foreach ($lines as $L) {
            if (!is_array($L)) {
                continue;
            }
            ++$ix;
            $isDetail = isset($L['line_no']);
            $imgUrl = $isDetail ? (string) ($L['image_url'] ?? '') : '';
            $pname = (string) ($L['name'] ?? '');
            $sku = (string) ($L['sku'] ?? '');
            $pnameTrim = trim($pname);
            $skuTrim = trim($sku);
            $nameSkuSame = $skuTrim !== '' && strcasecmp($pnameTrim, $skuTrim) === 0;
            $nameContainsSku = $skuTrim !== '' && $pnameTrim !== '' && stripos($pnameTrim, $skuTrim) !== false;
            $showSkuLine = $skuTrim !== '' && !$nameSkuSame && !$nameContainsSku;
            $qty = $isDetail ? (string) ($L['quantity_formatted'] ?? '') : (string) ($L['qty'] ?? '');
            $sp = $isDetail ? (string) ($L['sale_price_formatted'] ?? '') : (string) ($L['sale_price'] ?? '');
            $to = $isDetail ? (string) ($L['total_order_formatted'] ?? '') : '';
            $note = $isDetail ? (string) ($L['line_comment'] ?? '') : (string) ($L['comment'] ?? '');
            $mod = $isDetail && !empty($L['price_modified']);
            $fix = $isDetail && !empty($L['price_fixed']);
            $rowNum = $isDetail ? (int) $L['line_no'] : $ix;
            ?>
            <tr class="align-top transition-colors hover:bg-slate-50/80">
              <td class="px-2 py-2.5 text-center text-xs tabular-nums text-slate-500"><?= (int) $rowNum ?></td>
              <td class="px-3 py-2.5">
                <div class="flex gap-2.5">
                  <?php if ($imgUrl !== '') { ?>
                  <img src="<?= e($imgUrl) ?>" alt="" class="h-10 w-10 shrink-0 rounded-md bg-white object-cover ring-1 ring-slate-200" width="40" height="40" loading="lazy" />
                  <?php } ?>
                  <div class="min-w-0">
                    <?php
                    if ($nameSkuSame) {
                        ?><p class="text-sm font-semibold leading-snug text-slate-900"><?= e($pnameTrim) ?></p><?php
                    } elseif ($pnameTrim !== '') {
                        ?><p class="text-sm font-semibold leading-snug text-slate-900"><?= e($pnameTrim) ?></p><?php
                        if ($showSkuLine) {
                            ?><p class="mt-0.5 text-[11px] font-mono text-slate-500"><?= e($od['sku'] ?? '') ?> <?= e($skuTrim) ?></p><?php
                        }
                    } elseif ($skuTrim !== '') {
                        ?><p class="text-sm font-semibold font-mono leading-snug text-slate-900"><?= e($skuTrim) ?></p><?php
                    }
            ?>
                    <?php if ($mod || $fix) { ?>
                    <div class="mt-1 flex flex-wrap gap-1">
                      <?php if ($mod) { ?><span class="rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-900 ring-1 ring-amber-200/80"><?= e($od['modified_price'] ?? '') ?></span><?php } ?>
                      <?php if ($fix) { ?><span class="rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-900 ring-1 ring-amber-200/80"><?= e($od['fixed_price'] ?? '') ?></span><?php } ?>
                    </div>
                    <?php } ?>
                  </div>
                </div>
              </td>
              <td class="px-2 py-2.5 text-center text-sm tabular-nums font-medium text-slate-800"><?= e($qty) ?></td>
              <td class="px-2 py-2.5 text-right text-sm tabular-nums text-slate-700"><?= e($sp) ?></td>
              <td class="bg-slate-50/80 px-3 py-2.5 text-right text-sm font-semibold tabular-nums text-slate-900"><?= e($to) ?></td>
            </tr>
            <?php if ($note !== '') { ?>
            <tr class="bg-slate-50/60">
              <td class="p-0"></td>
              <td colspan="4" class="px-3 py-2 text-xs leading-relaxed text-slate-600"><span class="font-semibold text-slate-700"><?= e($od['comments_line'] ?? '') ?>:</span> <?= e($note) ?></td>
            </tr>
            <?php } ?>
            <?php } ?>
            <?php if ($lines === []) { ?>
            <tr>
              <td colspan="5" class="py-10 text-center text-sm text-slate-500"><?= e($od['no_lines'] ?? $dict['orders']['no_orders'] ?? '') ?></td>
            </tr>
            <?php } ?>
          </tbody>
        </table>
      </div>

      <div class="grid gap-px border-t border-slate-200/90 bg-slate-200/90 md:grid-cols-2">
        <div class="flex min-h-[11rem] flex-col bg-white">
          <div class="border-b border-slate-100 px-4 py-2">
            <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500"><?= e($od['po_comments_title'] ?? '') ?></p>
          </div>
          <div class="flex-1 space-y-3 px-4 py-3 text-sm">
            <?php if ($custNotes !== '') { ?>
            <div>
              <p class="mb-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-400"><?= e($od['customer_notes'] ?? '') ?></p>
              <p class="whitespace-pre-wrap leading-relaxed text-slate-700"><?= e($custNotes) ?></p>
            </div>
            <?php } ?>
            <?php $intn = (string) ($o['internal_notes'] ?? ''); ?>
            <?php if ($intn !== '') { ?>
            <div>
              <p class="mb-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-400"><?= e($od['internal_notes'] ?? '') ?></p>
              <p class="whitespace-pre-wrap leading-relaxed text-slate-700"><?= e($intn) ?></p>
            </div>
            <?php } ?>
            <?php if ($custNotes === '' && $intn === '') { ?>
            <p class="text-slate-400">—</p>
            <?php } ?>
          </div>
        </div>
        <div class="bg-gradient-to-b from-slate-50 to-white p-4 sm:p-5">
          <table class="ml-auto w-full max-w-[260px] border-collapse text-sm">
            <tbody class="divide-y divide-slate-200/90">
            <tr>
              <td class="py-1.5 pr-3 text-right text-slate-600"><?= e($od['subtotal'] ?? '') ?></td>
              <td class="py-1.5 pl-3 text-right tabular-nums font-medium text-slate-900"><?= e($subO) ?></td>
            </tr>
            <tr>
              <td class="py-1.5 pr-3 text-right text-slate-600"><?= e($od['po_tax'] ?? '') ?></td>
              <td class="py-1.5 pl-3 text-right tabular-nums text-slate-800"><?= e($taxZero) ?></td>
            </tr>
            <tr>
              <td class="py-1.5 pr-3 text-right text-slate-600"><?= e($od['po_shipping'] ?? '') ?></td>
              <td class="py-1.5 pl-3 text-right tabular-nums text-slate-800"><?= e($shipZero) ?></td>
            </tr>
            <tr>
              <td class="py-1.5 pr-3 text-right text-slate-600"><?= e($od['po_other'] ?? '') ?> <span class="text-[11px] font-normal text-slate-400">(<?= e($od['discount'] ?? '') ?><?= $discSuffix ?>)</span></td>
              <td class="py-1.5 pl-3 text-right tabular-nums text-slate-800"><?= e($discO) ?></td>
            </tr>
            <tr>
              <td class="rounded-bl-lg bg-gradient-to-r from-indigo-600 to-violet-600 py-2.5 pr-3 text-right text-xs font-bold uppercase tracking-wide text-white"><?= e($od['total'] ?? '') ?></td>
              <td class="rounded-br-lg bg-indigo-950 py-2.5 pl-3 text-right text-base font-bold tabular-nums text-white"><?= e($totO) ?></td>
            </tr>
            </tbody>
          </table>
        </div>
      </div>

      <p class="border-t border-slate-200/90 bg-slate-50/50 px-4 py-3 text-center text-[11px] leading-relaxed text-slate-500">
        <?= e($od['po_footer_note'] ?? '') ?> <?= e($od['invoice_company_line'] ?? '') ?>
      </p>
    </article>
    <?php } ?>
  </div>
</div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = ($od['title'] ?? 'Order') . ' — ' . ($dict['seo']['title'] ?? '');
require __DIR__ . '/../layout.php';
