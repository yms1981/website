<?php

declare(strict_types=1);

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function json_response(array $data, int $code = 200): void
{
    if (defined('HV_API_REQUEST') && HV_API_REQUEST && class_exists('ApiLogger', false)) {
        ApiLogger::logResponse($code, $data);
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if (defined('HV_API_REQUEST') && HV_API_REQUEST && class_exists('ApiLogger', false)) {
        ApiLogger::logJsonBody($raw);
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

/** @param array<int, array<string, mixed>> $products */
function strip_prices(array $products): array
{
    $out = [];
    foreach ($products as $p) {
        unset($p['sale_price'], $p['sale_price0'], $p['fob_price'], $p['purchase_price']);
        $out[] = $p;
    }

    return $out;
}

/**
 * Filtra productos que comparten al menos un category_id (CSV) con la lista deseada; conserva el orden del array origen.
 *
 * @param array<int, array<string, mixed>> $allProducts
 * @return array<int, array<string, mixed>>
 */
function hv_filter_related_products(array $allProducts, int $excludeProductId, string $categoryIdCsv, int $limit): array
{
    $want = [];
    foreach (explode(',', $categoryIdCsv) as $x) {
        $x = trim((string) $x);
        if ($x !== '') {
            $want[$x] = true;
        }
    }
    if ($want === [] || $allProducts === []) {
        return [];
    }
    $limit = max(1, min(80, $limit));
    $out = [];
    foreach ($allProducts as $p) {
        if (!is_array($p)) {
            continue;
        }
        if ((int) ($p['product_id'] ?? 0) === $excludeProductId) {
            continue;
        }
        $match = false;
        foreach (explode(',', (string) ($p['category_id'] ?? '')) as $c) {
            $c = trim($c);
            if ($c !== '' && isset($want[$c])) {
                $match = true;
                break;
            }
        }
        if (!$match) {
            continue;
        }
        $out[] = $p;
        if (count($out) >= $limit) {
            break;
        }
    }

    return $out;
}

/** @param array<int, array<string, mixed>> $products */
function slim_products(array $products): array
{
    $omit = ['requested', 'barcode', 'stock', 'total_order', 'force_moq', 'catalog_id', 'lblstock'];
    $out = [];
    foreach ($products as $p) {
        foreach ($omit as $k) {
            unset($p[$k]);
        }
        if (!empty($p['images']) && is_array($p['images']) && count($p['images']) > 1) {
            $images = $p['images'];
            $best = null;
            foreach ($images as $img) {
                if (isset($img['img_default']) && (int) $img['img_default'] === 1) {
                    $best = $img;
                    break;
                }
            }
            if ($best === null) {
                usort($images, static fn ($a, $b) => ((int) ($a['img_order'] ?? 999)) <=> ((int) ($b['img_order'] ?? 999)));
                $best = $images[0] ?? null;
            }
            $p['images'] = $best ? [$best] : [$images[0]];
        }
        $out[] = $p;
    }

    return $out;
}

function get_product_image(array $product): string
{
    $images = $product['images'] ?? [];
    if (!is_array($images) || count($images) === 0) {
        return 'https://app.fullvendor.com/uploads/noimg.png';
    }
    foreach ($images as $img) {
        if (isset($img['img_default']) && (int) $img['img_default'] === 1 && !empty($img['pic'])) {
            return (string) $img['pic'];
        }
    }
    usort($images, static fn ($a, $b) => ((int) ($a['img_order'] ?? 999)) <=> ((int) ($b['img_order'] ?? 999)));

    return !empty($images[0]['pic']) ? (string) $images[0]['pic'] : 'https://app.fullvendor.com/uploads/noimg.png';
}

function parse_tags(?string $tags): array
{
    if (!$tags) {
        return [];
    }

    return array_filter(array_map('trim', explode(',', $tags)));
}

function lang_to_id(string $lang): string
{
    return $lang === 'es' ? '2' : '1';
}

function format_price(float $price, string $lang): string
{
    if (extension_loaded('intl')) {
        $locale = $lang === 'es' ? 'es_US' : 'en_US';
        $fmt = numfmt_create($locale, NumberFormatter::CURRENCY);
        if ($fmt !== false) {
            return numfmt_format_currency($fmt, $price, 'USD');
        }
    }

    return '$' . number_format($price, 2);
}

function load_dictionary(string $locale): array
{
    $file = __DIR__ . '/../lang/' . ($locale === 'es' ? 'es' : 'en') . '.json';
    if (!is_readable($file)) {
        $file = __DIR__ . '/../lang/en.json';
    }
    $json = file_get_contents($file);
    $data = json_decode($json ?: '{}', true);

    return is_array($data) ? $data : [];
}

/** @return array{userId:int,companyId:int,customerId:int,email:string,name:string,approved:bool,rolId:int} */
function require_account_session(string $lang, bool $requireApproved = true): array
{
    $session = Auth::getSession();
    if ($session === null) {
        $from = urlencode($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: ' . base_url() . '/' . $lang . '/login?from=' . $from);
        exit;
    }
    if ($requireApproved && empty($session['approved'])) {
        header('Location: ' . base_url() . '/' . $lang . '/login?pending=1');
        exit;
    }

    return $session;
}

/** @return array{userId:int,companyId:int,customerId:int,email:string,name:string,approved:bool} */
function require_admin_session(): array
{
    $session = Auth::getSession();
    $admin = strtolower(trim(config('ADMIN_EMAIL', '')));
    $email = strtolower(trim((string) ($session['email'] ?? '')));
    if ($session === null || $email === '' || $email !== $admin) {
        json_response(['error' => 'Unauthorized'], 401);
    }

    return $session;
}

function require_admin_html(string $lang): array
{
    $session = Auth::getSession();
    $admin = strtolower(trim(config('ADMIN_EMAIL', '')));
    $email = strtolower(trim((string) ($session['email'] ?? '')));
    if ($session === null || $email === '' || $email !== $admin) {
        header('Location: ' . base_url() . '/' . $lang);
        exit;
    }

    return $session;
}

/**
 * Normaliza una fila de categoryList (FullVendor): `images` siempre URL string.
 *
 * @param array<string, mixed> $cat
 * @return array<string, string>
 */
function hv_normalize_category(array $cat): array
{
    $images = $cat['images'] ?? '';
    if (is_array($images)) {
        if (isset($images['pic'])) {
            $images = (string) $images['pic'];
        } elseif (isset($images[0]) && is_array($images[0]) && !empty($images[0]['pic'])) {
            $images = (string) $images[0]['pic'];
        } else {
            $images = '';
        }
    }
    $images = is_string($images) ? trim($images) : '';
    $catId = (string) ($cat['cat_id'] ?? $cat['category_id'] ?? '');

    return [
        'order_id' => (string) ($cat['order_id'] ?? ''),
        'category_id' => $catId,
        'company_id' => (string) ($cat['company_id'] ?? ''),
        'cat_id' => $catId,
        'language_id' => (string) ($cat['language_id'] ?? '1'),
        'category_status' => (string) ($cat['category_status'] ?? ''),
        'category_created_at' => (string) ($cat['category_created_at'] ?? ''),
        'id_kor' => (string) ($cat['id_kor'] ?? ''),
        'category_name' => (string) ($cat['category_name'] ?? ''),
        'images' => $images,
    ];
}
