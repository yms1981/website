<?php

declare(strict_types=1);

/**
 * Carrito persistido solo en MySQL local (CustomerCatalogCart / PortalCart) o fallback FullVendor.
 * Usado por /api/cart y por la ruta HTML /{lang}/account/cart-db (misma cookie de sesión, sin reglas .htaccess de /api).
 */
final class LocalCart
{
    public static function dispatch(): void
    {
        $session = Auth::getSession();
        if ($session === null || empty($session['approved'])) {
            json_response(['error' => 'Unauthorized'], 401);
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        require_once __DIR__ . '/CustomerCatalogCart.php';
        require_once __DIR__ . '/PortalCart.php';

        if ($method === 'GET') {
            if (Db::enabled() && CustomerCatalogCart::uses($session)) {
                json_response(['items' => CustomerCatalogCart::apiItems(Db::pdo(), $session)]);
            }
            if (Db::enabled() && PortalCart::usesPortalCart($session)) {
                json_response(['items' => PortalCart::apiItems(Db::pdo(), $session)]);
            }
            api_require_fullvendor();
            $lang = $_GET['lang'] ?? 'en';
            $res = FullVendor::getCart($session['userId'], lang_to_id($lang));
            $list = $res['list'] ?? [];
            json_response(['items' => is_array($list) ? $list : []]);
        }

        if ($method === 'POST') {
            $b = read_json_body();
            $pid = (int) ($b['productId'] ?? 0);
            $qtyRaw = $b['qty'] ?? 0;
            $qty = is_numeric($qtyRaw) ? (float) $qtyRaw : 0.0;
            $lang = (string) ($b['lang'] ?? 'en');
            if (Db::enabled() && CustomerCatalogCart::uses($session)) {
                if ($pid <= 0 || $qty < 0) {
                    json_response(['error' => 'Invalid product or quantity'], 400);
                }
                $meta = [
                    'name' => (string) ($b['name'] ?? ''),
                    'sku' => (string) ($b['sku'] ?? ''),
                    'image' => (string) ($b['image'] ?? ''),
                    'moq' => (int) ($b['moq'] ?? 1),
                    'sale_price' => isset($b['sale_price']) ? (float) $b['sale_price'] : (isset($b['unitPrice']) ? (float) $b['unitPrice'] : 0.0),
                    'fob_price' => isset($b['fob_price']) ? (float) $b['fob_price'] : 0.0,
                    'lineNote' => (string) ($b['lineNote'] ?? $b['line_note'] ?? ''),
                ];
                json_response(CustomerCatalogCart::setLine(Db::pdo(), $session, $pid, $qty, $meta));
            }
            if (Db::enabled() && PortalCart::usesPortalCart($session)) {
                if ($pid <= 0 || $qty < 0) {
                    json_response(['error' => 'Invalid product or quantity'], 400);
                }
                $meta = [
                    'name' => (string) ($b['name'] ?? ''),
                    'sku' => (string) ($b['sku'] ?? ''),
                    'image' => (string) ($b['image'] ?? ''),
                    'moq' => (int) ($b['moq'] ?? 1),
                    'sale_price' => isset($b['sale_price']) ? (float) $b['sale_price'] : (isset($b['unitPrice']) ? (float) $b['unitPrice'] : 0.0),
                    'fob_price' => isset($b['fob_price']) ? (float) $b['fob_price'] : 0.0,
                    'lineNote' => (string) ($b['lineNote'] ?? $b['line_note'] ?? ''),
                ];
                json_response(PortalCart::setLine(Db::pdo(), $session, $pid, $qty, $meta));
            }
            api_require_fullvendor();
            $qtyInt = (int) $qty;
            if ($pid <= 0 || $qtyInt <= 0) {
                json_response(['error' => 'Invalid product or quantity'], 400);
            }
            json_response(FullVendor::addToCart($session['userId'], $pid, $qtyInt, lang_to_id($lang)));
        }

        if ($method === 'DELETE') {
            $b = read_json_body();
            if (Db::enabled() && CustomerCatalogCart::uses($session)) {
                if (!empty($b['clearAll'])) {
                    json_response(CustomerCatalogCart::clearAll(Db::pdo(), $session));
                }
                $productIdDel = (int) ($b['productId'] ?? 0);
                if ($productIdDel > 0) {
                    json_response(CustomerCatalogCart::deleteByProductId(Db::pdo(), $session, $productIdDel));
                }
                $cartId = (int) ($b['cartId'] ?? 0);
                if ($cartId <= 0) {
                    json_response(['error' => 'Invalid cart id'], 400);
                }
                json_response(CustomerCatalogCart::deleteByLineId(Db::pdo(), $session, $cartId));
            }
            if (Db::enabled() && PortalCart::usesPortalCart($session)) {
                if (!empty($b['clearAll'])) {
                    json_response(PortalCart::clearAll(Db::pdo(), $session));
                }
                $productIdDel = (int) ($b['productId'] ?? 0);
                if ($productIdDel > 0) {
                    json_response(PortalCart::deleteByProductId(Db::pdo(), $session, $productIdDel));
                }
                $cartId = (int) ($b['cartId'] ?? 0);
                if ($cartId <= 0) {
                    json_response(['error' => 'Invalid cart id'], 400);
                }
                json_response(PortalCart::deleteByLineId(Db::pdo(), $session, $cartId));
            }
            api_require_fullvendor();
            if (!empty($b['clearAll'])) {
                json_response(FullVendor::clearCart($session['userId']));
            }
            $cartId = (int) ($b['cartId'] ?? 0);
            if ($cartId <= 0) {
                json_response(['error' => 'Invalid cart id'], 400);
            }
            json_response(FullVendor::removeFromCart($cartId));
        }

        json_response(['error' => 'Method not allowed'], 405);
    }
}
