<?php

declare(strict_types=1);

/**
 * Carrito en tablas `portal_cart_*` para usuarios no vendedor que no son cliente mayorista con customerId
 * (p. ej. admin rol 1). Los clientes rol 3 usan {@see CustomerCatalogCart} → `customer_catalog_user_state`.
 */
final class PortalCart
{
    public static function usesPortalCart(?array $session): bool
    {
        if (!Db::enabled() || $session === null || empty($session['approved'])) {
            return false;
        }
        if ((int) ($session['rolId'] ?? 0) === 2) {
            return false;
        }
        if ((int) ($session['rolId'] ?? 0) === 3 && (int) ($session['customerId'] ?? 0) > 0) {
            return false;
        }

        return true;
    }

    private static function portalUserId(array $session): int
    {
        $local = (int) ($session['localUserId'] ?? 0);
        if ($local > 0) {
            return $local;
        }

        return (int) ($session['userId'] ?? 0);
    }

    public static function upsertHeaderFromCustomer(\PDO $pdo, array $session): void
    {
        $uid = self::portalUserId($session);
        if ($uid <= 0) {
            return;
        }
        $cid = (int) ($session['customerId'] ?? 0);
        $discount = 0.0;
        $pop = 0.0;
        $ppa = 0.0;
        if ($cid > 0) {
            $st = $pdo->prepare(
                'SELECT `discount`, `percentage_on_price`, `percent_price_amount` FROM `customers`
                 WHERE `customeridfullvendor` = ? OR `customer_id` = ? LIMIT 1'
            );
            $st->execute([$cid, $cid]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($r)) {
                $discount = (float) ($r['discount'] ?? 0);
                $pop = (float) ($r['percentage_on_price'] ?? 0);
                $ppa = (float) ($r['percent_price_amount'] ?? 0);
            }
        }
        $ins = $pdo->prepare(
            'INSERT INTO `portal_cart_header` (`userId`, `customerId`, `discount`, `percentage_on_price`, `percent_price_amount`)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               `customerId` = VALUES(`customerId`),
               `discount` = VALUES(`discount`),
               `percentage_on_price` = VALUES(`percentage_on_price`),
               `percent_price_amount` = VALUES(`percent_price_amount`)'
        );
        $ins->execute([$uid, $cid, $discount, $pop, $ppa]);
    }

    /** @return list<array<string, mixed>> */
    public static function apiItems(\PDO $pdo, array $session): array
    {
        $uid = self::portalUserId($session);
        if ($uid <= 0) {
            return [];
        }
        self::upsertHeaderFromCustomer($pdo, $session);
        $st = $pdo->prepare(
            'SELECT `id`, `productId`, `qty`, `sales_price`, `fob_price`, `amount`, `product_name`, `sku`, `image`, `moq`, `line_note`
             FROM `portal_cart_item` WHERE `userId` = ? ORDER BY `id` ASC'
        );
        $st->execute([$uid]);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $pid = (int) $row['productId'];
            $qty = (float) $row['qty'];
            $sp = (float) $row['sales_price'];
            $out[] = [
                'product_id' => $pid,
                'productId' => $pid,
                'product_name' => (string) $row['product_name'],
                'name' => (string) $row['product_name'],
                'sku' => (string) $row['sku'],
                'qty' => $qty,
                'sale_price' => $sp,
                'price' => $sp,
                'cart_id' => (int) $row['id'],
                'cartId' => (int) $row['id'],
                'fob_price' => (float) $row['fob_price'],
                'image' => (string) $row['image'],
                'moq' => (int) $row['moq'],
                'line_note' => (string) ($row['line_note'] ?? ''),
                'lineNote' => (string) ($row['line_note'] ?? ''),
            ];
        }

        return $out;
    }

    /** @param array{name?:string,sku?:string,image?:string,moq?:int|float,sale_price?:float,unitPrice?:float,fob_price?:float,lineNote?:string,line_note?:string} $meta */
    public static function setLine(\PDO $pdo, array $session, int $productId, float $qty, array $meta): array
    {
        $uid = self::portalUserId($session);
        if ($uid <= 0) {
            return ['status' => '0', 'error' => 'Invalid session'];
        }
        $cid = (int) ($session['customerId'] ?? 0);
        $rol = (int) ($session['rolId'] ?? 0);
        self::upsertHeaderFromCustomer($pdo, $session);

        if ($productId <= 0 || $qty < 0) {
            return ['status' => '0', 'error' => 'Invalid product or quantity'];
        }

        if ($qty <= 0) {
            $del = $pdo->prepare('DELETE FROM `portal_cart_item` WHERE `userId` = ? AND `productId` = ?');
            $del->execute([$uid, $productId]);

            return ['status' => '1'];
        }

        $name = mb_substr((string) ($meta['name'] ?? ''), 0, 512);
        $sku = mb_substr((string) ($meta['sku'] ?? ''), 0, 255);
        $image = mb_substr((string) ($meta['image'] ?? ''), 0, 1024);
        $moq = max(1, (int) ($meta['moq'] ?? 1));
        $salesPrice = (float) ($meta['sale_price'] ?? $meta['unitPrice'] ?? 0);
        if (!is_finite($salesPrice) || $salesPrice < 0) {
            $salesPrice = 0.0;
        }
        $fob = (float) ($meta['fob_price'] ?? 0);
        if (!is_finite($fob) || $fob < 0) {
            $fob = 0.0;
        }
        $amount = $qty * $salesPrice;
        $lineNoteRaw = (string) ($meta['lineNote'] ?? $meta['line_note'] ?? '');
        if (strlen($lineNoteRaw) > 2000) {
            $lineNoteRaw = substr($lineNoteRaw, 0, 2000);
        }

        $sql = 'INSERT INTO `portal_cart_item` (`userId`, `customerId`, `rolId`, `productId`, `qty`, `sales_price`, `fob_price`, `amount`, `product_name`, `sku`, `image`, `moq`, `line_note`)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                  `qty` = VALUES(`qty`),
                  `sales_price` = VALUES(`sales_price`),
                  `fob_price` = VALUES(`fob_price`),
                  `amount` = VALUES(`amount`),
                  `product_name` = VALUES(`product_name`),
                  `sku` = VALUES(`sku`),
                  `image` = VALUES(`image`),
                  `moq` = VALUES(`moq`),
                  `line_note` = VALUES(`line_note`),
                  `customerId` = VALUES(`customerId`),
                  `rolId` = VALUES(`rolId`)';
        $st = $pdo->prepare($sql);
        $st->execute([$uid, $cid, $rol, $productId, $qty, $salesPrice, $fob, $amount, $name, $sku, $image, $moq, $lineNoteRaw === '' ? null : $lineNoteRaw]);

        return ['status' => '1'];
    }

    public static function deleteByLineId(\PDO $pdo, array $session, int $lineId): array
    {
        $uid = self::portalUserId($session);
        if ($uid <= 0 || $lineId <= 0) {
            return ['status' => '0', 'error' => 'Invalid'];
        }
        $st = $pdo->prepare('DELETE FROM `portal_cart_item` WHERE `id` = ? AND `userId` = ?');
        $st->execute([$lineId, $uid]);

        return ['status' => '1'];
    }

    public static function deleteByProductId(\PDO $pdo, array $session, int $productId): array
    {
        $uid = self::portalUserId($session);
        if ($uid <= 0 || $productId <= 0) {
            return ['status' => '0', 'error' => 'Invalid'];
        }
        $st = $pdo->prepare('DELETE FROM `portal_cart_item` WHERE `userId` = ? AND `productId` = ?');
        $st->execute([$uid, $productId]);

        return ['status' => '1'];
    }

    public static function clearAll(\PDO $pdo, array $session): array
    {
        $uid = self::portalUserId($session);
        if ($uid <= 0) {
            return ['status' => '0', 'error' => 'Invalid session'];
        }
        $pdo->prepare('DELETE FROM `portal_cart_item` WHERE `userId` = ?')->execute([$uid]);
        $pdo->prepare('DELETE FROM `portal_cart_header` WHERE `userId` = ?')->execute([$uid]);

        return ['status' => '1'];
    }
}
