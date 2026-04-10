<?php

declare(strict_types=1);

/**
 * Catálogo leyendo la BD MySQL de FullVendor, alineado con:
 * - categoriesList_post → list de categorías (cat_id como category_id, images, etc.)
 * - wcproductList_post → list de productos (galería desde product_images, sale_price>0, status=1)
 * - customer_group → grupos de precio (CustomerGroupsSync → tabla local `customer_groups`)
 * - order_categories → orden del catálogo (productList / wcproductList vía JOIN + FIND_IN_SET)
 * - rs_requested → líneas “requested” por cliente en productList (opcional)
 *
 * Imágenes públicas (recomendado en .env):
 * - FULLVENDOR_IMAGES_PRODUCTS_URL → base con company, ej. https://app.fullvendor.com/uploads/products/77/
 * - FULLVENDOR_IMAGES_CATEGORIES_URL → base categorías, ej. https://app.fullvendor.com/uploads/categories/77/
 * Si no existen, se usa FULLVENDOR_DB_FILES_BASE_URL + uploads/... o FULLVENDOR_BASE_URL.
 */
final class FullVendorDb
{
    private static ?\PDO $pdo = null;

    private static function ident(string $name): string
    {
        if ($name === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new InvalidArgumentException('Identificador SQL no válido: ' . $name);
        }

        return '`' . $name . '`';
    }

    /**
     * Nombre de tabla SQL (con prefijo) para lecturas desde la BD FullVendor.
     *
     * @param string $logical p. ej. categories, products, orders, order_details, customers
     */
    public static function sqlTable(string $logical): string
    {
        return self::table($logical);
    }

    /** @param string $logical clave lógica (categories, products, …, orders, order_details, customers) */
    private static function table(string $logical): string
    {
        $prefix = (string) config('FULLVENDOR_DB_TABLE_PREFIX', '');
        if ($prefix !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
            throw new InvalidArgumentException('FULLVENDOR_DB_TABLE_PREFIX no válido');
        }
        $defaults = [
            'categories' => 'categories',
            'products' => 'products',
            'catalog' => 'catalog',
            'product_images' => 'product_images',
            'companies' => 'companies',
            'customer_group' => 'customer_group',
            'order_categories' => 'order_categories',
            'rs_requested' => 'rs_requested',
            'vendors' => 'vendors',
            'orders' => 'orders',
            'order_details' => 'order_details',
            'customers' => 'customers',
            'status_orders' => 'status_orders',
        ];
        $envKey = 'FULLVENDOR_DB_TABLE_' . strtoupper($logical);
        $t = trim((string) config($envKey, $defaults[$logical] ?? $logical));
        if ($t === '') {
            throw new RuntimeException($envKey . ' no puede estar vacío');
        }

        return self::ident($prefix . $t);
    }

    public static function pdo(): \PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        if (!function_exists('fullvendor_db_configured') || !fullvendor_db_configured()) {
            throw new RuntimeException('Base de datos FullVendor no configurada (.env FULLVENDOR_DB_*)');
        }
        $host = (string) config('FULLVENDOR_DB_HOST', '127.0.0.1');
        $port = (int) config('FULLVENDOR_DB_PORT', '3306');
        if ($port < 1 || $port > 65535) {
            $port = 3306;
        }
        $name = (string) config('FULLVENDOR_DB_NAME', '');
        $user = (string) config('FULLVENDOR_DB_USER', '');
        $pass = (string) config('FULLVENDOR_DB_PASS', '');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);
        self::$pdo = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        return self::$pdo;
    }

    /**
     * Vendedor en la BD FullVendor: `first_name`, `last_name` por `email` (y `company_id` si está configurado).
     *
     * @return array{first_name:string,last_name:string}|null
     */
    public static function vendorNamesByEmail(string $email): ?array
    {
        if (!function_exists('fullvendor_db_configured') || !fullvendor_db_configured()) {
            return null;
        }
        $emailNorm = strtolower(trim($email));
        if ($emailNorm === '') {
            return null;
        }
        $pdo = self::pdo();
        $tv = self::table('vendors');
        $companyIdRaw = trim((string) config('FULLVENDOR_COMPANY_ID', ''));
        $sql = 'SELECT `first_name`, `last_name` FROM ' . $tv
            . ' WHERE LOWER(TRIM(`email`)) = :e';
        $params = ['e' => $emailNorm];
        if ($companyIdRaw !== '' && ctype_digit($companyIdRaw)) {
            $sql .= ' AND `company_id` = :cid';
            $params['cid'] = (int) $companyIdRaw;
        }
        $sql .= ' LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'first_name' => (string) ($row['first_name'] ?? ''),
            'last_name' => (string) ($row['last_name'] ?? ''),
        ];
    }

    /**
     * Vendedor en BD FullVendor por `user_id` (PK en tabla vendors), alineado con `users.customerId` del portal para rol 2.
     *
     * @return array{first_name:string,last_name:string}|null
     */
    public static function vendorNamesByUserId(int $vendorUserId): ?array
    {
        if ($vendorUserId <= 0 || !function_exists('fullvendor_db_configured') || !fullvendor_db_configured()) {
            return null;
        }
        $pdo = self::pdo();
        $tv = self::table('vendors');
        $companyIdRaw = trim((string) config('FULLVENDOR_COMPANY_ID', ''));
        $sql = 'SELECT `first_name`, `last_name` FROM ' . $tv . ' WHERE `user_id` = :uid';
        $params = ['uid' => $vendorUserId];
        if ($companyIdRaw !== '' && ctype_digit($companyIdRaw)) {
            $sql .= ' AND `company_id` = :cid';
            $params['cid'] = (int) $companyIdRaw;
        }
        $sql .= ' LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'first_name' => (string) ($row['first_name'] ?? ''),
            'last_name' => (string) ($row['last_name'] ?? ''),
        ];
    }

    private static function companyParam(): int|string
    {
        $cid = trim((string) config('FULLVENDOR_COMPANY_ID', ''));

        return ctype_digit($cid) ? (int) $cid : $cid;
    }

    /** Base pública para /uploads/... (barra final opcional). */
    private static function filesBaseUrl(): string
    {
        $u = rtrim((string) config('FULLVENDOR_DB_FILES_BASE_URL', ''), '/');
        if ($u === '') {
            $u = rtrim((string) config('FULLVENDOR_BASE_URL', ''), '/');
        }

        return $u === '' ? '' : $u . '/';
    }

    private static function joinPublicUrl(string $base, string $path): string
    {
        $b = rtrim(trim($base), '/');
        $p = ltrim(trim($path), '/');

        return $p === '' ? $b : $b . '/' . $p;
    }

    private static function noImageUrl(): string
    {
        $fb = self::filesBaseUrl();
        if ($fb !== '') {
            return $fb . 'images/noimg.png';
        }
        $root = rtrim((string) config('FULLVENDOR_BASE_URL', ''), '/');

        return $root !== '' ? $root . '/images/noimg.png' : '/images/noimg.png';
    }

    /**
     * URL absoluta para imagen de categoría (columna `images` = solo nombre de archivo o URL completa).
     */
    private static function categoryImagePublicUrl(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $raw)) {
            return $raw;
        }
        $base = trim((string) config('FULLVENDOR_IMAGES_CATEGORIES_URL', ''));
        if ($base !== '') {
            return self::joinPublicUrl($base, $raw);
        }
        $fb = self::filesBaseUrl();
        if ($fb !== '') {
            return self::joinPublicUrl($fb . 'uploads/categories/' . self::companyParam(), $raw);
        }

        return $raw;
    }

    /** imgURL / simgURL u otro path relativo → absoluto con FULLVENDOR_IMAGES_PRODUCTS_URL si aplica. */
    private static function absolutizeProductMediaUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^https?://#i', $url)) {
            return $url;
        }
        $pb = trim((string) config('FULLVENDOR_IMAGES_PRODUCTS_URL', ''));
        if ($pb !== '') {
            return self::joinPublicUrl($pb, $url);
        }
        $root = rtrim((string) config('FULLVENDOR_BASE_URL', ''), '/');

        return $root !== '' ? self::joinPublicUrl($root, ltrim($url, '/')) : $url;
    }

    private static function productPicUrl(string $filename, int|string $companyId): string
    {
        $fn = trim($filename);
        $baseProducts = trim((string) config('FULLVENDOR_IMAGES_PRODUCTS_URL', ''));
        if ($baseProducts !== '') {
            if ($fn === '') {
                return self::noImageUrl();
            }

            return self::joinPublicUrl($baseProducts, $fn);
        }
        if ($fn === '') {
            return self::noImageUrl();
        }
        $fb = self::filesBaseUrl();

        return $fb !== '' ? $fb . 'uploads/products/' . $companyId . '/' . $fn : $fn;
    }

    /** Misma URL que la galería del catálogo para un archivo en `product_images.images`. */
    public static function productImagePublicUrl(string $filename, int|string $companyId): string
    {
        return self::productPicUrl($filename, $companyId);
    }

    /**
     * catalog_id del catálogo "FullVendor Catalog" (como wcproductList_post).
     */
    private static function resolveCatalogId(\PDO $pdo): int
    {
        $catTable = self::table('catalog');
        $name = trim((string) config('FULLVENDOR_DB_CATALOG_NAME', 'FullVendor Catalog'));
        $sql = 'SELECT `catalog_id` FROM ' . $catTable
            . ' WHERE `catalog_name` = :n AND `company_id` = :c LIMIT 1';
        try {
            $st = $pdo->prepare($sql);
            $st->execute([':n' => $name, ':c' => self::companyParam()]);
            $v = $st->fetchColumn();
            if ($v !== false && $v !== null && is_numeric($v)) {
                return (int) $v;
            }
        } catch (Throwable) {
            /* tabla catalog distinta o ausente */
        }

        return 0;
    }

    /** Coincide con getSymbol típico de FullVendor (símbolo para currency_type). */
    private static function currencySymbol(string|int|null $code): string
    {
        $c = strtoupper(trim((string) $code));
        $map = [
            '1' => '$', '2' => '€', '3' => '£', 'USD' => '$', 'EUR' => '€', 'GBP' => '£',
            'MXN' => '$', 'CAD' => '$',
        ];

        return $map[$c] ?? (string) $code;
    }

    /** Expuesto para listados de pedidos u otros consumidores fuera de esta clase. */
    public static function currencyTypeSymbol(string|int|null $code): string
    {
        return self::currencySymbol($code);
    }

    private static function normalizedCompanyId(): string
    {
        return trim((string) config('FULLVENDOR_COMPANY_ID', ''));
    }

    /** Comprueba si existe la tabla de orden de categorías en el catálogo. */
    private static function hasOrderCategoriesTable(\PDO $pdo): bool
    {
        try {
            $oc = self::table('order_categories');
            $pdo->query('SELECT 1 FROM ' . $oc . ' LIMIT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Orden de category_id en el catálogo (menor `order` primero).
     *
     * @return array<string, int> category_id => posición 0-based
     */
    private static function categoryOrderIndexByCatalog(\PDO $pdo, int $catalogId, int|string $companyId): array
    {
        if ($catalogId <= 0 || !self::hasOrderCategoriesTable($pdo)) {
            return [];
        }
        try {
            $oc = self::table('order_categories');
            $st = $pdo->prepare(
                'SELECT `category_id`, `order` FROM ' . $oc
                    . ' WHERE `catalog_id` = :cat AND `company_id` = :cid ORDER BY `order` ASC, `category_id` ASC'
            );
            $st->execute([':cat' => $catalogId, ':cid' => $companyId]);
            $rows = $st->fetchAll();
            if (!is_array($rows)) {
                return [];
            }
            $map = [];
            $i = 0;
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $cid = (string) ($row['category_id'] ?? '');
                if ($cid === '') {
                    continue;
                }
                if (!isset($map[$cid])) {
                    $map[$cid] = $i;
                    $i++;
                }
            }

            return $map;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Lista de productos como en productList_post: JOIN order_categories + FIND_IN_SET, ORDER BY od.order, p.name.
     * Si catalog_id = 0, todos los activos con sale_price > 0 ordenados por nombre.
     *
     * @return list<array<string, mixed>>
     */
    private static function fetchProductRowsForList(
        \PDO $pdo,
        int $catalogId,
        string $languageId,
        ?string $categoryFilter,
        int|string $companyId
    ): array {
        $t = self::table('products');
        $hasCat = $categoryFilter !== null && $categoryFilter !== '';
        $catSqlInner = $hasCat ? ' AND FIND_IN_SET(:catf, p2.`category_id`)' : '';

        if ($catalogId > 0 && self::hasOrderCategoriesTable($pdo)) {
            $oc = self::table('order_categories');
            $sql = 'SELECT p.* FROM ('
                . ' SELECT p2.`product_id`, MIN(od.`order`) AS ord'
                . ' FROM ' . $oc . ' od'
                . ' INNER JOIN ' . $t . ' p2 ON FIND_IN_SET(od.`category_id`, p2.`category_id`)'
                . ' WHERE p2.`status` = 1 AND p2.`company_id` = :cid AND p2.`language_id` = :lid'
                . ' AND p2.`sale_price` > 0 AND od.`catalog_id` = :catalog AND od.`company_id` = :cid'
                . $catSqlInner
                . ' GROUP BY p2.`product_id`'
                . ' ) x'
                . ' INNER JOIN ' . $t . ' p ON p.`product_id` = x.`product_id`'
                . ' ORDER BY x.`ord` ASC, p.`name` ASC';
            try {
                $st = $pdo->prepare($sql);
                $exec = [':cid' => $companyId, ':lid' => $languageId, ':catalog' => $catalogId];
                if ($hasCat) {
                    $exec[':catf'] = $categoryFilter;
                }
                $st->execute($exec);
                $rows = $st->fetchAll();

                return is_array($rows) ? $rows : [];
            } catch (Throwable $e) {
                error_log('[FullVendorDb] fetchProductRowsForList catalog join: ' . $e->getMessage());
            }
        }

        $sql = 'SELECT * FROM ' . $t
            . ' WHERE `status` = 1 AND `company_id` = :cid AND `language_id` = :lid AND `sale_price` > 0';
        if ($hasCat) {
            $sql .= ' AND FIND_IN_SET(:catf, `category_id`)';
        }
        $sql .= ' ORDER BY `name` ASC';
        $st = $pdo->prepare($sql);
        $exec = [':cid' => $companyId, ':lid' => $languageId];
        if ($hasCat) {
            $exec[':catf'] = $categoryFilter;
        }
        $st->execute($exec);
        $rows = $st->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param list<int|string> $productIds
     * @return array<string, list<array{customer_id: mixed, qty: mixed, requested: mixed}>>
     */
    private static function requestedByProductIds(\PDO $pdo, int $customerId, array $productIds): array
    {
        if ($customerId <= 0 || $productIds === []) {
            return [];
        }
        try {
            $t = self::table('rs_requested');
        } catch (Throwable) {
            return [];
        }
        $productIds = array_values(array_unique(array_filter($productIds, static fn ($x) => $x !== '' && $x !== null)));
        if ($productIds === []) {
            return [];
        }
        try {
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $sql = 'SELECT `product_id`, `customer_id`, `qty`, `requested` FROM ' . $t
                . ' WHERE `customer_id` = ? AND `product_id` IN (' . $placeholders . ')';
            $st = $pdo->prepare($sql);
            $st->execute(array_merge([$customerId], $productIds));
            $rows = $st->fetchAll();
        } catch (Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $pid = (string) ($row['product_id'] ?? '');
            if ($pid === '') {
                continue;
            }
            $out[$pid][] = [
                'customer_id' => $row['customer_id'] ?? null,
                'qty' => $row['qty'] ?? null,
                'requested' => $row['requested'] ?? null,
            ];
        }

        return $out;
    }

    /** 1 = mostrar texto "Stock: …" en lblstock (companies.show_inventory). */
    private static function companyShowInventory(\PDO $pdo): bool
    {
        try {
            $co = self::table('companies');
            $st = $pdo->prepare('SELECT `show_inventory` FROM ' . $co . ' WHERE `company_id` = :c LIMIT 1');
            $st->execute([':c' => self::companyParam()]);
            $v = $st->fetchColumn();

            return (int) $v === 1;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Portal cliente (rol 3): si `companies.show_inventory` = 1 para FULLVENDOR_COMPANY_ID, mostrar cantidades de stock en UI.
     * Sin BD FullVendor configurada no aplica (se asume mostrar como hasta ahora).
     */
    public static function companyShowsProductInventory(): bool
    {
        if (!function_exists('fullvendor_db_configured') || !fullvendor_db_configured()) {
            return true;
        }
        try {
            return self::companyShowInventory(self::pdo());
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Galería como wcproductList_post (product_id en product_images = products.product_id).
     *
     * @return list<array<string, mixed>>
     */
    private static function galleryForProduct(\PDO $pdo, int|string $productRowId, int|string $companyId): array
    {
        $tbl = self::table('product_images');
        $st = $pdo->prepare('SELECT * FROM ' . $tbl . ' WHERE `product_id` = :pid ORDER BY `img_order` ASC, `img_id` ASC');
        $st->execute([':pid' => $productRowId]);
        $imgRows = $st->fetchAll();
        $gallery = [];
        foreach ($imgRows as $img) {
            if (!is_array($img)) {
                continue;
            }
            $file = trim((string) ($img['images'] ?? ''));
            $gallery[] = [
                'product_id' => $img['product_id'] ?? $productRowId,
                'company_id' => $companyId,
                'img_id' => $img['img_id'] ?? 0,
                'img_order' => $img['img_order'] ?? 0,
                'img_default' => $img['img_default'] ?? 0,
                'pic' => self::productPicUrl($file, $companyId),
                'local' => (isset($img['img_id']) ? (string) $img['img_id'] : '0') . '.jpg',
            ];
        }
        if ($gallery === []) {
            $gallery[] = [
                'product_id' => $productRowId,
                'company_id' => $companyId,
                'img_id' => 0,
                'img_order' => 0,
                'img_default' => 1,
                'pic' => self::productPicUrl('', $companyId),
                'local' => '0.jpg',
            ];
        }

        return $gallery;
    }

    /**
     * Si la galería solo apunta a noimg.png, usar imgURL / simgURL de la fila products (esquema FullVendor).
     *
     * @param list<array<string, mixed>> $gallery
     * @return list<array<string, mixed>>
     */
    private static function applyImageUrlFallback(array $gallery, array $p, int|string $companyId): array
    {
        $pic0 = (string) ($gallery[0]['pic'] ?? '');
        if ($pic0 === '' || !str_contains($pic0, 'noimg')) {
            return $gallery;
        }
        $url = trim((string) ($p['imgURL'] ?? $p['imgurl'] ?? ''));
        if ($url === '') {
            $url = trim((string) ($p['simgURL'] ?? $p['simgurl'] ?? ''));
        }
        if ($url === '') {
            return $gallery;
        }
        $url = self::absolutizeProductMediaUrl($url);
        $pid = $p['product_id'] ?? 0;

        return [[
            'product_id' => $pid,
            'company_id' => $companyId,
            'img_id' => 0,
            'img_order' => 0,
            'img_default' => 1,
            'pic' => $url,
            'local' => '0.jpg',
        ]];
    }

    /**
     * @return array<int, list<array<string, mixed>>>
     */
    private static function galleriesByProductId(\PDO $pdo, array $productIds, int|string $companyId): array
    {
        $productIds = array_values(array_unique(array_filter($productIds, static fn ($x) => $x !== '' && $x !== null)));
        if ($productIds === []) {
            return [];
        }
        $tbl = self::table('product_images');
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $st = $pdo->prepare('SELECT * FROM ' . $tbl . ' WHERE `product_id` IN (' . $placeholders . ') ORDER BY `product_id`, `img_order` ASC, `img_id` ASC');
        $st->execute(array_values($productIds));
        $all = $st->fetchAll();
        $byPid = [];
        foreach ($all as $img) {
            if (!is_array($img)) {
                continue;
            }
            $pid = (string) ($img['product_id'] ?? '');
            if ($pid === '') {
                continue;
            }
            $byPid[$pid][] = $img;
        }
        $out = [];
        foreach ($byPid as $pid => $rows) {
            $gallery = [];
            foreach ($rows as $img) {
                $file = trim((string) ($img['images'] ?? ''));
                $gallery[] = [
                    'product_id' => $img['product_id'] ?? $pid,
                    'company_id' => $companyId,
                    'img_id' => $img['img_id'] ?? 0,
                    'img_order' => $img['img_order'] ?? 0,
                    'img_default' => $img['img_default'] ?? 0,
                    'pic' => self::productPicUrl($file, $companyId),
                    'local' => (isset($img['img_id']) ? (string) $img['img_id'] : '0') . '.jpg',
                ];
            }
            $out[$pid] = $gallery;
        }

        return $out;
    }

    /**
     * Una fila producto en el mismo shape que wcproductList_post → list[].
     *
     * @param array<string, mixed> $p
     * @param list<array<string, mixed>> $gallery
     * @param list<array<string, mixed>> $requested
     * @return array<string, mixed>
     */
    private static function productToApiShape(
        array $p,
        array $gallery,
        int $catalogId,
        int $orden,
        ?string $categoryFilter,
        bool $showInventory,
        int|string $companyId,
        array $requested = []
    ): array {
        $stock = $p['stock'] ?? 0;
        $lbl = '';
        if ($showInventory) {
            $lbl = 'Stock: ' . $stock;
        }
        $name = (string) ($p['name'] ?? '');
        $nameUp = $name;
        if ($nameUp !== '') {
            $nameUp = function_exists('mb_strtoupper') ? mb_strtoupper($nameUp, 'UTF-8') : strtoupper($nameUp);
        }

        return [
            'tipo' => (string) ($categoryFilter ?? ''),
            'consulta' => '1',
            'catalog_order' => $orden,
            'FilaOrden' => $orden,
            'catalog_id' => $catalogId,
            'product_id' => $p['product_id'] ?? null,
            'name' => $nameUp,
            'sku' => $p['sku'] ?? '',
            'category_id' => $p['category_id'] ?? '',
            'sale_price' => $p['sale_price'] ?? 0,
            'sale_price0' => $p['sale_price'] ?? 0,
            'fob_price' => $p['fob_price'] ?? 0,
            'purchase_price' => $p['purchase_price'] ?? 0,
            'barcode' => $p['barcode'] ?? '',
            'tags' => $p['tags'] ?? '',
            'descriptions' => $p['descriptions'] ?? '',
            'unit_type' => $p['unit_type'] ?? '',
            'stock' => $stock,
            'lblstock' => $lbl,
            'total_order' => 0,
            'available_stock' => $stock,
            'minimum_stock' => $p['minimum_stock'] ?? 0,
            'force_moq' => $p['notify_minimum_stock'] ?? 0,
            'status' => (string) ($p['status'] ?? '1'),
            'currency_type' => self::currencySymbol($p['currency_type'] ?? ''),
            'images' => $gallery,
            'requested' => $requested,
            'language_id' => (string) ($p['language_id'] ?? '1'),
            'company_id' => $companyId,
            'pro_id' => $p['pro_id'] ?? null,
        ];
    }

    /**
     * Igual que categoriesList_post: SELECT cat_id AS category_id, … FROM categories WHERE company_id = …
     * Opcional: AND language_id = :lid (FULLVENDOR_DB_CATEGORIES_FILTER_LANGUAGE=1, por defecto).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getCategories(string $languageId): array
    {
        $pdo = self::pdo();
        $t = self::table('categories');
        $sql = 'SELECT '
            . '`cat_id`, `cat_id` AS `category_id`, `language_id`, `company_id`, `category_name`, '
            . '`images`, `category_status`, `category_created_at`, `id_kor`, '
            . 'IFNULL(`category_default`, 0) AS `category_default` '
            . 'FROM ' . $t . ' WHERE `company_id` = :cid';
        $params = [':cid' => self::companyParam()];
        if (config('FULLVENDOR_DB_CATEGORIES_FILTER_LANGUAGE', '1') !== '0') {
            $sql .= ' AND `language_id` = :lid';
            $params[':lid'] = $languageId;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            /* hv_normalize_category: images como string URL absoluta */
            $imgRaw = trim((string) ($row['images'] ?? ''));
            $row['images'] = self::categoryImagePublicUrl($imgRaw);
            $row['category_id'] = (string) ($row['category_id'] ?? $row['cat_id'] ?? '');
            $row['category_name'] = (string) ($row['category_name'] ?? '');
            $row['language_id'] = (string) ($row['language_id'] ?? $languageId);
            $row['company_id'] = (string) ($row['company_id'] ?? '');
            $out[] = $row;
        }

        $catalogId = self::resolveCatalogId($pdo);
        $orderMap = self::categoryOrderIndexByCatalog($pdo, $catalogId, self::companyParam());
        usort(
            $out,
            static function (array $a, array $b) use ($orderMap): int {
                $ida = (string) ($a['category_id'] ?? '');
                $idb = (string) ($b['category_id'] ?? '');
                $oa = $orderMap[$ida] ?? PHP_INT_MAX;
                $ob = $orderMap[$idb] ?? PHP_INT_MAX;
                if ($oa !== $ob) {
                    return $oa <=> $ob;
                }

                return strcmp((string) ($a['category_name'] ?? ''), (string) ($b['category_name'] ?? ''));
            }
        );

        return $out;
    }

    /**
     * wcproductList_post: status=1, sale_price>0, company_id, language_id; imágenes desde product_images.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getProducts(?string $categoryId, ?string $customerId, string $languageId): array
    {
        $pdo = self::pdo();
        $cid = self::companyParam();
        $catalogId = self::resolveCatalogId($pdo);
        $catFilter = ($categoryId !== null && $categoryId !== '') ? trim($categoryId) : null;
        $rows = self::fetchProductRowsForList($pdo, $catalogId, $languageId, $catFilter, $cid);
        if ($rows === []) {
            return [];
        }

        $pids = [];
        foreach ($rows as $r) {
            if (is_array($r) && isset($r['product_id'])) {
                $pids[] = $r['product_id'];
            }
        }
        $galleries = self::galleriesByProductId($pdo, $pids, $cid);
        $showInv = self::companyShowInventory($pdo);
        $custInt = ($customerId !== null && $customerId !== '') ? (int) $customerId : 0;
        $reqMap = self::requestedByProductIds($pdo, $custInt, $pids);

        $out = [];
        $orden = 0;
        foreach ($rows as $p) {
            if (!is_array($p)) {
                continue;
            }
            $pidKey = (string) ($p['product_id'] ?? '');
            $gallery = $galleries[$pidKey] ?? [];
            if ($gallery === []) {
                $gallery = self::galleryForProduct($pdo, $p['product_id'] ?? 0, $cid);
            }
            $gallery = self::applyImageUrlFallback($gallery, $p, $cid);
            $out[] = self::productToApiShape(
                $p,
                $gallery,
                $catalogId,
                $orden,
                $catFilter,
                $showInv,
                $cid,
                $reqMap[$pidKey] ?? []
            );
            $orden++;
        }

        return $out;
    }

    /**
     * Respuesta tipo productList_post de FullVendor (status, language_id, list).
     * Requiere company_id igual a FULLVENDOR_COMPANY_ID.
     *
     * @param array<string, mixed> $userData
     * @return array<string, mixed>
     */
    public static function productListPost(array $userData): array
    {
        $language_id = trim((string) ($userData['language_id'] ?? ''));
        $company_id = trim((string) ($userData['company_id'] ?? ''));
        if ($language_id === '') {
            return ['status' => '0', 'error' => 'The Language Id field is required.'];
        }
        if ($company_id === '') {
            return ['status' => '0', 'error' => 'The Company Id field is required.'];
        }
        $expected = self::normalizedCompanyId();
        if ($expected === '' || (string) $company_id !== $expected) {
            return ['status' => '0', 'error' => 'Invalid company_id'];
        }

        $category_id = isset($userData['category_id']) ? trim((string) $userData['category_id']) : '';
        $customer_id = isset($userData['customer_id']) ? trim((string) $userData['customer_id']) : '';

        $catParam = $category_id === '' ? null : $category_id;
        $custParam = $customer_id === '' ? null : $customer_id;
        $list = self::getProducts($catParam, $custParam, $language_id);
        if ($list === []) {
            return ['status' => '0', 'error' => 'No Data found. '];
        }

        return [
            'status' => '1',
            'language_id' => $language_id,
            'list' => $list,
        ];
    }

    /**
     * Ficha: misma forma de filas que la lista + detalle en details/info.
     *
     * @return array<string, mixed>
     */
    public static function getProductDetails(int $productId, string $languageId = '1'): array
    {
        $pdo = self::pdo();
        $t = self::table('products');
        $cid = self::companyParam();
        $sql = 'SELECT * FROM ' . $t
            . ' WHERE `product_id` = :pid AND `company_id` = :cid AND `language_id` = :lid AND `status` = 1 AND `sale_price` > 0 LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute([':pid' => $productId, ':cid' => $cid, ':lid' => $languageId]);
        $p = $st->fetch();
        if (!is_array($p)) {
            return ['status' => '0', 'error' => 'Product not found'];
        }
        $gallery = self::applyImageUrlFallback(
            self::galleryForProduct($pdo, $p['product_id'] ?? $productId, $cid),
            $p,
            $cid
        );
        $catalogId = self::resolveCatalogId($pdo);
        $showInv = self::companyShowInventory($pdo);
        $detail = self::productToApiShape($p, $gallery, $catalogId, 0, null, $showInv, $cid, []);

        return ['status' => '1', 'details' => $detail, 'info' => $detail];
    }

    /**
     * Otros productos que comparten categorías (category_id tipo CSV), orden como catálogo (order_categories + nombre).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getRelatedProducts(int $excludeProductId, string $categoryIdCsv, string $languageId, int $limit = 24): array
    {
        $catParts = [];
        foreach (explode(',', $categoryIdCsv) as $x) {
            $x = trim((string) $x);
            if ($x !== '') {
                $catParts[] = $x;
            }
        }
        $catParts = array_values(array_unique($catParts));
        if ($catParts === []) {
            return [];
        }
        $limit = max(1, min(60, $limit));

        $pdo = self::pdo();
        $t = self::table('products');
        $cid = self::companyParam();
        $catalogId = self::resolveCatalogId($pdo);

        $params = [':ex' => $excludeProductId, ':lid' => $languageId, ':cid' => $cid];
        $orParts = [];
        foreach ($catParts as $i => $cat) {
            $ph = ':rc' . $i;
            $orParts[] = 'FIND_IN_SET(' . $ph . ', p2.`category_id`)';
            $params[$ph] = $cat;
        }
        $orSql = implode(' OR ', $orParts);

        $rows = [];
        if ($catalogId > 0 && self::hasOrderCategoriesTable($pdo)) {
            try {
                $oc = self::table('order_categories');
                $sql = 'SELECT p.* FROM ('
                    . ' SELECT p2.`product_id`, MIN(od.`order`) AS ord'
                    . ' FROM ' . $oc . ' od'
                    . ' INNER JOIN ' . $t . ' p2 ON FIND_IN_SET(od.`category_id`, p2.`category_id`)'
                    . ' WHERE p2.`status` = 1 AND p2.`company_id` = :cid AND p2.`language_id` = :lid'
                    . ' AND p2.`sale_price` > 0 AND p2.`product_id` != :ex'
                    . ' AND od.`catalog_id` = :catalog AND od.`company_id` = :cid'
                    . ' AND (' . $orSql . ')'
                    . ' GROUP BY p2.`product_id`'
                    . ' ) x'
                    . ' INNER JOIN ' . $t . ' p ON p.`product_id` = x.`product_id`'
                    . ' ORDER BY x.`ord` ASC, p.`name` ASC'
                    . ' LIMIT ' . (int) $limit;
                $paramsCat = $params + [':catalog' => $catalogId];
                $st = $pdo->prepare($sql);
                $st->execute($paramsCat);
                $fetched = $st->fetchAll();
                if (is_array($fetched)) {
                    $rows = $fetched;
                }
            } catch (Throwable $e) {
                error_log('[FullVendorDb] getRelatedProducts catalog: ' . $e->getMessage());
                $rows = [];
            }
        }

        if ($rows === []) {
            $params2 = [':ex' => $excludeProductId, ':lid' => $languageId, ':cid' => $cid];
            $or2 = [];
            foreach ($catParts as $i => $cat) {
                $ph = ':fc' . $i;
                $or2[] = 'FIND_IN_SET(' . $ph . ', `category_id`)';
                $params2[$ph] = $cat;
            }
            $sql2 = 'SELECT * FROM ' . $t
                . ' WHERE `status` = 1 AND `company_id` = :cid AND `language_id` = :lid AND `sale_price` > 0'
                . ' AND `product_id` != :ex AND (' . implode(' OR ', $or2) . ')'
                . ' ORDER BY `name` ASC LIMIT ' . (int) $limit;
            $st = $pdo->prepare($sql2);
            $st->execute($params2);
            $rows = $st->fetchAll() ?: [];
        }

        $pids = [];
        foreach ($rows as $r) {
            if (is_array($r) && isset($r['product_id'])) {
                $pids[] = $r['product_id'];
            }
        }
        $galleries = self::galleriesByProductId($pdo, $pids, $cid);
        $showInv = self::companyShowInventory($pdo);
        $out = [];
        $orden = 0;
        foreach ($rows as $p) {
            if (!is_array($p)) {
                continue;
            }
            $pidKey = (string) ($p['product_id'] ?? '');
            $gallery = $galleries[$pidKey] ?? [];
            if ($gallery === []) {
                $gallery = self::galleryForProduct($pdo, $p['product_id'] ?? 0, $cid);
            }
            $gallery = self::applyImageUrlFallback($gallery, $p, $cid);
            $out[] = self::productToApiShape($p, $gallery, $catalogId, $orden, null, $showInv, $cid, []);
            $orden++;
        }

        return $out;
    }

    /**
     * Grupos de cliente (tabla `customer_group` en la BD de FullVendor).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fetchCustomerGroups(?string $languageId = null): array
    {
        $pdo = self::pdo();
        $t = self::table('customer_group');
        $cid = self::companyParam();

        $rows = self::queryCustomerGroups($pdo, $t, $languageId, true, $cid);
        if ($rows !== []) {
            return $rows;
        }
        /* Muchas instalaciones tienen company_id NULL/0 en customer_group: segunda pasada sin filtro de compañía */
        if (config('FULLVENDOR_DB_CUSTOMER_GROUP_RELAX_COMPANY', '1') !== '0') {
            $rows = self::queryCustomerGroups($pdo, $t, $languageId, false, $cid);
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function queryCustomerGroups(
        \PDO $pdo,
        string $tableIdent,
        ?string $languageId,
        bool $filterCompany,
        int|string $companyId
    ): array {
        $sql = 'SELECT * FROM ' . $tableIdent . ' WHERE 1=1';
        $params = [];
        if ($filterCompany) {
            $sql .= ' AND `company_id` = :cid';
            $params[':cid'] = $companyId;
        }
        if ($languageId !== null && $languageId !== '') {
            $sql .= ' AND `language_id` = :lid';
            $params[':lid'] = $languageId;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();

        return is_array($rows) ? $rows : [];
    }
}
