<?php

declare(strict_types=1);

/**
 * Ajuste de precio unitario según `customer_groups` (percentage_on_price + percent_price_amount).
 * Usado por clientes mayoristas (rol 3) y por vendedores vía {@see SellerCustomers}.
 */
final class CustomerPriceAdjustment
{
    /**
     * @param array<string, mixed>|null $g fila de `customer_groups`
     *
     * @return array{kind:string, percent:float}
     */
    public static function fromGroupRow(?array $g): array
    {
        if (!is_array($g)) {
            return ['kind' => 'none', 'percent' => 0.0];
        }
        $pop = (string) ($g['percentage_on_price'] ?? '');
        $pct = isset($g['percent_price_amount']) ? (float) $g['percent_price_amount'] : 0.0;
        if ($pct < 0) {
            $pct = abs($pct);
        }
        if ($pop !== '' && stripos($pop, 'decrease') !== false) {
            return ['kind' => 'decrease', 'percent' => $pct];
        }
        if ($pop !== '' && stripos($pop, 'increase') !== false) {
            return ['kind' => 'increase', 'percent' => $pct];
        }

        return ['kind' => 'none', 'percent' => 0.0];
    }

    /**
     * Reglas de precio para un cliente por su `customeridfullvendor` en `customers`.
     *
     * @return array{kind:string, percent:float}
     */
    public static function forCustomerFv(\PDO $pdo, int $customerFvId, int $companyId): array
    {
        if ($customerFvId <= 0) {
            return ['kind' => 'none', 'percent' => 0.0];
        }
        $sql = 'SELECT `group_id` FROM `customers` WHERE (`customeridfullvendor` = ? OR `customer_id` = ?)';
        $params = [$customerFvId, $customerFvId];
        if ($companyId > 0) {
            $sql .= ' AND `company_id` = ?';
            $params[] = $companyId;
        }
        $sql .= ' LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['kind' => 'none', 'percent' => 0.0];
        }
        $gid = $row['group_id'] !== null && $row['group_id'] !== '' ? (int) $row['group_id'] : 0;
        if ($gid <= 0) {
            return ['kind' => 'none', 'percent' => 0.0];
        }
        require_once __DIR__ . '/CustomerGroupsLocal.php';
        $g = CustomerGroupsLocal::byGroupId($gid);

        return self::fromGroupRow($g);
    }

    /**
     * @param array{kind:string, percent:float} $adj
     */
    public static function applyToUnitPrice(float $unitPrice, array $adj): float
    {
        $kind = (string) ($adj['kind'] ?? 'none');
        if ($kind === 'none') {
            return $unitPrice;
        }
        $p = (float) ($adj['percent'] ?? 0.0);
        if ($p <= 0) {
            return $unitPrice;
        }
        if ($kind === 'decrease') {
            return max(0.0, $unitPrice * (1.0 - $p / 100.0));
        }
        if ($kind === 'increase') {
            return $unitPrice * (1.0 + $p / 100.0);
        }

        return $unitPrice;
    }
}
