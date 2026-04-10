<?php

declare(strict_types=1);

require_once __DIR__ . '/CartSeller.php';

/**
 * Estado de catálogo / carrito JSON para clientes mayoristas (rol 3), análogo a {@see SellerCatalogUserState}.
 * `primary_seller_user_id` se toma siempre del primer ID numérico en `customers.user_id` (CSV).
 */
final class CustomerCatalogUserState
{
    private const MAX_LINES = 500;
    private const MAX_STR = 600;
    private const MAX_LINE_NOTE = 2000;
    private const MAX_ORDER_NOTES = 8000;

    /**
     * @return array{row_exists:bool,primary_seller_user_id:int,carts:array<string,list<array<string,mixed>>>,order_notes:string}
     */
    public static function get(\PDO $pdo, int $customerFvId, int $companyId): array
    {
        if ($customerFvId <= 0) {
            return ['row_exists' => false, 'primary_seller_user_id' => 0, 'carts' => [], 'order_notes' => ''];
        }
        $primary = CartSeller::primarySellerUserIdForCustomer($pdo, $customerFvId);

        $st = $pdo->prepare(
            'SELECT `primary_seller_user_id`, `carts_json`, `order_notes` FROM `customer_catalog_user_state`
             WHERE `customer_fv_id` = ? AND `company_id` = ? LIMIT 1'
        );
        $st->execute([$customerFvId, $companyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['row_exists' => false, 'primary_seller_user_id' => $primary, 'carts' => [], 'order_notes' => ''];
        }

        $carts = [];
        $raw = $row['carts_json'] ?? null;
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $carts = self::sanitizeCartsMap($decoded, $primary);
                }
            } catch (Throwable) {
                $carts = [];
            }
        }
        $notes = (string) ($row['order_notes'] ?? '');
        $storedSeller = isset($row['primary_seller_user_id']) ? (int) $row['primary_seller_user_id'] : 0;

        return [
            'row_exists' => true,
            'primary_seller_user_id' => $primary > 0 ? $primary : $storedSeller,
            'carts' => $carts,
            'order_notes' => $notes,
        ];
    }

    /**
     * @param array{carts?:mixed,orderNotes?:mixed} $body
     * @return array{primary_seller_user_id:int,carts:array<string,list<array<string,mixed>>>,order_notes:string}
     */
    public static function patch(\PDO $pdo, int $customerFvId, int $companyId, array $body): array
    {
        $primary = CartSeller::primarySellerUserIdForCustomer($pdo, $customerFvId);
        $cur = self::get($pdo, $customerFvId, $companyId);
        $carts = $cur['carts'];
        $orderNotes = $cur['order_notes'];

        if (array_key_exists('carts', $body)) {
            $carts = self::normalizeCartsFromBody($body['carts'], $primary);
        }
        if (array_key_exists('orderNotes', $body)) {
            $orderNotes = self::clipOrderNotes((string) ($body['orderNotes'] ?? ''));
        }

        self::upsert($pdo, $customerFvId, $companyId, $primary, $carts, $orderNotes);

        return [
            'primary_seller_user_id' => $primary,
            'carts' => $carts,
            'order_notes' => $orderNotes,
        ];
    }

    /**
     * @param mixed $rawBody
     * @return array<string,list<array<string,mixed>>>
     */
    private static function normalizeCartsFromBody($rawBody, int $primary): array
    {
        if (!is_array($rawBody)) {
            return [];
        }
        $bucketKey = $primary > 0 ? (string) $primary : '0';

        if ($rawBody === []) {
            return [];
        }

        $keys = array_keys($rawBody);
        $isList = $keys === range(0, count($rawBody) - 1);

        if ($isList) {
            $lines = self::sanitizeCartLines($rawBody);

            return $lines === [] ? [] : [$bucketKey => $lines];
        }

        $san = self::sanitizeCartsMap($rawBody, $primary);
        if ($san === []) {
            return [];
        }

        $merged = [];
        foreach ($san as $lines) {
            foreach ($lines as $ln) {
                $merged[] = $ln;
            }
        }
        $merged = self::sanitizeCartLines($merged);

        return $merged === [] ? [] : [$bucketKey => $merged];
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,list<array<string,mixed>>>
     */
    private static function sanitizeCartsMap(array $raw, int $primary): array
    {
        $out = [];
        $n = 0;
        foreach ($raw as $fvKey => $lines) {
            if ($n >= 50) {
                break;
            }
            $fv = preg_replace('/\D/', '', (string) $fvKey);
            if ($fv === '') {
                $fv = $primary > 0 ? (string) $primary : '0';
            }
            if ((int) $fv <= 0 && $fv !== '0') {
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
            if ($i >= self::MAX_LINES) {
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
    private static function upsert(\PDO $pdo, int $customerFvId, int $companyId, int $primarySellerUserId, array $carts, string $orderNotes): void
    {
        if ($customerFvId <= 0) {
            return;
        }
        $jsonFlags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $json = json_encode($carts, $jsonFlags);
        $ins = $pdo->prepare(
            'INSERT INTO `customer_catalog_user_state` (`customer_fv_id`, `company_id`, `primary_seller_user_id`, `carts_json`, `order_notes`)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               `primary_seller_user_id` = VALUES(`primary_seller_user_id`),
               `carts_json` = VALUES(`carts_json`),
               `order_notes` = VALUES(`order_notes`),
               `updated_at` = CURRENT_TIMESTAMP'
        );
        $ins->execute([
            $customerFvId,
            $companyId,
            $primarySellerUserId > 0 ? $primarySellerUserId : null,
            $json,
            $orderNotes === '' ? null : $orderNotes,
        ]);
    }
}
