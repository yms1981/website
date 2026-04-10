<?php

declare(strict_types=1);

/**
 * Preferencias de catálogo vendedor (cliente seleccionado, carritos por cliente, notas) por usuario FullVendor.
 */
final class SellerCatalogUserState
{
    private const MAX_CART_KEYS = 200;
    private const MAX_LINES_PER_CART = 500;
    private const MAX_STR = 600;
    private const MAX_LINE_NOTE = 2000;
    private const MAX_ORDER_NOTES = 8000;

    /**
     * @return array{row_exists:bool,selected_customer_fv:int|null,carts:array<string,list<array<string,mixed>>>,order_notes:string}
     */
    public static function get(\PDO $pdo, int $sellerUserId, int $companyId): array
    {
        if ($sellerUserId <= 0) {
            return ['row_exists' => false, 'selected_customer_fv' => null, 'carts' => [], 'order_notes' => ''];
        }
        $st = $pdo->prepare(
            'SELECT `selected_customer_fv`, `carts_json`, `order_notes` FROM `seller_catalog_user_state`
             WHERE `user_id` = ? AND `company_id` = ? LIMIT 1'
        );
        $st->execute([$sellerUserId, $companyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['row_exists' => false, 'selected_customer_fv' => null, 'carts' => [], 'order_notes' => ''];
        }
        $fv = $row['selected_customer_fv'] ?? null;
        $sel = ($fv !== null && $fv !== '') ? (int) $fv : null;
        if ($sel !== null && $sel <= 0) {
            $sel = null;
        }
        $carts = [];
        $raw = $row['carts_json'] ?? null;
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $carts = self::sanitizeCartsMap($decoded);
                }
            } catch (Throwable) {
                $carts = [];
            }
        }
        $notes = (string) ($row['order_notes'] ?? '');

        return ['row_exists' => true, 'selected_customer_fv' => $sel, 'carts' => $carts, 'order_notes' => $notes];
    }

    /**
     * @param array{selectedCustomerFv?:mixed,carts?:mixed,orderNotes?:mixed} $body
     * @return array{selected_customer_fv:int|null,carts:array<string,list<array<string,mixed>>>,order_notes:string}
     */
    public static function patch(\PDO $pdo, int $sellerUserId, int $companyId, array $body): array
    {
        $cur = self::get($pdo, $sellerUserId, $companyId);
        $sel = $cur['selected_customer_fv'];
        $carts = $cur['carts'];
        $orderNotes = $cur['order_notes'];

        if (array_key_exists('selectedCustomerFv', $body)) {
            $v = $body['selectedCustomerFv'];
            if ($v === null || $v === '' || (is_numeric($v) && (int) $v <= 0)) {
                $sel = null;
            } else {
                $sel = (int) $v;
            }
        }
        if (array_key_exists('carts', $body) && is_array($body['carts'])) {
            $carts = self::sanitizeCartsMap($body['carts']);
        }
        if (array_key_exists('orderNotes', $body)) {
            $orderNotes = self::clipOrderNotes((string) ($body['orderNotes'] ?? ''));
        }

        self::upsert($pdo, $sellerUserId, $companyId, $sel, $carts, $orderNotes);

        return ['selected_customer_fv' => $sel, 'carts' => $carts, 'order_notes' => $orderNotes];
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,list<array<string,mixed>>>
     */
    private static function sanitizeCartsMap(array $raw): array
    {
        $out = [];
        $n = 0;
        foreach ($raw as $fvKey => $lines) {
            if ($n >= self::MAX_CART_KEYS) {
                break;
            }
            $fv = preg_replace('/\D/', '', (string) $fvKey);
            if ($fv === '' || (int) $fv <= 0) {
                continue;
            }
            if (!is_array($lines)) {
                continue;
            }
            $clean = self::sanitizeCartLines($lines);
            if ($clean !== []) {
                $out[$fv] = $clean;
            }
            ++$n;
        }

        return $out;
    }

    /**
     * @param list<mixed> $lines
     * @return list<array<string,mixed>>
     */
    private static function sanitizeCartLines(array $lines): array
    {
        $out = [];
        $i = 0;
        foreach ($lines as $line) {
            if ($i >= self::MAX_LINES_PER_CART) {
                break;
            }
            if (!is_array($line)) {
                continue;
            }
            $pid = isset($line['productId']) ? (int) $line['productId'] : 0;
            if ($pid <= 0) {
                continue;
            }
            $qty = max(0, (int) ($line['qty'] ?? 0));
            $moq = max(1, (int) ($line['moq'] ?? 1));
            $up = 0.0;
            if (isset($line['unitPrice'])) {
                $up = (float) $line['unitPrice'];
            } elseif (isset($line['sale_price'])) {
                $up = (float) $line['sale_price'];
            }
            if (!is_finite($up) || $up < 0) {
                $up = 0.0;
            }
            $noteRaw = '';
            if (isset($line['lineNote'])) {
                $noteRaw = (string) $line['lineNote'];
            } elseif (isset($line['note'])) {
                $noteRaw = (string) $line['note'];
            }
            $out[] = [
                'productId' => $pid,
                'qty' => $qty,
                'moq' => $moq,
                'unitPrice' => round($up, 4),
                'name' => self::clipStr((string) ($line['name'] ?? '')),
                'sku' => self::clipStr((string) ($line['sku'] ?? '')),
                'image' => self::clipStr((string) ($line['image'] ?? '')),
                'lineNote' => self::clipLineNote($noteRaw),
            ];
            ++$i;
        }

        return $out;
    }

    private static function clipLineNote(string $s): string
    {
        if (strlen($s) <= self::MAX_LINE_NOTE) {
            return $s;
        }

        return substr($s, 0, self::MAX_LINE_NOTE);
    }

    private static function clipOrderNotes(string $s): string
    {
        if (strlen($s) <= self::MAX_ORDER_NOTES) {
            return $s;
        }

        return substr($s, 0, self::MAX_ORDER_NOTES);
    }

    private static function clipStr(string $s): string
    {
        if (strlen($s) <= self::MAX_STR) {
            return $s;
        }

        return substr($s, 0, self::MAX_STR);
    }

    /**
     * @param array<string,list<array<string,mixed>>> $carts
     */
    private static function upsert(\PDO $pdo, int $sellerUserId, int $companyId, ?int $selectedCustomerFv, array $carts, string $orderNotes): void
    {
        if ($sellerUserId <= 0) {
            return;
        }
        $json = json_encode($carts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $ins = $pdo->prepare(
            'INSERT INTO `seller_catalog_user_state` (`user_id`, `company_id`, `selected_customer_fv`, `carts_json`, `order_notes`)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               `selected_customer_fv` = VALUES(`selected_customer_fv`),
               `carts_json` = VALUES(`carts_json`),
               `order_notes` = VALUES(`order_notes`),
               `updated_at` = CURRENT_TIMESTAMP'
        );
        $ins->execute([$sellerUserId, $companyId, $selectedCustomerFv, $json, $orderNotes === '' ? null : $orderNotes]);
    }
}
