<?php

declare(strict_types=1);

final class FullVendor
{
    private const TIMEOUT = 90;

    private static function apiSnapshotDir(): string
    {
        $dir = dirname(__DIR__) . '/data/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir;
    }

    /**
     * Nombre de archivo estable por endpoint + parámetros relevantes del POST (sobrescribe la última llamada igual).
     */
    private static function apiSnapshotBasename(string $endpoint, array $body): string
    {
        $ep = preg_replace('/[^a-zA-Z0-9_-]/', '_', $endpoint);
        if ($ep === '') {
            $ep = 'api';
        }
        $segments = [$ep];
        $paramKeys = [
            'language_id', 'category_id', 'customer_id', 'user_id', 'product_id',
            'cart_id', 'company_id',
        ];
        foreach ($paramKeys as $k) {
            if (!array_key_exists($k, $body)) {
                continue;
            }
            $v = $body[$k];
            if ($v === '' || $v === null) {
                continue;
            }
            $seg = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string) $v);
            if ($seg !== '') {
                $segments[] = $k . '_' . $seg;
            }
        }

        return implode('-', $segments);
    }

    /** @param array<string, mixed> $body */
    private static function redactRequestForSnapshot(array $body): array
    {
        $hide = ['password', 'otp', 'token', 'old_password', 'new_password'];
        $out = [];
        foreach ($body as $k => $v) {
            if (in_array(strtolower((string) $k), $hide, true)) {
                $out[$k] = '[redacted]';
            } elseif (is_array($v)) {
                $out[$k] = self::redactRequestForSnapshot($v);
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /** @return 'off'|'lists'|'all' */
    private static function apiSnapshotMode(): string
    {
        $v = strtolower(trim((string) config('FULLVENDOR_SAVE_API_JSON', '0')));
        if ($v === '' || $v === '0' || $v === 'false' || $v === 'off' || $v === 'no') {
            return 'off';
        }
        if ($v === 'lists') {
            return 'lists';
        }

        return 'all';
    }

    /**
     * En modo `lists` solo se guardan respuestas de listas masivas (pocas rutas, pocos archivos).
     * Modo `all` genera muchos fv-* (p. ej. un archivo por product_id visto o por acción de carrito).
     */
    private static function apiSnapshotAllowedForEndpoint(string $endpoint): bool
    {
        $mode = self::apiSnapshotMode();
        if ($mode === 'off') {
            return false;
        }
        if ($mode === 'all') {
            return true;
        }
        $allowed = ['categoryList', 'productList'];
        $cg = trim((string) config('FULLVENDOR_CUSTOMER_GROUPS_LIST_ENDPOINT', 'customergroupsList'));
        if ($cg !== '') {
            $allowed[] = $cg;
        }
        $soft = trim((string) config('FULLVENDOR_SOFT_CUSTOMER_LIST_ENDPOINT', 'softcustomerList'));
        if ($soft !== '') {
            $allowed[] = $soft;
        }
        $ul = trim((string) config('FULLVENDOR_USERS_LIST_ENDPOINT', 'usersList'));
        if ($ul !== '') {
            $allowed[] = $ul;
        }

        return in_array($endpoint, $allowed, true);
    }

    /**
     * Guarda request/response en data/cache (mismo ámbito que products-/categories-). No interrumpe la API si falla el disco.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $response
     */
    private static function writeApiSnapshot(string $endpoint, array $body, array $response): void
    {
        if (!self::apiSnapshotAllowedForEndpoint($endpoint)) {
            return;
        }
        try {
            $dir = self::apiSnapshotDir();
            if (!is_dir($dir) || !is_writable($dir)) {
                return;
            }
            $base = self::apiSnapshotBasename($endpoint, $body);
            $file = $dir . '/fv-' . $base . '.json';
            $payload = [
                'ts' => time(),
                'endpoint' => $endpoint,
                'request' => self::redactRequestForSnapshot($body),
                'response' => $response,
            ];
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($encoded !== false) {
                file_put_contents($file, $encoded, LOCK_EX);
            }
        } catch (Throwable $e) {
            error_log('[FullVendor] snapshot skip: ' . $e->getMessage());
        }
    }

    /**
     * POST con cuerpo JSON exacto (sin mezclar otros campos).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function requestJsonPost(string $endpoint, array $body): array
    {
        $base = fullvendor_base_url();
        $url = $base . $endpoint;
        $token = require_env('FULLVENDOR_TOKEN');
        $encoded = json_encode($body, JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-KEY: ' . $token],
            CURLOPT_POSTFIELDS => $encoded,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('FullVendor request failed: ' . $err);
        }
        $loginEndpoint = strcasecmp($endpoint, 'login') === 0;
        if ($code < 200 || $code >= 300) {
            $jsonErr = json_decode($raw, true);
            if ($loginEndpoint && is_array($jsonErr)) {
                return $jsonErr;
            }
            if (strcasecmp($endpoint, 'addOrder') === 0) {
                $reqJson = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                if ($reqJson === false) {
                    $reqJson = '{"encode_error":"cannot_encode_request"}';
                }
                throw new RuntimeException("FullVendor HTTP {$code} on {$endpoint}: " . mb_substr($raw, 0, 200) . ' | request=' . $reqJson);
            }
            throw new RuntimeException("FullVendor HTTP {$code} on {$endpoint}: " . mb_substr($raw, 0, 200));
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new RuntimeException('FullVendor invalid JSON on ' . $endpoint);
        }
        if (isset($json['status'])) {
            $st = $json['status'];
            $failed = $st === false || $st === 0 || $st === '0' || $st === 'false';
            if ($failed) {
                $err = (string) ($json['error'] ?? $json['message'] ?? $json['msg'] ?? 'Request failed');
                if ($loginEndpoint) {
                    self::writeApiSnapshot($endpoint, $body, $json);

                    return $json;
                }
                if (strcasecmp($endpoint, 'addOrder') === 0) {
                    $reqJson = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                    if ($reqJson === false) {
                        $reqJson = '{"encode_error":"cannot_encode_request"}';
                    }
                    $rawJson = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                    if ($rawJson === false) {
                        $rawJson = '{"encode_error":"cannot_encode_response"}';
                    }
                    throw new RuntimeException('FullVendor API error on addOrder: ' . $err . ' | request=' . $reqJson . ' | response=' . $rawJson);
                }
                throw new RuntimeException('FullVendor API error: ' . $err);
            }
        }

        self::writeApiSnapshot($endpoint, $body, $json);

        return $json;
    }

    private static function normalizeCompanyId(string $companyId): int|string
    {
        $t = trim($companyId);
        if ($t === '') {
            return $companyId;
        }
        if (ctype_digit($t)) {
            return (int) $t;
        }
        if (is_numeric($t)) {
            return (int) $t;
        }

        return $t;
    }

    /** @param array<string, mixed> $data */
    private static function post(string $endpoint, array $data = []): array
    {
        $cid = require_env('FULLVENDOR_COMPANY_ID');

        return self::requestJsonPost($endpoint, array_merge(
            ['company_id' => self::normalizeCompanyId($cid)],
            $data
        ));
    }

    /**
     * Envío directo al endpoint REST FullVendor **addOrder** (mismo que la app móvil / Flutter).
     * Se fusiona `company_id` desde .env; el cuerpo debe seguir OrderPlaceRequestBody + itemList.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function addOrder(array $payload): array
    {
        return self::post('addOrder', $payload);
    }

    /** @return array<int, array<string, mixed>> */
    public static function getProducts(?string $categoryId, ?string $customerId, string $languageId): array
    {
        if (function_exists('fullvendor_data_source') && fullvendor_data_source() === 'db') {
            require_once __DIR__ . '/FullVendorDb.php';

            return FullVendorDb::getProducts($categoryId, $customerId, $languageId);
        }
        $data = ['language_id' => $languageId];
        if ($categoryId !== null && $categoryId !== '') {
            $data['category_id'] = $categoryId;
        }
        if ($customerId !== null && $customerId !== '') {
            $data['customer_id'] = $customerId;
        }
        $res = self::post('wcproductList', $data);
        $list = $res['list'] ?? [];

        return is_array($list) ? $list : [];
    }

    /**
     * Respuesta estilo productList_post de FullVendor: status, language_id, list.
     * En modo db usa order_categories + FIND_IN_SET (misma lógica que el API legacy).
     * En modo api reenvía a wcproductList con company_id de este sitio.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function productList(array $body): array
    {
        if (function_exists('fullvendor_data_source') && fullvendor_data_source() === 'db') {
            require_once __DIR__ . '/FullVendorDb.php';

            return FullVendorDb::productListPost($body);
        }
        if (!fullvendor_api_configured()) {
            return ['status' => '0', 'error' => 'FullVendor API not configured'];
        }
        $cid = require_env('FULLVENDOR_COMPANY_ID');
        $merged = array_merge($body, ['company_id' => self::normalizeCompanyId($cid)]);
        $res = self::requestJsonPost('wcproductList', $merged);
        if (($res['status'] ?? '') === '1' && isset($res['list']) && is_array($res['list'])) {
            return [
                'status' => '1',
                'language_id' => (string) ($body['language_id'] ?? '1'),
                'list' => $res['list'],
            ];
        }
        $list = $res['list'] ?? null;
        if (is_array($list) && $list !== []) {
            return [
                'status' => '1',
                'language_id' => (string) ($body['language_id'] ?? '1'),
                'list' => $list,
            ];
        }

        return [
            'status' => '0',
            'error' => is_string($res['error'] ?? null) ? $res['error'] : 'No Data found. ',
        ];
    }

    /** @return array<string, mixed> */
    public static function getProductDetails(int $productId, string $languageId = '1'): array
    {
        if (function_exists('fullvendor_data_source') && fullvendor_data_source() === 'db') {
            require_once __DIR__ . '/FullVendorDb.php';

            return FullVendorDb::getProductDetails($productId, $languageId);
        }

        return self::post('productDetails', [
            'product_id' => $productId,
            'language_id' => $languageId,
        ]);
    }

    /**
     * Productos de la misma categoría (category_id CSV), excluyendo el actual.
     * Modo db: SQL con order_categories. Modo api: filtra la lista completa de wcproductList.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getRelatedProducts(int $excludeProductId, string $categoryIdCsv, string $languageId, int $limit = 24): array
    {
        if (function_exists('fullvendor_data_source') && fullvendor_data_source() === 'db') {
            require_once __DIR__ . '/FullVendorDb.php';

            return FullVendorDb::getRelatedProducts($excludeProductId, $categoryIdCsv, $languageId, $limit);
        }
        $all = self::getProducts(null, null, $languageId);

        return hv_filter_related_products(is_array($all) ? $all : [], $excludeProductId, $categoryIdCsv, $limit);
    }

    /**
     * Lista de grupos de cliente (solo HTTP). En modo db use FullVendorDb::fetchCustomerGroups o la tabla local tras sync.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function customerGroupsList(string $languageId): array
    {
        $endpoint = trim((string) config('FULLVENDOR_CUSTOMER_GROUPS_LIST_ENDPOINT', 'customergroupsList'));
        if ($endpoint === '') {
            $endpoint = 'customergroupsList';
        }
        $res = self::post($endpoint, ['language_id' => $languageId]);
        $list = self::extractCustomerGroupsListArray($res);
        if ($list === [] && config('FULLVENDOR_CUSTOMER_GROUPS_TRY_NO_LANGUAGE', '1') !== '0') {
            $res2 = self::post($endpoint, []);
            $list = self::extractCustomerGroupsListArray($res2);
        }

        return $list;
    }

    /**
     * Muchas rutas devuelven `list`; otras anidan o usan nombres distintos (data, group_list, result.list, etc.).
     *
     * @param array<string, mixed> $res
     * @return array<int, array<string, mixed>>
     */
    private static function extractCustomerGroupsListArray(array $res): array
    {
        $list = self::tryExtractListFromContainer($res);
        if ($list !== null) {
            return $list;
        }
        if (isset($res['result']) && is_array($res['result'])) {
            $list = self::tryExtractListFromContainer($res['result']);
            if ($list !== null) {
                return $list;
            }
        }

        return self::isSequentialArrayOfAssoc($res) ? $res : [];
    }

    /**
     * @param array<string, mixed> $box
     * @return list<array<string, mixed>>|null
     */
    private static function tryExtractListFromContainer(array $box): ?array
    {
        $keys = [
            'list', 'List', 'data', 'Data', 'rows', 'Rows', 'items', 'Items',
            'customer_groups', 'customer_group_list', 'customerGroupList',
            'group_list', 'groupList', 'groups', 'Groups', 'records', 'Records',
        ];
        foreach ($keys as $k) {
            if (!array_key_exists($k, $box) || !is_array($box[$k])) {
                continue;
            }
            $inner = $box[$k];
            if (self::isSequentialArrayOfAssoc($inner)) {
                /** @var list<array<string, mixed>> $inner */
                return $inner;
            }
            if (is_array($inner)) {
                $nested = self::tryExtractListFromContainer($inner);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    /** @param array<mixed> $a */
    private static function isSequentialArrayOfAssoc(array $a): bool
    {
        if ($a === []) {
            return false;
        }
        if (function_exists('array_is_list')) {
            return array_is_list($a) && isset($a[0]) && is_array($a[0]);
        }
        $i = 0;
        foreach ($a as $k => $v) {
            if ((int) $k !== $i || !is_array($v)) {
                return false;
            }
            $i++;
        }

        return true;
    }

    /** @return array<int, array<string, mixed>> */
    public static function getCategories(string $languageId): array
    {
        if (function_exists('fullvendor_data_source') && fullvendor_data_source() === 'db') {
            require_once __DIR__ . '/FullVendorDb.php';

            return FullVendorDb::getCategories($languageId);
        }
        $res = self::post('categoryList', ['language_id' => $languageId]);
        $list = $res['list'] ?? [];

        return is_array($list) ? $list : [];
    }

    /**
     * Lista clientes. El cuerpo del POST es solo {"company_id": ...} (sin más claves).
     *
     * @return array<string, mixed>
     */
    public static function softCustomerList(): array
    {
        $cid = require_env('FULLVENDOR_COMPANY_ID');
        $endpoint = trim((string) config('FULLVENDOR_SOFT_CUSTOMER_LIST_ENDPOINT', 'allcustomerList'));
        if ($endpoint === '') {
            $endpoint = 'allcustomerList';
        }

        return self::requestJsonPost($endpoint, [
            'company_id' => self::normalizeCompanyId($cid),
        ]);
    }

    /**
     * Lista usuarios de la compañía (POST con company_id, igual que otras listas).
     *
     * @return array<string, mixed>
     */
    public static function usersList(): array
    {
        $endpoint = trim((string) config('FULLVENDOR_USERS_LIST_ENDPOINT', 'usersList'));
        if ($endpoint === '') {
            $endpoint = 'usersList';
        }

        return self::post($endpoint, []);
    }

    /** @return array<string, mixed> */
    public static function login(string $email, string $password): array
    {
        return self::post('login', [
            'email' => $email,
            'password' => $password,
            'user_type' => 1,
        ]);
    }

    /** @param array<string, mixed> $data */
    public static function createCustomer(array $data): array
    {
        return self::post('createCustomer', $data);
    }

    /** @return array<string, mixed> */
    public static function getCart(int $userId, string $languageId): array
    {
        return self::post('cartList', [
            'user_id' => $userId,
            'language_id' => $languageId,
        ]);
    }

    public static function addToCart(int $userId, int $productId, int $qty, string $languageId): array
    {
        return self::post('addEditCart', [
            'user_id' => $userId,
            'language_id' => $languageId,
            'product_id' => $productId,
            'qty' => $qty,
        ]);
    }

    public static function removeFromCart(int $cartId): array
    {
        return self::post('deleteCart', ['cart_id' => $cartId]);
    }

    public static function clearCart(int $userId): array
    {
        return self::post('deleteCartAll', ['user_id' => $userId]);
    }

    private static function uuidV4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);

        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }

    /** 1 = importe fijo, 2 = porcentaje (alineado con modelo app FullVendor). */
    private static function discountTypeToInt(string $discountType): int
    {
        return $discountType === 'percent' ? 2 : 1;
    }

    /**
     * user_id del pedido: rol 2 → `vendors.user_id` en BD FullVendor (mismo que sesión vendedor);
     * rol 3 → primer id en `customers.user_id` (CSV de vendedores asignados).
     */
    private static function resolveOrderUserIdForAddOrder(int $rolId, int $sessionUserId, int $customerFvId, int $preferredSellerUserId = 0): int
    {
        if ($preferredSellerUserId > 0) {
            return $preferredSellerUserId;
        }
        if ($sessionUserId <= 0) {
            return self::fallbackVendorUserIdByCompany();
        }
        if ($rolId === 2) {
            if (function_exists('fullvendor_db_configured') && fullvendor_db_configured()) {
                try {
                    require_once __DIR__ . '/FullVendorDb.php';
                    $pdo = FullVendorDb::pdo();
                    $tv = FullVendorDb::sqlTable('vendors');
                    $st = $pdo->prepare("SELECT `user_id` FROM {$tv} WHERE `user_id` = ? LIMIT 1");
                    $st->execute([$sessionUserId]);
                    $row = $st->fetch(\PDO::FETCH_ASSOC);
                    if (is_array($row) && (int) ($row['user_id'] ?? 0) > 0) {
                        return (int) $row['user_id'];
                    }
                } catch (Throwable) {
                }
            }

            return $sessionUserId > 0 ? $sessionUserId : self::fallbackVendorUserIdByCompany();
        }
        if ($rolId === 3 && $customerFvId > 0 && class_exists('Db', false) && Db::enabled()) {
            try {
                $st = Db::pdo()->prepare(
                    'SELECT `user_id` FROM `customers` WHERE `customeridfullvendor` = ? OR `customer_id` = ? LIMIT 1'
                );
                $st->execute([$customerFvId, $customerFvId]);
                $row = $st->fetch(\PDO::FETCH_ASSOC);
                if (is_array($row)) {
                    $raw = trim((string) ($row['user_id'] ?? ''));
                    if ($raw !== '') {
                        $first = trim(explode(',', $raw)[0]);
                        if ($first !== '' && ctype_digit($first)) {
                            return (int) $first;
                        }
                    }
                }
            } catch (Throwable) {
            }
            return self::fallbackVendorUserIdByCompany();
        }

        return $sessionUserId > 0 ? $sessionUserId : self::fallbackVendorUserIdByCompany();
    }

    /**
     * Fallback para pedidos sin vendedor explícito:
     * 1) vendors.default=1 por compañía
     * 2) primer vendor de la compañía
     */
    private static function fallbackVendorUserIdByCompany(): int
    {
        if (!function_exists('fullvendor_db_configured') || !fullvendor_db_configured()) {
            return 0;
        }
        try {
            require_once __DIR__ . '/FullVendorDb.php';
            $pdo = FullVendorDb::pdo();
            $tv = FullVendorDb::sqlTable('vendors');
            $cidRaw = trim((string) config('FULLVENDOR_COMPANY_ID', ''));

            $where = '';
            $params = [];
            if ($cidRaw !== '' && ctype_digit($cidRaw)) {
                $where = ' WHERE `company_id` = ?';
                $params[] = (int) $cidRaw;
            }

            // Prioriza default=1; si no hay, toma el primero por user_id.
            $sql = "SELECT `user_id` FROM {$tv}{$where} ORDER BY CASE WHEN `default` = 1 THEN 0 ELSE 1 END ASC, `user_id` ASC LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            if (is_array($row) && (int) ($row['user_id'] ?? 0) > 0) {
                return (int) $row['user_id'];
            }
        } catch (Throwable) {
            // Si la columna `default` no existe en ese esquema, intenta por primer vendor.
            try {
                require_once __DIR__ . '/FullVendorDb.php';
                $pdo = FullVendorDb::pdo();
                $tv = FullVendorDb::sqlTable('vendors');
                $cidRaw = trim((string) config('FULLVENDOR_COMPANY_ID', ''));
                $where = '';
                $params = [];
                if ($cidRaw !== '' && ctype_digit($cidRaw)) {
                    $where = ' WHERE `company_id` = ?';
                    $params[] = (int) $cidRaw;
                }
                $sql = "SELECT `user_id` FROM {$tv}{$where} ORDER BY `user_id` ASC LIMIT 1";
                $st = $pdo->prepare($sql);
                $st->execute($params);
                $row = $st->fetch(\PDO::FETCH_ASSOC);
                if (is_array($row) && (int) ($row['user_id'] ?? 0) > 0) {
                    return (int) $row['user_id'];
                }
            } catch (Throwable) {
            }
        }

        return 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function fetchLocalCustomerRow(int $customerFvId): ?array
    {
        if (!class_exists('Db', false) || !Db::enabled() || $customerFvId <= 0) {
            return null;
        }
        try {
            $st = Db::pdo()->prepare(
                'SELECT `name`, `business_name`, `user_id`, `discount`, `group_name`, `percentage_on_price` FROM `customers`'
                . ' WHERE `customeridfullvendor` = ? OR `customer_id` = ? LIMIT 1'
            );
            $st->execute([$customerFvId, $customerFvId]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);

            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Arma el JSON de **addOrder** como `OrderPlaceRequestBody` + `itemList` (`OrderPlaceList`):
     * cabecera: `Id` 0, `created`, `contactName`, `bussName`, `tipo_d` "D", `order_status` 1, `user_id` (seller),
     * `language_id` (desde {@see lang_to_id}: 1=en, 2=es), `customer_id`, `order_comment`, `discount` (importe descuento pedido),
     * `discount_type` (1=importe, 2=porcentaje), `amount` (total líneas), `company_id`, `uuid`, `itemList`.
     * Cada línea: `product_id`, `qty`, `discount` (% cliente en línea o "0"), `discount_type` 1, `comment`, `groupcustomer` "",
     * `tipolista` "", `perc_price` 0, `salesp`, `impprice`, `totalprice`.
     * La web solo envía el DTO corto; este método construye el cuerpo completo. `company_id` también va en el array (y {@see self::post()} lo fusiona por compatibilidad).
     *
     * @param list<array<string, mixed>> $items product_id, qty, sale_price; opcional line_note|lineNote|comment
     * @param array{
     *   rolId?: int,
     *   contactName?: string,
     *   bussName?: string,
     *   customerDiscountPct?: float,
     *   groupcustomer?: string,
     *   tipolista?: string,
     *   percprice?: float,
     *   preferredSellerUserId?: int
     * } $meta
     * @param 'amount'|'percent' $discountType
     */
    public static function createOrder(
        int $sessionUserId,
        int $customerId,
        string $languageId,
        string $orderComment,
        array $items,
        float $discount = 0.0,
        string $discountType = 'amount',
        array $meta = []
    ): array {
        if ($discountType !== 'percent') {
            $discountType = 'amount';
        }
        if (!is_finite($discount) || $discount < 0) {
            $discount = 0.0;
        }

        $rolId = (int) ($meta['rolId'] ?? 0);
        $preferredSellerUserId = (int) ($meta['preferredSellerUserId'] ?? 0);
        $fvUserId = self::resolveOrderUserIdForAddOrder($rolId, $sessionUserId, $customerId, $preferredSellerUserId);
        if ($fvUserId <= 0 && ($rolId === 2 || $rolId === 3)) {
            throw new RuntimeException(
                'No hay vendedor asignado para el pedido (user_id). Revise customers.user_id en el catálogo o la sesión del cliente.'
            );
        }

        $contactName = trim((string) ($meta['contactName'] ?? ''));
        $bussName = trim((string) ($meta['bussName'] ?? ''));
        if ($contactName === '' && $bussName === '') {
            $rowFallback = self::fetchLocalCustomerRow($customerId);
            if ($rowFallback !== null) {
                $contactName = trim((string) ($rowFallback['name'] ?? ''));
                $bussName = trim((string) ($rowFallback['business_name'] ?? ''));
            }
        }

        $custDiscPct = isset($meta['customerDiscountPct']) ? (float) $meta['customerDiscountPct'] : 0.0;
        if (!is_finite($custDiscPct) || $custDiscPct < 0) {
            $custDiscPct = 0.0;
        }
        if ($custDiscPct > 100) {
            $custDiscPct = 100.0;
        }
        $lineDiscountStr = $custDiscPct > 0 ? (string) round($custDiscPct, 4) : '0';

        $itemList = [];
        $amount = 0.0;
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $pid = (int) ($it['product_id'] ?? 0);
            $qty = (int) ($it['qty'] ?? 0);
            $unit = (float) ($it['sale_price'] ?? 0);
            if ($pid <= 0 || $qty <= 0) {
                continue;
            }
            if (!is_finite($unit) || $unit < 0) {
                $unit = 0.0;
            }
            $lineTotal = round($qty * $unit, 2);
            $amount += $lineTotal;
            $comment = trim((string) ($it['line_note'] ?? $it['lineNote'] ?? $it['comment'] ?? ''));

            // OrderPlaceList: descuento del cliente en cada línea; groupcustomer/tipolista/perc_price como en app móvil base.
            $itemList[] = [
                'product_id' => $pid,
                'qty' => $qty,
                'discount' => $lineDiscountStr,
                'discount_type' => 1,
                'comment' => $comment,
                'groupcustomer' => '',
                'tipolista' => '',
                'perc_price' => 0,
                'salesp' => $unit,
                'impprice' => $lineTotal,
                'totalprice' => $lineTotal,
            ];
        }

        if ($itemList === []) {
            throw new RuntimeException(
                'No hay líneas válidas en el pedido (product_id y qty). El carrito enviado estaba vacío o sin IDs de producto.'
            );
        }

        $langInt = (int) $languageId;
        $now = date('Y-m-d H:i:s');
        $discTypeHeader = self::discountTypeToInt($discountType);
        $companyIdVal = self::normalizeCompanyId(require_env('FULLVENDOR_COMPANY_ID'));

        // Cuerpo addOrder alineado con OrderPlaceRequestBody + itemList (tipos JSON: enteros donde corresponde).
        $payload = [
            'Id' => 0,
            'created' => $now,
            'contactName' => $contactName,
            'bussName' => $bussName,
            'tipo_d' => 'D',
            'order_status' => 1,
            'user_id' => $fvUserId,
            'language_id' => $langInt,
            'customer_id' => $customerId,
            'order_comment' => $orderComment,
            'discount' => (string) round($discount, 2),
            'discount_type' => $discTypeHeader,
            'amount' => (string) round($amount, 2),
            'company_id' => $companyIdVal,
            'uuid' => self::uuidV4(),
            'itemList' => $itemList,
        ];

        return self::addOrder($payload);
    }

    /** @return array{order_list?: list<array<string, mixed>>} */
    public static function getOrders(int $userId, int $customerId, string $languageId): array
    {
        return self::post('orderList', [
            'user_id' => $userId,
            'customer_id' => $customerId,
            'language_id' => $languageId,
        ]);
    }
}
