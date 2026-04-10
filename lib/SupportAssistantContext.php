<?php

declare(strict_types=1);

/**
 * Datos en vivo para el asistente de soporte (cliente rol 3): rutas, categorías, pedidos, saldo local, inventario.
 */
final class SupportAssistantContext
{
    /**
     * @param array<string, mixed> $session Sesión aprobada rol 3
     *
     * @return array<string, mixed>
     */
    public static function build(array $session, string $lang): array
    {
        $prefix = rtrim(base_url(), '/') . '/' . $lang;
        $fvCustomerId = (int) ($session['customerId'] ?? 0);
        $lid = lang_to_id($lang);

        $out = [
            'routes' => [
                'catalog' => $prefix . '/account/catalog',
                'cart_db' => $prefix . '/account/cart-db',
                'cart_legacy' => $prefix . '/account/cart',
                'orders' => $prefix . '/account/orders',
                'invoices' => $prefix . '/account/invoices',
                'messages' => $prefix . '/account/messages',
            ],
            'show_inventory' => true,
            'orders_from_db' => false,
            'orders_count' => 0,
            'orders_preview' => [],
            'customer_snapshot' => null,
            'categories' => [],
            'catalog_configured' => fullvendor_configured(),
        ];

        if (fullvendor_db_configured()) {
            require_once __DIR__ . '/FullVendorDb.php';
            $out['show_inventory'] = FullVendorDb::companyShowsProductInventory();
        }

        if ($out['catalog_configured']) {
            require_once __DIR__ . '/CatalogCache.php';
            $cats = CatalogCache::getCategories($lid);
            foreach (array_slice($cats, 0, 120) as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $catId = trim((string) ($c['category_id'] ?? $c['cat_id'] ?? ''));
                $name = trim((string) ($c['category_name'] ?? ''));
                if ($catId === '' || $name === '') {
                    continue;
                }
                $out['categories'][] = ['id' => $catId, 'name' => $name];
            }
        }

        if (fullvendor_db_configured() && $fvCustomerId > 0) {
            require_once __DIR__ . '/FullVendorOrdersDb.php';
            $list = FullVendorOrdersDb::listForAccount(3, 0, $fvCustomerId, $lang);
            $out['orders_from_db'] = true;
            $out['orders_count'] = count($list);
            foreach (array_slice($list, 0, 20) as $o) {
                if (!is_array($o)) {
                    continue;
                }
                $out['orders_preview'][] = [
                    'order_id' => (int) ($o['order_id'] ?? $o['id'] ?? 0),
                    'order_number' => (string) ($o['order_number'] ?? ''),
                    'order_date' => (string) ($o['order_date'] ?? $o['date'] ?? $o['created'] ?? ''),
                    'status_label' => (string) ($o['status_label'] ?? $o['status'] ?? ''),
                    'total' => (string) ($o['total'] ?? ''),
                ];
            }
        }

        if (class_exists('Db', false) && Db::enabled() && $fvCustomerId > 0) {
            try {
                $pdo = Db::pdo();
                $st = $pdo->prepare(
                    'SELECT `balance`, `term_name`, `group_name`, `discount`, `name`, `business_name` '
                    . 'FROM `customers` WHERE `customeridfullvendor` = ? OR `customer_id` = ? LIMIT 1'
                );
                $st->execute([$fvCustomerId, $fvCustomerId]);
                $row = $st->fetch();
                if (is_array($row)) {
                    $out['customer_snapshot'] = [
                        'balance' => isset($row['balance']) ? round((float) $row['balance'], 2) : null,
                        'term_name' => trim((string) ($row['term_name'] ?? '')),
                        'group_name' => trim((string) ($row['group_name'] ?? '')),
                        'discount' => isset($row['discount']) ? round((float) $row['discount'], 2) : null,
                        'name' => trim((string) ($row['name'] ?? '')),
                        'business_name' => trim((string) ($row['business_name'] ?? '')),
                    ];
                }
            } catch (Throwable) {
                // sin snapshot
            }
        }

        return $out;
    }
}
