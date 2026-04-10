<?php

declare(strict_types=1);

/**
 * Clientes asignados a un vendedor: `customers.user_id` es CSV de user_id FullVendor (usersList).
 */
final class SellerCustomers
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function listForSellerFvUserId(\PDO $pdo, int $sellerFvUserId, int $companyId): array
    {
        if ($sellerFvUserId <= 0) {
            return [];
        }

        require_once __DIR__ . '/CustomerGroupsLocal.php';

        $cols = [
            'customer_id', 'customeridfullvendor', 'company_id', 'language_id', 'user_id',
            'name', 'business_name', 'tax_id', 'term_id', 'term_name', 'group_id', 'group_name',
            'percentage_on_price', 'percent_price_amount', 'email', 'phone', 'cell_phone', 'notes',
            'commercial_address', 'commercial_delivery_address', 'commercial_country', 'commercial_state',
            'commercial_city', 'commercial_zone', 'commercial_zip_code',
            'dispatch_address', 'dispatch_delivery_address', 'dispatch_country', 'dispatch_state',
            'dispatch_city', 'dispatch_zone', 'dispatch_zip_code', 'dispatch_shipping_notes',
            'catalog_emails', 'customer_created_at', 'customer_status', 'cust_id_kor', 'id_kor',
            'assign_catalog', 'discount', 'modified_at', 'balance', 'sales',
        ];
        $sql = 'SELECT ' . implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $cols)) . ' FROM `customers`';
        $params = [];
        if ($companyId > 0) {
            $sql .= ' WHERE `company_id` = ?';
            $params[] = $companyId;
        }
        $sql .= ' ORDER BY `business_name`, `name`';

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        $sellerStr = (string) $sellerFvUserId;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!self::csvContainsUserId((string) ($row['user_id'] ?? ''), $sellerStr)) {
                continue;
            }
            $fv = (int) ($row['customeridfullvendor'] ?? 0);
            if ($fv <= 0) {
                $fv = (int) ($row['customer_id'] ?? 0);
            }
            if ($fv <= 0) {
                continue;
            }

            $bn = self::decodeDisplay((string) ($row['business_name'] ?? ''));
            $nm = self::decodeDisplay((string) ($row['name'] ?? ''));
            $label = $bn !== '' && $nm !== '' && strcasecmp($bn, $nm) !== 0
                ? trim($bn . ' — ' . $nm)
                : ($bn !== '' ? $bn : ($nm !== '' ? $nm : ('#' . $fv)));

            $gid = $row['group_id'] !== null && $row['group_id'] !== '' ? (int) $row['group_id'] : 0;
            $gRow = $gid > 0 ? CustomerGroupsLocal::byGroupId($gid) : null;
            $priceAdjustment = self::priceAdjustmentFromGroupRow($gRow);

            $out[] = array_merge(
                [
                    'label' => $label,
                    'customeridfullvendor' => $fv,
                    'customer_id' => (int) ($row['customer_id'] ?? 0),
                    'company_id' => (int) ($row['company_id'] ?? 0),
                    'language_id' => (int) ($row['language_id'] ?? 0),
                    'user_id' => self::decodeDisplay((string) ($row['user_id'] ?? '')),
                    'term_id' => $row['term_id'] !== null && $row['term_id'] !== '' ? (int) $row['term_id'] : null,
                    'group_id' => $gid > 0 ? $gid : null,
                    'catalog_emails' => (int) ($row['catalog_emails'] ?? 0),
                    'customer_status' => (int) ($row['customer_status'] ?? 0),
                    'assign_catalog' => (int) ($row['assign_catalog'] ?? 0),
                    'id_kor' => (int) ($row['id_kor'] ?? 0),
                    'percentage_on_price' => isset($row['percentage_on_price']) ? (float) $row['percentage_on_price'] : 0.0,
                    'percent_price_amount' => isset($row['percent_price_amount']) ? (float) $row['percent_price_amount'] : 0.0,
                    'discount' => isset($row['discount']) ? (float) $row['discount'] : 0.0,
                    'balance' => isset($row['balance']) ? (float) $row['balance'] : 0.0,
                    'sales' => isset($row['sales']) ? (float) $row['sales'] : 0.0,
                    'price_adjustment' => $priceAdjustment,
                ],
                self::decodedStringFields($row, [
                    'name', 'business_name', 'tax_id', 'term_name', 'group_name',
                    'email', 'phone', 'cell_phone', 'notes',
                    'commercial_address', 'commercial_delivery_address', 'commercial_country', 'commercial_state',
                    'commercial_city', 'commercial_zone', 'commercial_zip_code',
                    'dispatch_address', 'dispatch_delivery_address', 'dispatch_country', 'dispatch_state',
                    'dispatch_city', 'dispatch_zone', 'dispatch_zip_code', 'dispatch_shipping_notes',
                    'cust_id_kor',
                ]),
                [
                    'customer_created_at' => self::rawOrEmpty($row['customer_created_at'] ?? null),
                    'modified_at' => self::rawOrEmpty($row['modified_at'] ?? null),
                ]
            );
        }

        return $out;
    }

    /**
     * Cliente asignado al vendedor por `customeridfullvendor`, o null si no corresponde.
     *
     * @return array<string, mixed>|null
     */
    public static function assignedCustomerForFvId(\PDO $pdo, int $sellerFvUserId, int $companyId, int $customerFvId): ?array
    {
        if ($customerFvId <= 0) {
            return null;
        }
        foreach (self::listForSellerFvUserId($pdo, $sellerFvUserId, $companyId) as $c) {
            if ((int) ($c['customeridfullvendor'] ?? 0) === $customerFvId) {
                return $c;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $keys
     * @return array<string, string>
     */
    private static function decodedStringFields(array $row, array $keys): array
    {
        $o = [];
        foreach ($keys as $k) {
            $o[$k] = self::decodeDisplay((string) ($row[$k] ?? ''));
        }

        return $o;
    }

    private static function decodeDisplay(string $s): string
    {
        $t = trim($s);
        if ($t === '') {
            return '';
        }

        return html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function rawOrEmpty(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        $s = trim((string) $v);

        return $s;
    }

    /**
     * @param array<string, mixed>|null $g
     *
     * @return array{kind:string, percent:float}
     */
    private static function priceAdjustmentFromGroupRow(?array $g): array
    {
        require_once __DIR__ . '/CustomerPriceAdjustment.php';

        return CustomerPriceAdjustment::fromGroupRow($g);
    }

    private static function csvContainsUserId(string $csv, string $needle): bool
    {
        $csv = trim($csv);
        if ($csv === '' || $needle === '') {
            return false;
        }
        foreach (preg_split('/\s*,\s*/', $csv) ?: [] as $part) {
            if (trim((string) $part) === $needle) {
                return true;
            }
        }

        return false;
    }
}
