<?php

declare(strict_types=1);

require_once __DIR__ . '/FullVendorDb.php';
require_once __DIR__ . '/HvOrderUi.php';

/**
 * Pedidos en MySQL FullVendor: cabecera `orders`, líneas `order_details`, enlace por `order_id` (FULLVENDOR_DB_TABLE_ORDERS / ORDER_DETAILS).
 * Además: products, customers, vendors, status_orders. Filtro por vendedor (user_id), cliente (customer_id) o, si no hay filtro de usuario, todos los pedidos del alcance de compañía/tipo_d (rol admin).
 */
final class FullVendorOrdersDb
{
    private static function companyIdForWhere(): ?int
    {
        $raw = trim((string) config('FULLVENDOR_COMPANY_ID', ''));
        if ($raw !== '' && ctype_digit($raw)) {
            return (int) $raw;
        }

        return null;
    }

    private static function tipoDFilter(): ?string
    {
        $tipo = trim((string) config('FULLVENDOR_DB_ORDERS_TIPO_D', ''));
        if ($tipo === '') {
            return null;
        }
        if (strlen($tipo) > 4) {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9]+$/', $tipo)) {
            return null;
        }

        return $tipo;
    }

    /**
     * Columna en `order_details` que enlaza con el pedido (por defecto order_id). Ver FULLVENDOR_DB_ORDER_DETAILS_FK_COLUMN.
     */
    private static function orderDetailsFkColumn(): string
    {
        $c = trim((string) config('FULLVENDOR_DB_ORDER_DETAILS_FK_COLUMN', ''));
        if ($c === '' || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $c)) {
            return 'order_id';
        }

        return $c;
    }

    /** @return non-empty-string p.ej. `order_id` */
    private static function orderDetailsFkIdent(): string
    {
        $c = self::orderDetailsFkColumn();

        return '`' . $c . '`';
    }

    /**
     * Resuelve orders.id cuando no está en el SELECT principal (p. ej. sin columna id en SELECT o sin alias).
     */
    private static function trySelectOrdersInternalId(\PDO $pdo, string $ordersTableSql, int $businessOrderId): int
    {
        if ($businessOrderId <= 0) {
            return 0;
        }
        try {
            $st = $pdo->prepare('SELECT `id` FROM ' . $ordersTableSql . ' WHERE `order_id` = ? LIMIT 1');
            $st->execute([$businessOrderId]);
            $v = $st->fetchColumn();

            return (is_string($v) || is_int($v) || is_float($v)) && is_numeric((string) $v) ? (int) $v : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listForVendorUserId(int $userId, string $lang = 'en'): array
    {
        if ($userId <= 0) {
            return [];
        }

        return self::loadOrders('user_id', $userId, $lang);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listForCustomerId(int $customerId, string $lang = 'en'): array
    {
        if ($customerId <= 0) {
            return [];
        }

        return self::loadOrders('customer_id', $customerId, $lang);
    }

    /**
     * Listado según rol de sesión portal: 1 = alcance compañía (sin filtro user/customer), 2 = vendedor, 3 = cliente.
     *
     * @return list<array<string, mixed>>
     */
    public static function listForAccount(int $rolId, int $fvUserId, int $fvCustomerId, string $lang = 'en'): array
    {
        if ($rolId === 1) {
            return self::loadOrders(null, null, $lang);
        }
        if ($rolId === 2 && $fvUserId > 0) {
            return self::loadOrders('user_id', $fvUserId, $lang);
        }
        if ($rolId === 3 && $fvCustomerId > 0) {
            return self::loadOrders('customer_id', $fvCustomerId, $lang);
        }

        return [];
    }

    /**
     * @param 'user_id'|'customer_id'|null $column null = sin filtrar por vendedor/cliente (solo company_id / tipo_d si aplican)
     * @return list<array<string, mixed>>
     */
    private static function loadOrders(?string $column, ?int $id, string $lang): array
    {
        if ($column !== null) {
            if ($column !== 'user_id' && $column !== 'customer_id') {
                throw new InvalidArgumentException('columna no válida');
            }
            if ($id === null || $id <= 0) {
                throw new InvalidArgumentException('id inválido');
            }
        }

        $pdo = FullVendorDb::pdo();
        $to = FullVendorDb::sqlTable('orders');
        $tc = FullVendorDb::sqlTable('customers');
        $tv = FullVendorDb::sqlTable('vendors');

        $where = [];
        $params = [];
        if ($column !== null && $id !== null) {
            $where[] = 'o.`' . $column . '` = :id';
            $params['id'] = $id;
        }

        if (trim((string) config('FULLVENDOR_DB_ORDERS_IGNORE_COMPANY', '')) !== '1') {
            $cid = self::companyIdForWhere();
            if ($cid !== null) {
                $where[] = 'o.`company_id` = :comp';
                $params['comp'] = $cid;
            }
        }

        $tipo = self::tipoDFilter();
        if ($tipo !== null) {
            $where[] = 'o.`tipo_d` = :tipo_d';
            $params['tipo_d'] = $tipo;
        }

        if ($where === []) {
            $where[] = '1=1';
        }

        // Vendedor: siempre por orders.user_id → vendors.user_id (sin filtrar por company_id en v:
        // en muchas BDs el vendor no coincide con FULLVENDOR_COMPANY_ID y el nombre quedaba vacío).
        $vJoin = ' LEFT JOIN ' . $tv . ' v ON v.`user_id` = o.`user_id`';
        if (trim((string) config('FULLVENDOR_DB_VENDOR_JOIN_MATCH_ORDER_COMPANY', '')) === '1') {
            $vJoin .= ' AND v.`company_id` <=> o.`company_id`';
        }

        $statusSelect = '';
        $statusJoin = '';
        if (trim((string) config('FULLVENDOR_DB_ORDERS_SKIP_STATUS_JOIN', '')) !== '1') {
            $tso = FullVendorDb::sqlTable('status_orders');
            $statusJoin = ' LEFT JOIN ' . $tso . ' so ON so.`cod_status` = o.`order_status`'
                . ' AND (IFNULL(TRIM(so.`tipo_d`), \'\') = \'\' OR so.`tipo_d` <=> o.`tipo_d`)';
            $statusSelect = ', so.`id_status` AS `fv_so_id_status`, so.`name_status_spanish` AS `fv_so_name_es`,'
                . ' so.`name_status_english` AS `fv_so_name_en`, so.`color` AS `fv_so_color`,'
                . ' so.`color_icono` AS `fv_so_color_icono`, so.`can_show_data_asigned` AS `fv_so_can_show_assigned`';
        }

        $sql = 'SELECT o.*, c.`business_name` AS `fv_cust_business_name`, c.`name` AS `fv_cust_name`,'
            . ' c.`email` AS `fv_cust_email`, c.`phone` AS `fv_cust_phone`,'
            . ' TRIM(CONCAT(COALESCE(v.`first_name`,\'\'), \' \', COALESCE(v.`last_name`,\'\'))) AS `fv_seller_name`,'
            . ' NULLIF(TRIM(COALESCE(v.`email`,\'\')), \'\') AS `fv_seller_email`'
            . $statusSelect
            . ' FROM ' . $to . ' o'
            . ' LEFT JOIN ' . $tc . ' c ON c.`customer_id` = o.`customer_id`'
            . $vJoin
            . $statusJoin
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY o.`created` DESC';

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $orderRows = $st->fetchAll(\PDO::FETCH_ASSOC);
        if (!is_array($orderRows) || $orderRows === []) {
            return [];
        }

        $orderIds = [];
        foreach ($orderRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $oid = $row['order_id'] ?? null;
            if ($oid !== null && is_numeric($oid)) {
                $orderIds[] = (int) $oid;
            }
            // Algunas BDs guardan order_details.order_id = orders.id (auto_increment), no orders.order_id.
            $iid = $row['id'] ?? null;
            if ($iid !== null && is_numeric($iid)) {
                $orderIds[] = (int) $iid;
            }
        }
        $orderIds = array_values(array_unique($orderIds));
        if ($orderIds === []) {
            return [];
        }

        $detailsByOrder = self::fetchDetailsGrouped($pdo, $orderIds);

        $out = [];
        foreach ($orderRows as $cart) {
            if (!is_array($cart)) {
                continue;
            }
            $oid = isset($cart['order_id']) && is_numeric($cart['order_id']) ? (int) $cart['order_id'] : 0;
            $internalId = isset($cart['id']) && is_numeric($cart['id']) ? (int) $cart['id'] : 0;
            $productList = $detailsByOrder[$oid] ?? [];
            if ($productList === [] && $internalId > 0 && $internalId !== $oid) {
                $productList = $detailsByOrder[$internalId] ?? [];
            }
            $built = self::buildOrderPayload($cart, $productList);
            $out[] = HvOrderUi::enrichOrderRow($built, $lang);
        }

        return $out;
    }

    /**
     * @param list<int> $orderIds
     * @return array<int, list<array<string, mixed>>>
     */
    private static function fetchDetailsGrouped(\PDO $pdo, array $orderIds): array
    {
        $tod = FullVendorDb::sqlTable('order_details');
        $tp = FullVendorDb::sqlTable('products');
        $fk = self::orderDetailsFkIdent();
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $sql = 'SELECT od.*, p.`name` AS `product_name`, p.`sku` AS `product_sku`, p.`barcode` AS `product_barcode`,'
            . ' p.`currency_type` AS `product_currency_type`'
            . ' FROM ' . $tod . ' od'
            . ' LEFT JOIN ' . $tp . ' p ON p.`product_id` = od.`product_id`'
            . ' WHERE od.' . $fk . ' IN (' . $placeholders . ')';

        $st = $pdo->prepare($sql);
        $st->execute($orderIds);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $fkBare = self::orderDetailsFkColumn();
        $by = [];
        foreach ($rows as $pr) {
            if (!is_array($pr)) {
                continue;
            }
            $oid = isset($pr[$fkBare]) && is_numeric($pr[$fkBare]) ? (int) $pr[$fkBare] : 0;
            if ($oid <= 0 && isset($pr['order_id']) && is_numeric($pr['order_id'])) {
                $oid = (int) $pr['order_id'];
            }
            if ($oid <= 0) {
                continue;
            }
            if (!isset($by[$oid])) {
                $by[$oid] = [];
            }
            $by[$oid][] = $pr;
        }

        return $by;
    }

    /**
     * @param array<string, mixed> $cart
     * @param list<array<string, mixed>> $productRows
     * @return array<string, mixed>
     */
    private static function buildOrderPayload(array $cart, array $productRows): array
    {
        $orderId = isset($cart['order_id']) && is_numeric($cart['order_id']) ? (int) $cart['order_id'] : 0;

        $subTotal = 0.0;
        $newarray = [];
        foreach ($productRows as $pr) {
            $qty = (float) ($pr['qty'] ?? 0);
            $sale = (float) ($pr['sale_price'] ?? 0);
            $subTotal += $qty * $sale;

            $pname = (string) ($pr['product_name'] ?? '');
            $cur = FullVendorDb::currencyTypeSymbol($pr['product_currency_type'] ?? null);

            $newarray[] = [
                'order_id' => $orderId,
                'currency_type' => $cur,
                'product_id' => isset($pr['product_id']) && is_numeric($pr['product_id']) ? (int) $pr['product_id'] : 0,
                'name' => strtoupper($pname),
                'sku' => (string) ($pr['product_sku'] ?? ''),
                'qty' => number_format($qty, 2, '.', ''),
                'sale_price' => number_format($sale, 2, '.', ''),
                'fob_price' => number_format((float) ($pr['fob_price'] ?? 0), 2, '.', ''),
                'purchase_price' => number_format((float) ($pr['purchase_price'] ?? 0), 2, '.', ''),
                'barcode' => (string) ($pr['product_barcode'] ?? ''),
                'discount' => number_format((float) ($pr['discount'] ?? 0), 2, '.', ''),
                'discount_type' => $pr['discount_type'] ?? null,
                'comment' => (string) ($pr['comment'] ?? ''),
                'created' => $pr['created'] ?? null,
            ];
        }

        $hdrDiscount = (float) ($cart['discount'] ?? 0);
        $totalAfter = $subTotal - $hdrDiscount;

        $dbTotalAmt = (float) ($cart['total_amount'] ?? 0);
        $totalValue = $dbTotalAmt > 0 ? $dbTotalAmt : $totalAfter;
        $assignedVal = (float) ($cart['total_delivered'] ?? 0);

        $businessName = (string) ($cart['fv_cust_business_name'] ?? '');
        $custName = (string) ($cart['fv_cust_name'] ?? '');
        $custEmail = (string) ($cart['fv_cust_email'] ?? '');
        $custPhone = (string) ($cart['fv_cust_phone'] ?? '');
        $custId = isset($cart['customer_id']) && is_numeric($cart['customer_id']) ? (int) $cart['customer_id'] : 0;

        $sellerRaw = trim(preg_replace('/\s+/u', ' ', (string) ($cart['fv_seller_name'] ?? '')));
        if ($sellerRaw === '') {
            $sellerRaw = trim((string) ($cart['fv_seller_email'] ?? ''));
        }

        $nsEs = trim((string) ($cart['fv_so_name_es'] ?? $cart['name_status_spanish'] ?? ''));
        $nsEn = trim((string) ($cart['fv_so_name_en'] ?? $cart['name_status_english'] ?? ''));
        $stColor = trim((string) ($cart['fv_so_color'] ?? $cart['color'] ?? ''));
        $stIcon = trim((string) ($cart['fv_so_color_icono'] ?? ''));

        return [
            'tipo_d' => $cart['tipo_d'] ?? null,
            'order_id' => $orderId,
            'order_number' => $cart['order_number'] ?? null,
            'order_comments' => $cart['order_comments'] ?? null,
            'ordered_total' => number_format($totalAfter, 2, '.', ''),
            'discount' => number_format($hdrDiscount, 2, '.', ''),
            'discount_a' => number_format((float) ($cart['discount_a'] ?? 0), 2, '.', ''),
            'amount' => number_format($subTotal, 2, '.', ''),
            'total_amount' => number_format($totalValue, 2, '.', ''),
            'total_value' => $totalValue,
            'assigned_value' => $assignedVal,
            'total_delivered' => $assignedVal,
            'discount_type' => $cart['discount_type'] ?? null,
            'business_name' => $businessName,
            'customer_id' => $custId,
            'name' => $custName,
            'seller_name' => $sellerRaw,
            'email' => $custEmail,
            'phone' => $custPhone,
            'created' => $cart['created'] ?? null,
            'updated' => $cart['updated'] ?? null,
            'name_status_spanish' => $nsEs !== '' ? $nsEs : null,
            'name_status_english' => $nsEn !== '' ? $nsEn : null,
            'scolor' => $stColor !== '' ? $stColor : null,
            'status_color' => $stColor !== '' ? $stColor : null,
            'status_icon_color' => $stIcon !== '' ? $stIcon : null,
            'status_orders_id' => isset($cart['fv_so_id_status']) && is_numeric($cart['fv_so_id_status'])
                ? (int) $cart['fv_so_id_status'] : null,
            'can_show_data_assigned' => isset($cart['fv_so_can_show_assigned']) ? (int) $cart['fv_so_can_show_assigned'] : null,
            'order_status' => $cart['order_status'] ?? null,
            'warehouse_user_id' => $cart['warehouse_user_id'] ?? null,
            'warehouse_assign_date' => $cart['warehouse_assign_date'] ?? null,
            'warehouse_name' => $cart['warehouse_name'] ?? null,
            'source' => $cart['source'] ?? 'app',
            'product_list' => $newarray,
            'order_date' => $cart['created'] ?? null,
            'date' => $cart['created'] ?? null,
            'status' => isset($cart['order_status']) ? (string) $cart['order_status'] : '',
            'total' => number_format($totalValue, 2, '.', ''),
            'id' => $orderId,
        ];
    }

    /**
     * Un pedido por order_id con líneas enriquecidas; solo vendedor (dueño) o cliente del pedido.
     *
     * @return array<string, mixed>|null
     */
    public static function getOrderDetail(
        int $orderId,
        int $rolId,
        int $fvUserId,
        int $fvCustomerId,
        string $lang
    ): ?array {
        if ($orderId <= 0) {
            return null;
        }
        if ($rolId !== 1 && $rolId !== 2 && $rolId !== 3) {
            return null;
        }
        if ($rolId === 2 && $fvUserId <= 0) {
            return null;
        }
        if ($rolId === 3 && $fvCustomerId <= 0) {
            return null;
        }

        $pdo = FullVendorDb::pdo();
        $to = FullVendorDb::sqlTable('orders');
        $tc = FullVendorDb::sqlTable('customers');
        $tv = FullVendorDb::sqlTable('vendors');

        // FullVendor: cabecera en `orders`, líneas en `order_details`; enlace único `order_id` (mismo valor en ambas tablas).
        $where = ['o.`order_id` = :order_pk'];
        $params = ['order_pk' => $orderId];

        if ($rolId === 2) {
            $where[] = 'o.`user_id` = :sess_uid';
            $params['sess_uid'] = $fvUserId;
        } elseif ($rolId === 3) {
            $where[] = 'o.`customer_id` = :sess_cid';
            $params['sess_cid'] = $fvCustomerId;
        }

        $skipCompDetail = trim((string) config('FULLVENDOR_DB_ORDER_DETAIL_IGNORE_COMPANY', '')) === '1';
        if (!$skipCompDetail && trim((string) config('FULLVENDOR_DB_ORDERS_IGNORE_COMPANY', '')) !== '1') {
            $cid = self::companyIdForWhere();
            if ($cid !== null) {
                $where[] = 'o.`company_id` = :comp';
                $params['comp'] = $cid;
            }
        }

        // No aplicar filtro tipo_d al detalle: el usuario abre un pedido concreto; el list sí puede filtrar.
        if (trim((string) config('FULLVENDOR_DB_ORDERS_DETAIL_USE_TIPO_D', '')) === '1') {
            $tipo = self::tipoDFilter();
            if ($tipo !== null) {
                $where[] = 'o.`tipo_d` = :tipo_d';
                $params['tipo_d'] = $tipo;
            }
        }

        $vJoin = ' LEFT JOIN ' . $tv . ' v ON v.`user_id` = o.`user_id`';
        if (trim((string) config('FULLVENDOR_DB_VENDOR_JOIN_MATCH_ORDER_COMPANY', '')) === '1') {
            $vJoin .= ' AND v.`company_id` <=> o.`company_id`';
        }

        $statusSelect = '';
        $statusJoin = '';
        if (trim((string) config('FULLVENDOR_DB_ORDERS_SKIP_STATUS_JOIN', '')) !== '1') {
            $tso = FullVendorDb::sqlTable('status_orders');
            $statusJoin = ' LEFT JOIN ' . $tso . ' so ON so.`cod_status` = o.`order_status`'
                . ' AND (IFNULL(TRIM(so.`tipo_d`), \'\') = \'\' OR so.`tipo_d` <=> o.`tipo_d`)';
            $statusSelect = ', so.`id_status` AS `fv_so_id_status`, so.`name_status_spanish` AS `fv_so_name_es`,'
                . ' so.`name_status_english` AS `fv_so_name_en`, so.`color` AS `fv_so_color`,'
                . ' so.`color_icono` AS `fv_so_color_icono`, so.`can_show_data_asigned` AS `fv_so_can_show_assigned`';
        }

        $custExtra = ', c.`cell_phone` AS `fv_cust_cell_phone`,'
            . ' c.`commercial_delivery_address` AS `fv_cust_commercial_delivery_address`,'
            . ' c.`commercial_address` AS `fv_cust_commercial_address`,'
            . ' c.`commercial_city` AS `fv_cust_commercial_city`,'
            . ' c.`commercial_state` AS `fv_cust_commercial_state`,'
            . ' c.`commercial_zip_code` AS `fv_cust_commercial_zip`';

        $wantInternalPkInSelect = trim((string) config('FULLVENDOR_DB_ORDERS_SKIP_INTERNAL_ID_SELECT', '')) !== '1';
        $pkFragments = $wantInternalPkInSelect ? [', o.`id` AS `fv_orders_internal_pk`', ''] : [''];

        $sqlTail = ', c.`business_name` AS `fv_cust_business_name`, c.`name` AS `fv_cust_name`,'
            . ' c.`email` AS `fv_cust_email`, c.`phone` AS `fv_cust_phone`'
            . $custExtra
            . ', TRIM(CONCAT(COALESCE(v.`first_name`,\'\'), \' \', COALESCE(v.`last_name`,\'\'))) AS `fv_seller_name`,'
            . ' NULLIF(TRIM(COALESCE(v.`email`,\'\')), \'\') AS `fv_seller_email`'
            . $statusSelect
            . ' FROM ' . $to . ' o'
            . ' LEFT JOIN ' . $tc . ' c ON c.`customer_id` = o.`customer_id`'
            . $vJoin
            . $statusJoin;

        $cart = null;
        $headerFetchError = null;
        foreach ($pkFragments as $pkFrag) {
            try {
                $sqlBase = 'SELECT o.*' . $pkFrag . $sqlTail;
                $sql = $sqlBase . ' WHERE ' . implode(' AND ', $where) . ' LIMIT 1';
                $st = $pdo->prepare($sql);
                $st->execute($params);
                $row = $st->fetch(\PDO::FETCH_ASSOC);
                $cart = is_array($row) ? $row : null;
                $headerFetchError = null;
                break;
            } catch (Throwable $e) {
                $headerFetchError = $e;
            }
        }
        if ($headerFetchError !== null && $cart === null) {
            throw $headerFetchError;
        }
        if (!is_array($cart)) {
            return null;
        }

        $realOrderId = isset($cart['order_id']) && is_numeric($cart['order_id']) ? (int) $cart['order_id'] : 0;
        if ($realOrderId <= 0) {
            return null;
        }

        // id interno de fila orders (alias explícito; si no vino en el SELECT, consulta aparte).
        $internalRowId = isset($cart['fv_orders_internal_pk']) && is_numeric($cart['fv_orders_internal_pk'])
            ? (int) $cart['fv_orders_internal_pk'] : 0;
        if ($internalRowId <= 0 && isset($cart['id']) && is_numeric($cart['id'])) {
            $internalRowId = (int) $cart['id'];
        }
        if ($internalRowId <= 0) {
            $internalRowId = self::trySelectOrdersInternalId($pdo, $to, $realOrderId);
        }
        $lineRows = self::fetchOrderDetailLines($pdo, $realOrderId, $internalRowId);
        $payload = self::buildOrderDetailPayload($cart, $lineRows);

        return HvOrderUi::enrichOrderRow($payload, $lang);
    }

    /**
     * Líneas del pedido: prueba por clave de negocio, por id interno de orders, y por JOIN (evita duplicados si order_id = id).
     *
     * @return list<array<string, mixed>>
     */
    private static function fetchOrderDetailLines(\PDO $pdo, int $businessOrderId, int $ordersInternalId = 0): array
    {
        $try = self::fetchOrderDetailLinesByOdOrderId($pdo, $businessOrderId);
        if ($try !== []) {
            return $try;
        }
        if ($ordersInternalId > 0) {
            $try = self::fetchOrderDetailLinesByOdOrderId($pdo, $ordersInternalId);
            if ($try !== []) {
                return $try;
            }
        }
        return self::fetchOrderDetailLinesJoinedToOrders($pdo, $businessOrderId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fetchOrderDetailLinesByOdOrderId(\PDO $pdo, int $odOrderId): array
    {
        $tod = FullVendorDb::sqlTable('order_details');
        $tp = FullVendorDb::sqlTable('products');
        $sql = 'SELECT od.*, p.`name` AS `product_name`, p.`sku` AS `product_sku`, p.`barcode` AS `product_barcode`,'
            . ' p.`currency_type` AS `product_currency_type`, p.`stock` AS `product_stock`,'
            . ' NULLIF(TRIM(COALESCE(p.`imgURL`, \'\')), \'\') AS `product_imgurl`,'
            . ' NULLIF(TRIM(COALESCE(p.`simgURL`, \'\')), \'\') AS `product_simgurl`'
            . ' FROM ' . $tod . ' od'
            . ' LEFT JOIN ' . $tp . ' p ON p.`product_id` = od.`product_id`'
            . ' WHERE od.' . self::orderDetailsFkIdent() . ' = :oid ORDER BY od.`detail_id` ASC';
        try {
            $st = $pdo->prepare($sql);
            $st->execute(['oid' => $odOrderId]);

            return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            $sqlNoStock = 'SELECT od.*, p.`name` AS `product_name`, p.`sku` AS `product_sku`, p.`barcode` AS `product_barcode`,'
                . ' p.`currency_type` AS `product_currency_type`,'
                . ' NULLIF(TRIM(COALESCE(p.`imgURL`, \'\')), \'\') AS `product_imgurl`,'
                . ' NULLIF(TRIM(COALESCE(p.`simgURL`, \'\')), \'\') AS `product_simgurl`'
                . ' FROM ' . $tod . ' od'
                . ' LEFT JOIN ' . $tp . ' p ON p.`product_id` = od.`product_id`'
                . ' WHERE od.' . self::orderDetailsFkIdent() . ' = :oid ORDER BY od.`detail_id` ASC';
            try {
                $st2 = $pdo->prepare($sqlNoStock);
                $st2->execute(['oid' => $odOrderId]);

                return $st2->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable) {
                $sqlPlain = 'SELECT od.*, p.`name` AS `product_name`, p.`sku` AS `product_sku`, p.`barcode` AS `product_barcode`,'
                    . ' p.`currency_type` AS `product_currency_type`,'
                    . ' NULLIF(TRIM(COALESCE(p.`imgURL`, \'\')), \'\') AS `product_imgurl`,'
                    . ' NULLIF(TRIM(COALESCE(p.`simgURL`, \'\')), \'\') AS `product_simgurl`'
                    . ' FROM ' . $tod . ' od'
                    . ' LEFT JOIN ' . $tp . ' p ON p.`product_id` = od.`product_id`'
                    . ' WHERE od.' . self::orderDetailsFkIdent() . ' = :oid';
                try {
                    $st3 = $pdo->prepare($sqlPlain);
                    $st3->execute(['oid' => $odOrderId]);

                    return $st3->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                } catch (Throwable) {
                    return [];
                }
            }
        }
    }

    /**
     * Une líneas por orders.order_id O por orders.id sin duplicar cuando ambos campos tienen el mismo valor.
     *
     * @return list<array<string, mixed>>
     */
    private static function fetchOrderDetailLinesJoinedToOrders(\PDO $pdo, int $businessOrderId): array
    {
        if ($businessOrderId <= 0) {
            return [];
        }
        $tod = FullVendorDb::sqlTable('order_details');
        $to = FullVendorDb::sqlTable('orders');
        $tp = FullVendorDb::sqlTable('products');
        $fk = self::orderDetailsFkIdent();
        $joinFull = '(od.' . $fk . ' = o.`order_id` OR (NOT (o.`order_id` <=> o.`id`) AND od.' . $fk . ' = o.`id`))';
        $joinSimple = 'od.' . $fk . ' = o.`order_id`';

        $variants = [
            ['join' => $joinFull, 'stock' => true],
            ['join' => $joinFull, 'stock' => false],
            ['join' => $joinSimple, 'stock' => true],
            ['join' => $joinSimple, 'stock' => false],
        ];

        foreach ($variants as $v) {
            $stockSel = $v['stock'] ? ', p.`stock` AS `product_stock`' : '';
            $sql = 'SELECT od.*, p.`name` AS `product_name`, p.`sku` AS `product_sku`, p.`barcode` AS `product_barcode`,'
                . ' p.`currency_type` AS `product_currency_type`' . $stockSel . ', '
                . ' NULLIF(TRIM(COALESCE(p.`imgURL`, \'\')), \'\') AS `product_imgurl`,'
                . ' NULLIF(TRIM(COALESCE(p.`simgURL`, \'\')), \'\') AS `product_simgurl`'
                . ' FROM ' . $tod . ' od'
                . ' INNER JOIN ' . $to . ' o ON ' . $v['join']
                . ' LEFT JOIN ' . $tp . ' p ON p.`product_id` = od.`product_id`'
                . ' WHERE o.`order_id` = :bid ORDER BY od.`detail_id` ASC';
            try {
                $st = $pdo->prepare($sql);
                $st->execute(['bid' => $businessOrderId]);
                $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

                return is_array($rows) ? $rows : [];
            } catch (Throwable) {
                continue;
            }
        }

        return [];
    }

    /**
     * @param list<int|string> $productIds
     * @return array<int, string>
     */
    private static function fetchFirstProductImageUrls(\PDO $pdo, array $productIds, int|string $companyId): array
    {
        $ids = [];
        foreach ($productIds as $x) {
            if (is_numeric($x) && (int) $x > 0) {
                $ids[(int) $x] = true;
            }
        }
        $ids = array_keys($ids);
        if ($ids === []) {
            return [];
        }
        try {
            $tbl = FullVendorDb::sqlTable('product_images');
        } catch (Throwable) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'SELECT `product_id`, `images` FROM ' . $tbl
            . ' WHERE `product_id` IN (' . $ph . ') ORDER BY `product_id` ASC, `img_order` ASC, `img_id` ASC';
        try {
            $st = $pdo->prepare($sql);
            $st->execute($ids);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $pid = (int) ($r['product_id'] ?? 0);
            if ($pid <= 0 || isset($out[$pid])) {
                continue;
            }
            $out[$pid] = FullVendorDb::productImagePublicUrl(trim((string) ($r['images'] ?? '')), $companyId);
        }

        return $out;
    }

    private static function absolutizeInlineProductUrl(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '' || preg_match('#^https?://#i', $url)) {
            return $url;
        }
        $pb = trim((string) config('FULLVENDOR_IMAGES_PRODUCTS_URL', ''));
        if ($pb !== '') {
            return rtrim($pb, '/') . '/' . ltrim($url, '/');
        }
        $root = rtrim((string) config('FULLVENDOR_BASE_URL', ''), '/');

        return $root !== '' ? $root . '/' . ltrim($url, '/') : $url;
    }

    private static function formatCustomerAddress(array $cart): string
    {
        $line1 = trim((string) ($cart['fv_cust_commercial_delivery_address'] ?? ''));
        if ($line1 !== '') {
            return $line1;
        }
        $parts = array_values(array_filter([
            trim((string) ($cart['fv_cust_commercial_address'] ?? '')),
            trim((string) ($cart['fv_cust_commercial_city'] ?? '')),
            trim((string) ($cart['fv_cust_commercial_state'] ?? '')),
            trim((string) ($cart['fv_cust_commercial_zip'] ?? '')),
        ], static fn (string $x): bool => $x !== ''));

        return implode(', ', $parts);
    }

    /**
     * @param array<string, mixed> $cart
     * @param list<array<string, mixed>> $lineRows
     * @return array<string, mixed>
     */
    private static function buildOrderDetailPayload(array $cart, array $lineRows): array
    {
        $orderId = isset($cart['order_id']) && is_numeric($cart['order_id']) ? (int) $cart['order_id'] : 0;
        $companyRaw = $cart['company_id'] ?? null;
        $companyId = ($companyRaw !== null && $companyRaw !== '' && is_numeric($companyRaw))
            ? $companyRaw : trim((string) config('FULLVENDOR_COMPANY_ID', '0'));

        $pdo = FullVendorDb::pdo();
        $pids = [];
        foreach ($lineRows as $row) {
            if (is_array($row) && isset($row['product_id']) && is_numeric($row['product_id'])) {
                $pids[] = (int) $row['product_id'];
            }
        }
        $imgMap = self::fetchFirstProductImageUrls($pdo, $pids, $companyId);

        $base = self::buildOrderPayload($cart, $lineRows);
        $base['delivery_notes'] = (string) ($cart['delivery_notes'] ?? '');
        $detailLines = [];
        $sumLineAssigned = 0.0;

        $idx = 0;
        foreach ($lineRows as $pr) {
            if (!is_array($pr)) {
                continue;
            }
            ++$idx;
            $qty = (float) ($pr['qty'] ?? 0);
            $dqty = (float) ($pr['delivered_quantity'] ?? 0);
            $sale = (float) ($pr['sale_price'] ?? 0);
            $pid = isset($pr['product_id']) && is_numeric($pr['product_id']) ? (int) $pr['product_id'] : 0;

            $img = $imgMap[$pid] ?? '';
            if ($img === '' || str_contains($img, 'noimg')) {
                $u1 = self::absolutizeInlineProductUrl($pr['product_imgurl'] ?? null);
                if ($u1 !== '') {
                    $img = $u1;
                } else {
                    $u2 = self::absolutizeInlineProductUrl($pr['product_simgurl'] ?? null);
                    if ($u2 !== '') {
                        $img = $u2;
                    }
                }
            }
            if ($img === '') {
                $img = FullVendorDb::productImagePublicUrl('', $companyId);
            }

            $totalLine = (float) ($pr['total_amount'] ?? 0);
            if ($totalLine <= 0) {
                $totalLine = $qty * $sale;
            }
            $totalAsg = (float) ($pr['total_delivered'] ?? 0);
            if ($totalAsg <= 0 && $dqty > 0) {
                $totalAsg = $dqty * $sale;
            }
            $sumLineAssigned += $totalAsg;

            $modifyDate = $pr['modify_date'] ?? null;
            $fixedDate = $pr['fixed_pricedate'] ?? null;
            $lineNote = trim((string) ($pr['comments'] ?? ''));
            if ($lineNote === '') {
                $lineNote = trim((string) ($pr['comment'] ?? ''));
            }

            $stockVal = $pr['product_stock'] ?? null;
            $stockNum = is_numeric($stockVal) ? (float) $stockVal : null;

            $detailLines[] = [
                'line_no' => $idx,
                'product_id' => $pid,
                'image_url' => $img,
                'name' => strtoupper((string) ($pr['product_name'] ?? '')),
                'sku' => (string) ($pr['product_sku'] ?? ''),
                'barcode' => (string) ($pr['product_barcode'] ?? ''),
                'quantity' => $qty,
                'quantity_formatted' => number_format($qty, 2, '.', ''),
                'pack' => (float) ($pr['pack'] ?? 0),
                'pack_formatted' => number_format((float) ($pr['pack'] ?? 0), 2, '.', ''),
                'quantity_assigned' => $dqty,
                'quantity_assigned_formatted' => number_format($dqty, 2, '.', ''),
                'stock' => $stockNum,
                'stock_formatted' => $stockNum !== null ? number_format($stockNum, 2, '.', '') : '',
                'sale_price' => $sale,
                'sale_price_formatted' => number_format($sale, 2, '.', ''),
                'price_modified' => $modifyDate !== null && $modifyDate !== '' && $modifyDate !== '0000-00-00 00:00:00',
                'price_fixed' => $fixedDate !== null && $fixedDate !== '' && $fixedDate !== '0000-00-00 00:00:00',
                'total_order' => $totalLine,
                'total_order_formatted' => number_format($totalLine, 2, '.', ''),
                'total_assigned' => $totalAsg,
                'total_assigned_formatted' => number_format($totalAsg, 2, '.', ''),
                'line_comment' => $lineNote,
                'currency_type' => FullVendorDb::currencyTypeSymbol($pr['product_currency_type'] ?? null),
            ];
        }

        $subOrder = (float) ($cart['amount'] ?? 0);
        if ($subOrder <= 0) {
            $subOrder = 0.0;
            foreach ($detailLines as $dl) {
                $subOrder += (float) ($dl['quantity'] ?? 0) * (float) ($dl['sale_price'] ?? 0);
            }
        }

        $subAssignedDb = (float) ($cart['amount_delivered'] ?? 0);
        $subAssigned = $subAssignedDb > 0 ? $subAssignedDb : $sumLineAssigned;

        $discOrder = (float) ($cart['discount'] ?? 0);
        $discAssigned = (float) ($cart['discount_delivered'] ?? 0);
        $totalAssignedHdr = (float) ($cart['total_delivered'] ?? 0);

        $dt = (int) ($cart['discount_type'] ?? 1);
        if ($dt === 1) {
            $discountLabel = number_format($discOrder, 2, '.', '') . '%';
        } else {
            $discountLabel = number_format($discOrder, 2, '.', '');
        }

        $base['detail_lines'] = $detailLines;
        $base['customer_address_line'] = self::formatCustomerAddress($cart);
        $base['customer_cell_phone'] = trim((string) ($cart['fv_cust_cell_phone'] ?? ''));
        $base['internal_notes'] = (string) ($cart['internal_notes'] ?? '');
        $base['delivery_notes'] = (string) ($cart['delivery_notes'] ?? '');
        $base['summary'] = [
            'subtotal_order' => $subOrder,
            'subtotal_order_formatted' => number_format($subOrder, 2, '.', ''),
            'subtotal_assigned' => $subAssigned,
            'subtotal_assigned_formatted' => number_format($subAssigned, 2, '.', ''),
            'discount_order' => $discOrder,
            'discount_order_formatted' => number_format($discOrder, 2, '.', ''),
            'discount_assigned' => $discAssigned,
            'discount_assigned_formatted' => number_format($discAssigned, 2, '.', ''),
            'discount_label_suffix' => $discountLabel,
            'total_order' => (float) $base['total_value'],
            'total_order_formatted' => (string) ($base['total_amount'] ?? ''),
            'total_assigned' => $totalAssignedHdr,
            'total_assigned_formatted' => number_format($totalAssignedHdr, 2, '.', ''),
        ];

        return $base;
    }
}
