<?php

declare(strict_types=1);

/**
 * Datos para la barra de perfil según rolId y tablas usersList / customers.
 * rolId 2 = Seller (usersList), 3 = Customer (customers), 1 = admin.
 */
final class UserProfile
{
    /**
     * @return array{
     *   rolId:int,
     *   roleKey:string,
     *   roleLabel:string,
     *   fields:list<array{key:string,label:string,value:string}>
     * }
     */
    public static function barPayloadForEmail(string $email, string $lang): array
    {
        $emailNorm = strtolower(trim($email));
        $isEs = $lang === 'es';
        $empty = [
            'rolId' => 0,
            'roleKey' => 'unknown',
            'roleLabel' => '',
            'fields' => [],
        ];

        if ($emailNorm === '' || !Db::enabled()) {
            return $empty;
        }

        $dict = load_dictionary($lang);
        $p = $dict['profile'] ?? [];

        $st = Db::pdo()->prepare(
            'SELECT `userId`, `rolId`, `customerId` FROM `users` WHERE LOWER(TRIM(`username`)) = ? LIMIT 1'
        );
        $st->execute([$emailNorm]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($u)) {
            return $empty;
        }

        $rolId = (int) ($u['rolId'] ?? 0);
        $linkId = (int) ($u['customerId'] ?? 0);
        $localUserId = (int) ($u['userId'] ?? 0);

        if ($rolId === 1) {
            return [
                'rolId' => 1,
                'roleKey' => 'admin',
                'roleLabel' => (string) ($p['role_admin'] ?? ($isEs ? 'Administrador' : 'Administrator')),
                'fields' => [],
            ];
        }

        if ($rolId === 2) {
            require_once __DIR__ . '/CartSeller.php';
            $listUserId = $localUserId > 0 ? CartSeller::resolveSellerId(Db::pdo(), $localUserId) : 0;
            if ($listUserId <= 0 && $linkId > 0) {
                $listUserId = $linkId;
            }
            if ($listUserId <= 0) {
                return [
                    'rolId' => 2,
                    'roleKey' => 'seller',
                    'roleLabel' => (string) ($p['role_seller'] ?? ($isEs ? 'Vendedor' : 'Seller')),
                    'fields' => [],
                ];
            }
            $sl = Db::pdo()->prepare(
                'SELECT `first_name`, `last_name` FROM `usersList` WHERE `user_id` = ? LIMIT 1'
            );
            $sl->execute([$listUserId]);
            $row = $sl->fetch(PDO::FETCH_ASSOC);
            $fn = is_array($row) ? trim((string) ($row['first_name'] ?? '')) : '';
            $ln = is_array($row) ? trim((string) ($row['last_name'] ?? '')) : '';

            return [
                'rolId' => 2,
                'roleKey' => 'seller',
                'roleLabel' => (string) ($p['role_seller'] ?? ($isEs ? 'Vendedor' : 'Seller')),
                'fields' => [
                    [
                        'key' => 'first_name',
                        'label' => (string) ($p['first_name'] ?? ($isEs ? 'Nombre' : 'First name')),
                        'value' => $fn !== '' ? $fn : '—',
                    ],
                    [
                        'key' => 'last_name',
                        'label' => (string) ($p['last_name'] ?? ($isEs ? 'Apellido' : 'Last name')),
                        'value' => $ln !== '' ? $ln : '—',
                    ],
                ],
            ];
        }

        if ($rolId === 3 && $linkId > 0) {
            $sc = Db::pdo()->prepare(
                'SELECT `business_name`, `name` FROM `customers`
                 WHERE `customeridfullvendor` = ? OR `customer_id` = ? LIMIT 1'
            );
            $sc->execute([$linkId, $linkId]);
            $row = $sc->fetch(PDO::FETCH_ASSOC);
            $bn = is_array($row) ? trim((string) ($row['business_name'] ?? '')) : '';
            $nm = is_array($row) ? trim((string) ($row['name'] ?? '')) : '';

            return [
                'rolId' => 3,
                'roleKey' => 'customer',
                'roleLabel' => (string) ($p['role_customer'] ?? ($isEs ? 'Cliente' : 'Customer')),
                'fields' => [
                    [
                        'key' => 'business_name',
                        'label' => (string) ($p['business_name'] ?? ($isEs ? 'Empresa' : 'Business name')),
                        'value' => $bn !== '' ? $bn : '—',
                    ],
                    [
                        'key' => 'contact_name',
                        'label' => (string) ($p['contact_name'] ?? ($isEs ? 'Nombre de contacto' : 'Contact name')),
                        'value' => $nm !== '' ? $nm : '—',
                    ],
                ],
            ];
        }

        return [
            'rolId' => $rolId,
            'roleKey' => 'unknown',
            'roleLabel' => '',
            'fields' => [],
        ];
    }

    /**
     * Datos mínimos para el saludo del catálogo: admin solo email en sesión;
     * rol 2 → tabla `vendors` en BD FullVendor por email (first_name, last_name en mayúsculas); fallback usersList;
     * rol 3 → tabla local `customers` por email (business_name, name); fallback por customerId.
     *
     * $sessionRolId: si es > 0 (p. ej. JWT), define qué ramas de datos usar; evita mostrar cliente
     * cuando la sesión es vendedor pero la fila en `users` aún tiene otro rolId desactualizado.
     *
     * @return array{rolId:int,firstName:string,lastName:string,customerName:string,businessName:string,customerLine:string}
     */
    public static function catalogGreetingData(string $email, ?int $sessionRolId = null): array
    {
        $emailNorm = strtolower(trim($email));
        $out = [
            'rolId' => 0,
            'firstName' => '',
            'lastName' => '',
            'customerName' => '',
            'businessName' => '',
            'customerLine' => '',
        ];

        if ($emailNorm === '' || !Db::enabled()) {
            return $out;
        }

        $st = Db::pdo()->prepare(
            'SELECT `userId`, `rolId`, `customerId` FROM `users` WHERE LOWER(TRIM(`username`)) = ? LIMIT 1'
        );
        $st->execute([$emailNorm]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($u)) {
            return $out;
        }

        $dbRol = (int) ($u['rolId'] ?? 0);
        $rolId = ($sessionRolId !== null && $sessionRolId > 0) ? $sessionRolId : $dbRol;
        $linkId = (int) ($u['customerId'] ?? 0);
        $localUserId = (int) ($u['userId'] ?? 0);
        $out['rolId'] = $rolId;

        if ($rolId === 1) {
            return $out;
        }

        if ($rolId === 2) {
            // Solo vendedores: tabla `vendors` en BD FullVendor (no `customers`).
            $fvOk = false;
            try {
                require_once __DIR__ . '/FullVendorDb.php';
                $vrow = FullVendorDb::vendorNamesByEmail($emailNorm);
                if (is_array($vrow)) {
                    $fn = trim((string) ($vrow['first_name'] ?? ''));
                    $ln = trim((string) ($vrow['last_name'] ?? ''));
                    if ($fn !== '' || $ln !== '') {
                        $out['firstName'] = mb_strtoupper($fn, 'UTF-8');
                        $out['lastName'] = mb_strtoupper($ln, 'UTF-8');
                        $fvOk = true;
                    }
                }
            } catch (Throwable $e) {
                $fvOk = false;
            }
            if (!$fvOk) {
                require_once __DIR__ . '/CartSeller.php';
                $listUserId = $localUserId > 0 ? CartSeller::resolveSellerId(Db::pdo(), $localUserId) : 0;
                if ($listUserId <= 0 && $linkId > 0) {
                    $listUserId = $linkId;
                }
                if ($listUserId > 0) {
                    $sl = Db::pdo()->prepare(
                        'SELECT `first_name`, `last_name` FROM `usersList` WHERE `user_id` = ? LIMIT 1'
                    );
                    $sl->execute([$listUserId]);
                    $row = $sl->fetch(PDO::FETCH_ASSOC);
                    if (is_array($row)) {
                        $fn = trim((string) ($row['first_name'] ?? ''));
                        $ln = trim((string) ($row['last_name'] ?? ''));
                        $out['firstName'] = mb_strtoupper($fn, 'UTF-8');
                        $out['lastName'] = mb_strtoupper($ln, 'UTF-8');
                    }
                }
            }

            return $out;
        }

        if ($rolId === 3) {
            // Solo tabla local `customers` (nunca vendors). Por email; si falta, por customerId.
            $sc = Db::pdo()->prepare(
                'SELECT `business_name`, `name` FROM `customers` WHERE LOWER(TRIM(`email`)) = ? LIMIT 1'
            );
            $sc->execute([$emailNorm]);
            $row = $sc->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $out['businessName'] = trim((string) ($row['business_name'] ?? ''));
                $out['customerName'] = trim((string) ($row['name'] ?? ''));
            }
            if (($out['businessName'] === '' && $out['customerName'] === '') && $linkId > 0) {
                $sc2 = Db::pdo()->prepare(
                    'SELECT `business_name`, `name` FROM `customers`
                     WHERE `customeridfullvendor` = ? OR `customer_id` = ? LIMIT 1'
                );
                $sc2->execute([$linkId, $linkId]);
                $row2 = $sc2->fetch(PDO::FETCH_ASSOC);
                if (is_array($row2)) {
                    $out['businessName'] = trim((string) ($row2['business_name'] ?? ''));
                    $out['customerName'] = trim((string) ($row2['name'] ?? ''));
                }
            }
            $out['customerLine'] = self::customerCatalogDisplayLine($out['businessName'], $out['customerName']);

            return $out;
        }

        return $out;
    }

    /**
     * Texto del saludo cliente: business_name + name, sin duplicar si vienen iguales desde FullVendor.
     */
    private static function customerCatalogDisplayLine(string $businessName, string $contactName): string
    {
        $bn = trim($businessName);
        $nm = trim($contactName);
        if ($bn === '' && $nm === '') {
            return '';
        }
        if ($bn === '' || $nm === '') {
            return $bn !== '' ? $bn : $nm;
        }
        if (mb_strtolower($bn) === mb_strtolower($nm)) {
            return $bn;
        }

        return trim($bn . ' ' . $nm);
    }
}
