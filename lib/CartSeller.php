<?php

declare(strict_types=1);

/**
 * Resuelve sellerId para cart_orders según el usuario logueado.
 * - rolId 3 (cliente): sellerId = users.customerId
 * - rolId 2 (vendedor): fila en customers por customerId del usuario; primer id numérico en customers.user_id (CSV)
 */
final class CartSeller
{
    public static function resolveSellerId(\PDO $pdo, int $userId): int
    {
        $st = $pdo->prepare('SELECT `rolId`, `customerId` FROM `users` WHERE `userId` = ? LIMIT 1');
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return 0;
        }
        $rol = (int) ($row['rolId'] ?? 0);
        $customerId = (int) ($row['customerId'] ?? 0);

        if ($rol === 3) {
            return $customerId;
        }
        if ($rol !== 2 || $customerId <= 0) {
            return 0;
        }

        $q = $pdo->prepare(
            'SELECT `user_id` FROM `customers` WHERE (`customeridfullvendor` = ? OR `customer_id` = ?) LIMIT 1'
        );
        $q->execute([$customerId, $customerId]);
        $c = $q->fetch(PDO::FETCH_ASSOC);
        if ($c === false) {
            return 0;
        }

        return self::firstCsvInt((string) ($c['user_id'] ?? ''));
    }

    /**
     * Primer userId de vendedor en `customers.user_id` (lista separada por comas) para un cliente mayorista.
     */
    public static function primarySellerUserIdForCustomer(\PDO $pdo, int $customerFvOrLocalId): int
    {
        if ($customerFvOrLocalId <= 0) {
            return 0;
        }
        $q = $pdo->prepare(
            'SELECT `user_id` FROM `customers` WHERE (`customeridfullvendor` = ? OR `customer_id` = ?) LIMIT 1'
        );
        $q->execute([$customerFvOrLocalId, $customerFvOrLocalId]);
        $c = $q->fetch(PDO::FETCH_ASSOC);
        if ($c === false) {
            return 0;
        }

        return self::firstCsvInt((string) ($c['user_id'] ?? ''));
    }

    private static function firstCsvInt(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }
        foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $part) {
            $part = trim((string) $part);
            if ($part !== '' && ctype_digit($part)) {
                return (int) $part;
            }
        }

        return 0;
    }
}
