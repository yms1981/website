<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
/** Solo rutas HTML: no en API (p. ej. logout) para no emitir cookies antes de destroySession. */
Auth::syncLoggedInIndicatorCookie();

$path = isset($_GET['__path']) ? (string) $_GET['__path'] : '';
$path = trim($path, '/');
$segments = $path === '' ? [] : explode('/', $path);

if (count($segments) === 0) {
    header('Location: ' . base_url() . '/en', true, 302);
    exit;
}

$lang = $segments[0];
if (!in_array($lang, ['en', 'es'], true)) {
    http_response_code(404);
    $dict = load_dictionary('en');
    $lang = 'en';
    require __DIR__ . '/views/errors/404.php';
    exit;
}

$dict = load_dictionary($lang);
$GLOBALS['hv_lang'] = $lang;

$rest = array_slice($segments, 1);

try {
    if (count($rest) === 0) {
        $productsJson = json_encode([], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
        $categoriesJson = json_encode([], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
        $showPrice = false;
        $showFvWarning = !fullvendor_configured();
        $deferHomeCatalog = true;
        require __DIR__ . '/views/home.php';
        exit;
    }

    $route = $rest[0];

    if ($route === 'contact') {
        require __DIR__ . '/views/contact.php';
        exit;
    }
    if ($route === 'login') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            require_once __DIR__ . '/lib/WebLogin.php';
            $emailPost = (string) ($_POST['email'] ?? '');
            $passwordPost = (string) ($_POST['password'] ?? '');
            $fromPost = (string) ($_POST['from'] ?? '');
            $loginResult = WebLogin::attempt($emailPost, $passwordPost, $lang);
            if (!$loginResult['success']) {
                $_SESSION['hv_login_error'] = 'invalid';
                $_SESSION['hv_login_old_email'] = $emailPost;
                $failUrl = base_url() . '/' . $lang . '/login';
                if ($fromPost !== '') {
                    $failUrl .= '?' . http_build_query(['from' => $fromPost]);
                }
                header('Location: ' . $failUrl, true, 302);
                exit;
            }
            if (!empty($loginResult['pending'])) {
                header('Location: ' . base_url() . '/' . $lang . '/login?pending=1', true, 302);
                exit;
            }
            if ($fromPost !== '' && str_starts_with($fromPost, '/') && !str_starts_with($fromPost, '//')) {
                $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                header('Location: ' . ($https ? 'https' : 'http') . '://' . $host . $fromPost, true, 302);
                exit;
            }
            header('Location: ' . base_url() . '/' . $lang . '/account/catalog', true, 302);
            exit;
        }
        require __DIR__ . '/views/login.php';
        exit;
    }
    if ($route === 'logout') {
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        Auth::destroySession();
        header('Location: ' . base_url() . '/' . $lang . '?hv_clear_cart=1&_=' . time(), true, 302);
        exit;
    }
    if ($route === 'register') {
        require __DIR__ . '/views/register.php';
        exit;
    }
    if ($route === 'cart') {
        require __DIR__ . '/views/cart.php';
        exit;
    }

    if ($route === 'products' && isset($rest[1]) && ctype_digit((string) $rest[1])) {
        if (!fullvendor_configured()) {
            http_response_code(503);
            require __DIR__ . '/views/errors/service-unavailable.php';
            exit;
        }
        $productId = (int) $rest[1];
        $res = FullVendor::getProductDetails($productId, lang_to_id($lang));
        $product = $res['details'] ?? $res['info'] ?? null;
        if (($res['status'] ?? '') !== '1' || !is_array($product)) {
            http_response_code(404);
            require __DIR__ . '/views/errors/product-not-found.php';
            exit;
        }
        $session = Auth::getSession();
        if ($session === null || empty($session['approved'])) {
            unset($product['sale_price'], $product['sale_price0'], $product['fob_price'], $product['purchase_price']);
        }
        $relatedProducts = FullVendor::getRelatedProducts(
            $productId,
            (string) ($product['category_id'] ?? ''),
            lang_to_id($lang),
            24
        );
        if ($session === null || empty($session['approved'])) {
            $relatedProducts = strip_prices($relatedProducts);
        }
        require __DIR__ . '/views/product.php';
        exit;
    }

    if ($route === 'account') {
        require_account_session($lang, true);
        $sub = $rest[1] ?? 'catalog';
        if ($sub === 'catalog') {
            $lid = lang_to_id($lang);
            $rawProducts = CatalogCache::getProducts($lid);
            $categories = CatalogCache::getCategories($lid);
            $products = slim_products($rawProducts);
            $productsJson = json_encode($products, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
            $categoriesJson = json_encode($categories, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
            $showPrice = true;
            $showFvWarning = !fullvendor_configured();
            require __DIR__ . '/views/account/catalog.php';
            exit;
        }
        if ($sub === 'cart-db') {
            require __DIR__ . '/views/account/cart-db.php';
            exit;
        }
        if ($sub === 'cart') {
            require __DIR__ . '/views/account/cart.php';
            exit;
        }
        if ($sub === 'orders') {
            if (isset($rest[2]) && ctype_digit((string) $rest[2])) {
                $hv_order_detail_id = (int) $rest[2];
                require __DIR__ . '/views/account/order-detail.php';
                exit;
            }
            require __DIR__ . '/views/account/orders.php';
            exit;
        }
        if ($sub === 'invoices') {
            require __DIR__ . '/views/account/invoices.php';
            exit;
        }
        if ($sub === 'messages') {
            if (isset($rest[2]) && $rest[2] === 'file') {
                require __DIR__ . '/views/account/messages-file.php';
                exit;
            }
            require __DIR__ . '/views/account/messages.php';
            exit;
        }
    }

    if ($route === 'admin') {
        require_admin_html($lang);
        require __DIR__ . '/views/admin.php';
        exit;
    }
} catch (Throwable $e) {
    error_log('[index] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (class_exists('AppLog', false)) {
        AppLog::appException($e, 'index');
    }
    http_response_code(500);
    if (function_exists('app_debug') && app_debug()) {
        echo '<pre style="white-space:pre-wrap;padding:1rem;font-family:system-ui,sans-serif;">'
            . htmlspecialchars($e->getMessage() . "\n\n" . $e->getTraceAsString())
            . '</pre>';
    } else {
        echo '<p>Server error. Copia <code>.env.example</code> a <code>.env</code>, rellena FULLVENDOR_* y JWT_SECRET, o activa <code>APP_DEBUG=1</code> en .env para ver el detalle.</p>';
    }
    exit;
}

http_response_code(404);
require __DIR__ . '/views/errors/404.php';
