<?php

declare(strict_types=1);

require_once __DIR__ . '/CartSeller.php';
require_once __DIR__ . '/CustomerCatalogUserState.php';

/**
 * Carrito API (/api/cart) para clientes rol 3: persiste en `customer_catalog_user_state.carts_json`
 * (misma tabla que /api/customer-catalog-state). Los vendedores usan {@see SellerCatalogUserState}.
 */
final class CustomerCatalogCart
{
    public static function uses(?array $session): bool
    {
        if (!Db::enabled() || $session === null || empty($session['approved'])) {
            return false;
        }

        return (int) ($session['rolId'] ?? 0) === 3 && (int) ($session['customerId'] ?? 0) > 0;
    }

    private static function customerFvId(array $session): int
    {
        return (int) ($session['customerId'] ?? 0);
    }

    private static function companyId(array $session): int
    {
        return (int) ($session['companyId'] ?? 0);
    }

    private static function bucketKey(\PDO $pdo, int $customerFvId): string
    {
        $p = CartSeller::primarySellerUserIdForCustomer($pdo, $customerFvId);

        return $p > 0 ? (string) $p : '0';
    }

    /**
     * @param array{carts:array<string,list<array<string,mixed>>>} $st
     * @return list<array<string,mixed>>
     */
    private static function linesForBucket(array $st, \PDO $pdo, int $customerFvId): array
    {
        $bk = self::bucketKey($pdo, $customerFvId);
        $carts = $st['carts'];
        if (isset($carts[$bk]) && is_array($carts[$bk]) && $carts[$bk] !== []) {
            return $carts[$bk];
        }
        if ($carts === []) {
            return [];
        }
        $byPid = [];
        foreach ($carts as $lines) {
            if (!is_array($lines)) {
                continue;
            }
            foreach ($lines as $ln) {
                if (!is_array($ln)) {
                    continue;
                }
                $pid = (int) ($ln['productId'] ?? 0);
                if ($pid <= 0) {
                    continue;
                }
                $byPid[$pid] = $ln;
            }
        }

        return array_values($byPid);
    }

    /**
     * Posibles claves `userId` en `portal_cart_item` según versiones anteriores (localUser, FV user, customer FV).
     *
     * @return list<int>
     */
    private static function portalTableUserIds(array $session): array
    {
        $ids = [];
        foreach ([
            (int) ($session['localUserId'] ?? 0),
            (int) ($session['userId'] ?? 0),
            (int) ($session['customerId'] ?? 0),
        ] as $id) {
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fetchPortalCartRows(\PDO $pdo, array $uids): array
    {
        $uids = array_values(array_filter(array_map('intval', $uids), static fn (int $n): bool => $n > 0));
        if ($uids === []) {
            return [];
        }
        $uids = array_values(array_unique($uids));
        try {
            $ph = implode(',', array_fill(0, count($uids), '?'));
            $stq = $pdo->prepare(
                "SELECT `id`, `productId`, `qty`, `sales_price`, `product_name`, `sku`, `image`, `moq`, `line_note`, `userId`
                 FROM `portal_cart_item` WHERE `userId` IN ($ph) ORDER BY `id` ASC"
            );
            $stq->execute($uids);
            $rows = $stq->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{productId:int,qty:int,moq:int,unitPrice:float,name:string,sku:string,image:string,lineNote:string}>
     */
    private static function portalRowsToCatalogLines(array $rows): array
    {
        $byPid = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $pid = (int) ($row['productId'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $qty = (int) (float) ($row['qty'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $byPid[$pid] = [
                'productId' => $pid,
                'qty' => $qty,
                'moq' => max(1, (int) ($row['moq'] ?? 1)),
                'unitPrice' => (float) ($row['sales_price'] ?? 0),
                'name' => (string) ($row['product_name'] ?? ''),
                'sku' => (string) ($row['sku'] ?? ''),
                'image' => (string) ($row['image'] ?? ''),
                'lineNote' => (string) ($row['line_note'] ?? ''),
            ];
        }

        return array_values($byPid);
    }

    /**
     * Si `customer_catalog_user_state` está vacío pero `portal_cart_item` tiene líneas bajo cualquier id de sesión, importa y borra portal.
     */
    private static function migrateFromPortalTablesIfEmpty(\PDO $pdo, array $session): void
    {
        if (!self::uses($session)) {
            return;
        }
        $cfv = self::customerFvId($session);
        $comp = self::companyId($session);
        $st = CustomerCatalogUserState::get($pdo, $cfv, $comp);
        if (self::linesForBucket($st, $pdo, $cfv) !== []) {
            return;
        }
        $uids = self::portalTableUserIds($session);
        if ($uids === []) {
            return;
        }
        $rows = self::fetchPortalCartRows($pdo, $uids);
        if ($rows === []) {
            return;
        }
        $lines = self::portalRowsToCatalogLines($rows);
        if ($lines === []) {
            return;
        }
        $bk = self::bucketKey($pdo, $cfv);
        try {
            CustomerCatalogUserState::patch($pdo, $cfv, $comp, ['carts' => [$bk => $lines]]);
        } catch (Throwable) {
            return;
        }
        $verify = CustomerCatalogUserState::get($pdo, $cfv, $comp);
        if (self::linesForBucket($verify, $pdo, $cfv) === []) {
            return;
        }
        try {
            $ph = implode(',', array_fill(0, count($uids), '?'));
            $pdo->prepare("DELETE FROM `portal_cart_item` WHERE `userId` IN ($ph)")->execute($uids);
            foreach ($uids as $u) {
                $pdo->prepare('DELETE FROM `portal_cart_header` WHERE `userId` = ?')->execute([$u]);
            }
        } catch (Throwable) {
        }
    }

    /** @return list<array<string, mixed>> */
    public static function apiItems(\PDO $pdo, array $session): array
    {
        if (!self::uses($session)) {
            return [];
        }
        self::migrateFromPortalTablesIfEmpty($pdo, $session);
        $cfv = self::customerFvId($session);
        $comp = self::companyId($session);
        $st = CustomerCatalogUserState::get($pdo, $cfv, $comp);
        $lines = self::linesForBucket($st, $pdo, $cfv);
        $out = self::linesToApiItems($lines);

        if ($out === []) {
            $out = self::linesToApiItems(self::portalRowsToCatalogLines(self::fetchPortalCartRows($pdo, self::portalTableUserIds($session))));
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $lines líneas formato catálogo (productId, qty, unitPrice, name, …)
     * @return list<array<string, mixed>>
     */
    private static function linesToApiItems(array $lines): array
    {
        $out = [];
        foreach ($lines as $ln) {
            if (!is_array($ln)) {
                continue;
            }
            $pid = (int) ($ln['productId'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $qty = (int) ($ln['qty'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $sp = (float) ($ln['unitPrice'] ?? 0);
            $out[] = [
                'product_id' => $pid,
                'productId' => $pid,
                'product_name' => (string) ($ln['name'] ?? ''),
                'name' => (string) ($ln['name'] ?? ''),
                'sku' => (string) ($ln['sku'] ?? ''),
                'qty' => $qty,
                'sale_price' => $sp,
                'price' => $sp,
                'cart_id' => $pid,
                'cartId' => $pid,
                'fob_price' => 0.0,
                'image' => (string) ($ln['image'] ?? ''),
                'moq' => max(1, (int) ($ln['moq'] ?? 1)),
                'line_note' => (string) ($ln['lineNote'] ?? ''),
                'lineNote' => (string) ($ln['lineNote'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param array{name?:string,sku?:string,image?:string,moq?:int|float,sale_price?:float,unitPrice?:float,fob_price?:float,lineNote?:string,line_note?:string} $meta
     * @return array{status:string,error?:string}
     */
    public static function setLine(\PDO $pdo, array $session, int $productId, float $qty, array $meta): array
    {
        if (!self::uses($session)) {
            return ['status' => '0', 'error' => 'Invalid session'];
        }
        if ($productId <= 0 || $qty < 0) {
            return ['status' => '0', 'error' => 'Invalid product or quantity'];
        }
        $cfv = self::customerFvId($session);
        $comp = self::companyId($session);
        $bk = self::bucketKey($pdo, $cfv);
        $st = CustomerCatalogUserState::get($pdo, $cfv, $comp);
        $lines = self::linesForBucket($st, $pdo, $cfv);
        $byPid = [];
        foreach ($lines as $ln) {
            if (!is_array($ln)) {
                continue;
            }
            $p = (int) ($ln['productId'] ?? 0);
            if ($p > 0) {
                $byPid[$p] = $ln;
            }
        }
        $qtyInt = (int) round($qty);
        if ($qtyInt <= 0) {
            unset($byPid[$productId]);
        } else {
            $name = mb_substr((string) ($meta['name'] ?? ''), 0, 512);
            $sku = mb_substr((string) ($meta['sku'] ?? ''), 0, 255);
            $image = mb_substr((string) ($meta['image'] ?? ''), 0, 1024);
            $moq = max(1, (int) ($meta['moq'] ?? 1));
            $salesPrice = (float) ($meta['sale_price'] ?? $meta['unitPrice'] ?? 0);
            if (!is_finite($salesPrice) || $salesPrice < 0) {
                $salesPrice = 0.0;
            }
            $lineNoteRaw = (string) ($meta['lineNote'] ?? $meta['line_note'] ?? '');
            if (strlen($lineNoteRaw) > 2000) {
                $lineNoteRaw = substr($lineNoteRaw, 0, 2000);
            }
            $prev = $byPid[$productId] ?? [];
            $byPid[$productId] = [
                'productId' => $productId,
                'qty' => $qtyInt,
                'moq' => $moq,
                'unitPrice' => round($salesPrice, 4),
                'name' => $name !== '' ? $name : (string) ($prev['name'] ?? ''),
                'sku' => $sku !== '' ? $sku : (string) ($prev['sku'] ?? ''),
                'image' => $image !== '' ? $image : (string) ($prev['image'] ?? ''),
                'lineNote' => $lineNoteRaw !== '' ? $lineNoteRaw : (string) ($prev['lineNote'] ?? ''),
            ];
        }
        $newLines = array_values($byPid);
        try {
            CustomerCatalogUserState::patch($pdo, $cfv, $comp, ['carts' => [$bk => $newLines]]);
        } catch (Throwable) {
            return ['status' => '0', 'error' => 'Failed to save cart'];
        }
        if ($newLines !== []) {
            $verify = CustomerCatalogUserState::get($pdo, $cfv, $comp);
            if (self::linesForBucket($verify, $pdo, $cfv) === []) {
                return ['status' => '0', 'error' => 'Cart not persisted'];
            }
        }

        return ['status' => '1'];
    }

    /** @return array{status:string,error?:string} */
    public static function deleteByProductId(\PDO $pdo, array $session, int $productId): array
    {
        if (!self::uses($session) || $productId <= 0) {
            return ['status' => '0', 'error' => 'Invalid'];
        }

        return self::setLine($pdo, $session, $productId, 0.0, []);
    }

    /**
     * cartId en API = productId para clientes (JSON sin id de fila SQL).
     *
     * @return array{status:string,error?:string}
     */
    public static function deleteByLineId(\PDO $pdo, array $session, int $lineId): array
    {
        return self::deleteByProductId($pdo, $session, $lineId);
    }

    /** @return array{status:string,error?:string} */
    public static function clearAll(\PDO $pdo, array $session): array
    {
        if (!self::uses($session)) {
            return ['status' => '0', 'error' => 'Invalid session'];
        }
        try {
            CustomerCatalogUserState::patch($pdo, self::customerFvId($session), self::companyId($session), ['carts' => []]);
            $uids = self::portalTableUserIds($session);
            if ($uids !== []) {
                $ph = implode(',', array_fill(0, count($uids), '?'));
                $pdo->prepare("DELETE FROM `portal_cart_item` WHERE `userId` IN ($ph)")->execute($uids);
                foreach ($uids as $u) {
                    $pdo->prepare('DELETE FROM `portal_cart_header` WHERE `userId` = ?')->execute([$u]);
                }
            }
        } catch (Throwable) {
            return ['status' => '0', 'error' => 'Failed to clear cart'];
        }

        return ['status' => '1'];
    }
}
