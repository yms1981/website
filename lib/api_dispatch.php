<?php

declare(strict_types=1);

// Despacho central de la API REST (rutas tipo Next.js app/api).
// Cada PHP en la carpeta api asigna __path y carga api/_bootstrap.php.

/**
 * @return array{rolId:int,roleKey:string,roleLabel:string,fields:list<array{key:string,label:string,value:string}>}
 */
function hv_api_profile_payload(string $email, string $lang): array
{
    require_once __DIR__ . '/UserProfile.php';

    return UserProfile::barPayloadForEmail($email, $lang);
}

function hv_api_dispatch(): void
{
    $path = $_GET['__path'] ?? '';
    $parts = array_values(array_filter(explode('/', (string) $path)));
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    try {
        if ($method === 'GET' && ($parts === ['products'] || $parts === ['categorylist'])) {
            require_once dirname(__DIR__) . '/lib/HvBackgroundSync.php';
            HvBackgroundSync::scheduleThrottledCustomerUsersRefresh();
        }

        if ($parts === ['warmup'] && $method === 'GET') {
            json_response(['ok' => true]);
        }

        // Sincronización periódica vía HTTP (ej. cron en la nube): misma lógica que scripts/periodic_fullvendor_sync.php
        if ($parts === ['cron', 'fullvendor-sync'] && ($method === 'GET' || $method === 'POST')) {
            $secret = trim(config('CRON_SYNC_SECRET', ''));
            if ($secret === '') {
                json_response(['error' => 'CRON_SYNC_SECRET no definido en .env (endpoint desactivado)'], 403);
            }
            $given = (string) ($_GET['key'] ?? ($_SERVER['HTTP_X_CRON_SECRET'] ?? ''));
            if ($given === '' || !hash_equals($secret, $given)) {
                json_response(['error' => 'Forbidden'], 403);
            }
            require_once dirname(__DIR__) . '/lib/PeriodicFullVendorSync.php';
            $run = PeriodicFullVendorSync::run();
            $code = PeriodicFullVendorSync::hadProblems($run) ? 500 : 200;
            json_response([
                'ok' => $code === 200,
                'summary' => PeriodicFullVendorSync::summarize($run),
            ], $code);
        }

        if ($parts === ['customers', 'sync'] && $method === 'POST') {
            require_admin_session();
            api_require_fullvendor();
            if (!Db::enabled()) {
                json_response(['error' => 'Database not configured'], 500);
            }
            require_once dirname(__DIR__) . '/lib/CustomerSync.php';
            $stats = CustomerSync::syncFromFullVendor();
            $ok = count($stats['errors'] ?? []) === 0 && (int) ($stats['upserted'] ?? 0) > 0;
            json_response(array_merge(['success' => $ok], $stats));
        }

        if ($parts === ['customer-groups', 'sync'] && $method === 'POST') {
            require_admin_session();
            if (!Db::enabled()) {
                json_response(['error' => 'Database not configured'], 500);
            }
            if (fullvendor_data_source() === 'db') {
                if (!fullvendor_db_configured()) {
                    json_response(['error' => 'FULLVENDOR_DB_* no configurado (modo db)'], 503);
                }
            } else {
                api_require_fullvendor();
            }
            require_once dirname(__DIR__) . '/lib/CustomerGroupsSync.php';
            $stats = CustomerGroupsSync::syncFromFullVendor();
            $ok = count($stats['errors'] ?? []) === 0;
            json_response(array_merge(['success' => $ok], $stats));
        }

        if ($parts === ['customer-groups'] && $method === 'GET') {
            $session = Auth::getSession();
            if ($session === null || empty($session['approved'])) {
                json_response(['error' => 'Unauthorized'], 401);
            }
            require_once dirname(__DIR__) . '/lib/CustomerGroupsLocal.php';
            $lang = $_GET['lang'] ?? 'en';
            json_response([
                'list' => CustomerGroupsLocal::list(lang_to_id($lang)),
            ]);
        }

        if ($parts === ['messages'] && $method === 'GET') {
            $session = Auth::getSession();
            if ($session === null || empty($session['approved'])) {
                json_response(['error' => 'Unauthorized'], 401);
            }
            require_once dirname(__DIR__) . '/lib/Messaging.php';
            Messaging::requireMessagingRole($session);
            if (!Db::enabled()) {
                json_response(['error' => 'Database not configured'], 500);
            }
            $langMsg = $_GET['lang'] ?? 'en';
            $GLOBALS['hv_lang'] = $langMsg === 'es' ? 'es' : 'en';
            $pdo = Db::pdo();
            if (!empty($_GET['contacts'])) {
                json_response(['contacts' => Messaging::listContacts($pdo, $session)]);
            }
            if (!empty($_GET['badge'])) {
                json_response(['unread' => Messaging::totalUnreadBadge($pdo, $session)]);
            }
            $convId = (int) ($_GET['conversation'] ?? 0);
            if ($convId > 0) {
                $after = (int) ($_GET['after'] ?? 0);
                $uid = Messaging::localUserId($session);
                $msgs = Messaging::listMessages($pdo, $convId, $uid, $after, 120);
                $peerRead = Messaging::peerLastReadMessageId($pdo, $convId, $uid);
                json_response(['messages' => $msgs, 'peerLastReadMessageId' => $peerRead]);
            }
            json_response(['conversations' => Messaging::listConversations($pdo, $session)]);
        }

        if ($parts === ['messages'] && $method === 'POST') {
            $session = Auth::getSession();
            if ($session === null || empty($session['approved'])) {
                json_response(['error' => 'Unauthorized'], 401);
            }
            require_once dirname(__DIR__) . '/lib/Messaging.php';
            Messaging::requireMessagingRole($session);
            if (!Db::enabled()) {
                json_response(['error' => 'Database not configured'], 500);
            }
            $b = read_json_body();
            $GLOBALS['hv_lang'] = (isset($b['lang']) && $b['lang'] === 'es') ? 'es' : 'en';
            $pdo = Db::pdo();
            $res = Messaging::sendText(
                $pdo,
                $session,
                (int) ($b['conversationId'] ?? 0),
                (int) ($b['otherUserId'] ?? 0),
                (string) ($b['body'] ?? '')
            );
            if (empty($res['ok'])) {
                json_response(['error' => (string) ($res['error'] ?? 'Failed')], 400);
            }
            json_response($res);
        }

        if ($parts === ['messages', 'read'] && $method === 'POST') {
            $session = Auth::getSession();
            if ($session === null || empty($session['approved'])) {
                json_response(['error' => 'Unauthorized'], 401);
            }
            require_once dirname(__DIR__) . '/lib/Messaging.php';
            Messaging::requireMessagingRole($session);
            if (!Db::enabled()) {
                json_response(['error' => 'Database not configured'], 500);
            }
            $b = read_json_body();
            $cid = (int) ($b['conversationId'] ?? 0);
            $mid = (int) ($b['messageId'] ?? 0);
            $me = Messaging::localUserId($session);
            if ($cid <= 0 || $mid <= 0 || $me <= 0) {
                json_response(['error' => 'Invalid payload'], 400);
            }
            Messaging::markRead(Db::pdo(), $cid, $me, $mid);
            json_response(['ok' => true]);
        }

        if ($parts === ['messages', 'upload'] && $method === 'POST') {
            $session = Auth::getSession();
            if ($session === null || empty($session['approved'])) {
                json_response(['error' => 'Unauthorized'], 401);
            }
            require_once dirname(__DIR__) . '/lib/Messaging.php';
            Messaging::requireMessagingRole($session);
            if (!Db::enabled()) {
                json_response(['error' => 'Database not configured'], 500);
            }
            $langUp = $_POST['lang'] ?? 'en';
            $GLOBALS['hv_lang'] = $langUp === 'es' ? 'es' : 'en';
            Messaging::handleMultipartUpload(Db::pdo(), $session);
        }

        if ($parts === ['seller-catalog-state'] && ($method === 'GET' || $method === 'POST')) {
            $session = Auth::getSession();
            if ($session === null || empty($session['approved'])) {
                json_response(['error' => 'Unauthorized'], 401);
            }
            if ((int) ($session['rolId'] ?? 0) !== 2) {
                json_response(['error' => 'Forbidden'], 403);
            }
            if (!Db::enabled()) {
                json_response(['error' => 'Database not configured'], 500);
            }
            $uid = (int) ($session['userId'] ?? 0);
            $cid = (int) ($session['companyId'] ?? 0);
            if ($uid <= 0) {
                json_response(['error' => 'Invalid session'], 400);
            }
            require_once dirname(__DIR__) . '/lib/SellerCatalogUserState.php';
            $pdo = Db::pdo();
            if ($method === 'GET') {
                $st = SellerCatalogUserState::get($pdo, $uid, $cid);
                json_response([
                    'rowExists' => $st['row_exists'],
                    'selectedCustomerFv' => $st['selected_customer_fv'],
                    'carts' => $st['carts'],
                    'orderNotes' => $st['order_notes'],
                ]);
            }
            $body = read_json_body();
            $merged = SellerCatalogUserState::patch($pdo, $uid, $cid, $body);
            json_response([
                'ok' => true,
                'rowExists' => true,
                'selectedCustomerFv' => $merged['selected_customer_fv'],
                'carts' => $merged['carts'],
                'orderNotes' => $merged['order_notes'],
            ]);
        }

        if ($parts === ['customer-catalog-state'] && ($method === 'GET' || $method === 'POST')) {
            $session = Auth::getSession();
            if ($session === null || empty($session['approved'])) {
                json_response(['error' => 'Unauthorized'], 401);
            }
            if ((int) ($session['rolId'] ?? 0) !== 3) {
                json_response(['error' => 'Forbidden'], 403);
            }
            if (!Db::enabled()) {
                json_response(['error' => 'Database not configured'], 500);
            }
            $customerFvId = (int) ($session['customerId'] ?? 0);
            $cid = (int) ($session['companyId'] ?? 0);
            if ($customerFvId <= 0) {
                json_response(['error' => 'No customer linked to this account'], 403);
            }
            require_once dirname(__DIR__) . '/lib/CustomerCatalogUserState.php';
            $pdo = Db::pdo();
            if ($method === 'GET') {
                $st = CustomerCatalogUserState::get($pdo, $customerFvId, $cid);
                json_response([
                    'rowExists' => $st['row_exists'],
                    'primarySellerUserId' => $st['primary_seller_user_id'],
                    'carts' => $st['carts'],
                    'orderNotes' => $st['order_notes'],
                ]);
            }
            $body = read_json_body();
            $merged = CustomerCatalogUserState::patch($pdo, $customerFvId, $cid, $body);
            json_response([
                'ok' => true,
                'rowExists' => true,
                'primarySellerUserId' => $merged['primary_seller_user_id'],
                'carts' => $merged['carts'],
                'orderNotes' => $merged['order_notes'],
            ]);
        }

        if ($parts === ['catalog', 'sync', 'status'] && $method === 'GET') {
            require_admin_session();
            if (!Db::enabled()) {
                json_response(['error' => 'Database not configured'], 500);
            }
            require_once dirname(__DIR__) . '/lib/CatalogSync.php';
            json_response(['state' => CatalogSync::getSyncState()]);
        }

        if ($parts === ['catalog', 'sync'] && $method === 'POST') {
            require_admin_session();
            api_require_fullvendor();
            if (!Db::enabled()) {
                json_response(['error' => 'Database not configured'], 500);
            }
            require_once dirname(__DIR__) . '/lib/CatalogSync.php';
            $body = read_json_body();
            if (!empty($body['foreground'])) {
                $stats = CatalogSync::syncFromFullVendor();
                CatalogSync::recordForegroundSyncResult($stats);
                $total = (int) ($stats['categories_upserted'] ?? 0) + (int) ($stats['products_upserted'] ?? 0);
                $ok = count($stats['errors'] ?? []) === 0 && $total > 0;
                json_response(array_merge(
                    ['success' => $ok, 'foreground' => true, 'state' => CatalogSync::getSyncState()],
                    $stats
                ));
            }

            $claim = CatalogSync::claimBackgroundSyncOrBusy();
            if (!$claim['claimed']) {
                json_response([
                    'success' => false,
                    'busy' => true,
                    'message' => 'Ya hay una sincronización de catálogo en curso.',
                    'state' => CatalogSync::getSyncState(),
                ], 409);
            }

            if (CatalogSync::trySpawnWorkerCli()) {
                json_response([
                    'success' => true,
                    'background' => true,
                    'spawn' => true,
                    'message' => 'Sincronización del catálogo iniciada en segundo plano.',
                    'state' => CatalogSync::getSyncState(),
                ], 202);
            }

            $pdo = Db::pdo();
            if (function_exists('fastcgi_finish_request')) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
                http_response_code(202);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => true,
                    'background' => true,
                    'mode' => 'fpm',
                    'message' => 'Sincronización en curso; la respuesta ya se envió al navegador.',
                    'state' => CatalogSync::getSyncState($pdo),
                ], JSON_UNESCAPED_UNICODE);
                if (ob_get_level() > 0) {
                    while (ob_get_level() > 0) {
                        ob_end_flush();
                    }
                }
                flush();
                fastcgi_finish_request();
                CatalogSync::runWorkerJob();
                exit;
            }

            CatalogSync::releaseClaimAfterSpawnFailure(
                $pdo,
                'No se pudo lanzar el proceso PHP en segundo plano (CLI no encontrado).'
            );
            json_response([
                'success' => false,
                'error' => 'spawn_failed',
                'message' => 'No se pudo iniciar el worker. En Windows/XAMPP define PHP_CLI_PATH en .env con la ruta a php.exe (ej. C:\\xampp\\php\\php.exe).',
                'state' => CatalogSync::getSyncState($pdo),
            ], 503);
        }

        if ($parts === ['usersList', 'sync'] && $method === 'POST') {
            require_admin_session();
            api_require_fullvendor();
            if (!Db::enabled()) {
                json_response(['error' => 'Database not configured'], 500);
            }
            require_once dirname(__DIR__) . '/lib/UsersListSync.php';
            $stats = UsersListSync::syncFromFullVendor();
            $ok = count($stats['errors'] ?? []) === 0 && (int) ($stats['userslist_upserted'] ?? 0) > 0;
            json_response(array_merge(['success' => $ok], $stats));
        }

        if ($parts === ['whatsapp'] && $method === 'POST') {
            $ip = isset($_SERVER['HTTP_X_FORWARDED_FOR'])
                ? trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0])
                : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            if (!RateLimit::allow('wa-' . $ip, 3, 60)) {
                json_response(['error' => 'Too many requests. Please wait.'], 429);
            }
            $b = read_json_body();
            $name = trim((string) ($b['name'] ?? ''));
            $message = trim((string) ($b['message'] ?? ''));
            $phone = trim((string) ($b['phone'] ?? ''));
            if ($name === '' || strlen($name) > 200 || $message === '' || strlen($message) > 1000) {
                json_response(['error' => 'Invalid input'], 400);
            }
            $full = 'New inquiry from ' . $name . ($phone !== '' ? " ({$phone})" : '') . ":\n\n" . $message;
            $num = whatsapp_business_number();
            if (!Birdview::sendWhatsApp($num, $full)) {
                json_response(['error' => 'Failed to send message'], 500);
            }
            json_response(['success' => true]);
        }

        if ($parts === ['auth', 'login'] && $method === 'POST') {
            require_once dirname(__DIR__) . '/lib/WebLogin.php';
            $body = read_json_body();
            $email = (string) ($body['email'] ?? '');
            $password = (string) ($body['password'] ?? '');
            $lang = (($body['lang'] ?? '') === 'es') ? 'es' : 'en';
            $r = WebLogin::attempt($email, $password, $lang);
            if (!$r['success']) {
                json_response(['error' => 'Invalid email or password'], 401);
            }
            $sess = Auth::getSession();
            $emailProfile = (string) ($sess['email'] ?? '');
            if ($emailProfile === '') {
                json_response(['error' => 'Invalid email or password'], 500);
            }
            $profile = hv_api_profile_payload($emailProfile, $lang);
            if (!empty($r['pending'])) {
                json_response([
                    'success' => true,
                    'pending' => true,
                    'user' => ['name' => $r['name']],
                    'profile' => $profile,
                ]);
            }
            json_response([
                'success' => true,
                'user' => ['name' => $r['name']],
                'profile' => $profile,
            ]);
        }

        if ($parts === ['auth', 'profile'] && $method === 'GET') {
            $session = Auth::getSession();
            if ($session === null) {
                json_response(['error' => 'Unauthorized'], 401);
            }
            $langP = ($_GET['lang'] ?? 'en') === 'es' ? 'es' : 'en';
            json_response(['profile' => hv_api_profile_payload($session['email'], $langP)]);
        }

        if ($parts === ['auth', 'logout'] && ($method === 'POST' || $method === 'GET')) {
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            Auth::destroySession();
            if ($method === 'GET') {
                $langL = ($_GET['lang'] ?? 'en') === 'es' ? 'es' : 'en';
                header('Location: ' . base_url() . '/' . $langL . '?hv_clear_cart=1&_=' . time(), true, 302);
                exit;
            }
            json_response(['success' => true]);
        }

        if ($parts === ['auth', 'register'] && $method === 'POST') {
            $b = read_json_body();
            $contactName = trim((string) ($b['contactName'] ?? ''));
            $companyName = trim((string) ($b['companyName'] ?? ''));
            $email = filter_var($b['email'] ?? '', FILTER_VALIDATE_EMAIL);
            $password = (string) ($b['password'] ?? '');
            if ($contactName === '' || $companyName === '' || !$email || strlen($password) < 6) {
                json_response(['error' => 'Invalid registration data'], 400);
            }
            $taxId = trim((string) ($b['taxId'] ?? ''));
            $address = trim((string) ($b['address'] ?? ''));
            $phone = trim((string) ($b['phone'] ?? ''));
            $mobile = trim((string) ($b['mobile'] ?? ''));
            $hasWhatsapp = !empty($b['hasWhatsapp']);
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $registrationId = null;
            if (Db::enabled()) {
                try {
                    $st = Db::pdo()->prepare(
                        'INSERT INTO pending_registrations (contact_name, company_name, tax_id, address, email, phone, mobile, has_whatsapp, password_hash, status)
                         VALUES (?,?,?,?,?,?,?,?,?,?)'
                    );
                    $st->execute([$contactName, $companyName, $taxId, $address, $email, $phone, $mobile, $hasWhatsapp ? 1 : 0, $hash, 'pending']);
                    $registrationId = (int) Db::pdo()->lastInsertId();
                } catch (Throwable) {
                    json_response(['error' => 'Could not save registration (email may already exist).'], 400);
                }
            }
            if ($registrationId !== null) {
                if (!EmailService::sendRegistrationNotification([
                    'registrationId' => $registrationId,
                    'contactName' => $contactName,
                    'companyName' => $companyName,
                    'taxId' => $taxId,
                    'email' => $email,
                    'phone' => $phone,
                    'mobile' => $mobile,
                    'address' => $address,
                    'hasWhatsapp' => $hasWhatsapp,
                ])) {
                    json_response(['error' => 'Failed to submit registration. Please try again.'], 500);
                }
            } else {
                $html = '<div style="font-family:sans-serif;"><h2>New Registration</h2><p>' . htmlspecialchars($companyName) . '</p><p>' . htmlspecialchars($email) . '</p></div>';
                if (!Birdview::sendEmail([require_env('ADMIN_EMAIL')], 'New Registration: ' . $companyName, $html, true)) {
                    json_response(['error' => 'Failed to submit registration. Please try again.'], 500);
                }
            }
            json_response(['success' => true, 'message' => 'Registration submitted']);
        }

        if ($parts === ['categories'] && $method === 'GET') {
            $lang = $_GET['lang'] ?? 'en';
            $lid = lang_to_id($lang);
            if (!fullvendor_configured()) {
                json_response(['categories' => []]);
            }
            header('Cache-Control: private, no-store, max-age=0');
            $cats = CatalogCache::getCategories($lid);
            json_response(['categories' => $cats]);
        }

        if ($parts === ['categorylist'] && $method === 'GET') {
            $lang = $_GET['lang'] ?? 'en';
            $lid = lang_to_id($lang);
            if (!fullvendor_configured()) {
                json_response(['list' => []]);
            }
            header('Cache-Control: private, no-store, max-age=0');
            $list = CatalogCache::getCategories($lid);
            json_response(['list' => $list]);
        }

        if ($parts === ['products'] && $method === 'GET') {
            $lang = $_GET['lang'] ?? 'en';
            $lid = lang_to_id($lang);
            $session = Auth::getSession();
            $products = CatalogCache::getProducts($lid);
            $data = ($session !== null && !empty($session['approved'])) ? $products : strip_prices($products);
            header('Cache-Control: private, no-store, max-age=0');
            json_response(['products' => $data]);
        }

        /** POST JSON como productList_post (FullVendor): language_id, company_id; opcional category_id, customer_id */
        if ($parts === ['productList'] && $method === 'POST') {
            if (!fullvendor_configured()) {
                json_response(['status' => '0', 'error' => 'Catalog not configured'], 503);
            }
            if (fullvendor_data_source() === 'api' && !fullvendor_api_configured()) {
                json_response(['status' => '0', 'error' => 'FullVendor API not configured'], 503);
            }
            $b = read_json_body();
            $res = FullVendor::productList($b);
            if (($res['status'] ?? '') === '1') {
                header('Cache-Control: private, no-store, max-age=0');
                json_response($res, 200);
            }
            $err = strip_tags((string) ($res['error'] ?? 'No Data found. '));
            $low = strtolower($err);
            $isClient = str_contains($low, 'required') || str_contains($low, 'invalid company');
            json_response(['status' => '0', 'error' => $err], $isClient ? 400 : 404);
        }

        if (isset($parts[0], $parts[1]) && $parts[0] === 'products' && $method === 'GET') {
            api_require_fullvendor();
            $id = (int) $parts[1];
            $lang = $_GET['lang'] ?? 'en';
            if ($id <= 0) {
                json_response(['error' => 'Not found'], 404);
            }
            $res = FullVendor::getProductDetails($id, lang_to_id($lang));
            $product = $res['details'] ?? $res['info'] ?? null;
            if (($res['status'] ?? '') !== '1' || !is_array($product)) {
                json_response(['error' => 'Product not found'], 404);
            }
            $session = Auth::getSession();
            if ($session === null || empty($session['approved'])) {
                unset($product['sale_price'], $product['sale_price0'], $product['fob_price'], $product['purchase_price']);
            }
            json_response(['product' => $product]);
        }

        if ($parts === ['cart'] && in_array($method, ['GET', 'POST', 'DELETE'], true)) {
            require_once dirname(__DIR__) . '/lib/LocalCart.php';
            LocalCart::dispatch();
        }

        if ($parts === ['orders'] && $method === 'GET') {
            api_require_fullvendor();
            $session = Auth::getSession();
            if ($session === null || empty($session['approved'])) {
                json_response(['error' => 'Unauthorized'], 401);
            }
            $lang = (string) ($_GET['lang'] ?? 'en');
            try {
                $res = FullVendor::getOrders($session['userId'], $session['customerId'], lang_to_id($lang));
            } catch (Throwable $e) {
                $msg = (string) $e->getMessage();
                // FullVendor responde status=0 cuando no hay pedidos ("No Order found"/"No Data found").
                if (stripos($msg, 'No Order found') !== false || stripos($msg, 'No Data found') !== false) {
                    json_response(['orders' => []]);
                }
                throw $e;
            }
            $list = $res['order_list'] ?? [];
            require_once dirname(__DIR__) . '/lib/HvOrderUi.php';
            $ordersOut = [];
            foreach ($list as $item) {
                if (is_array($item)) {
                    $ordersOut[] = HvOrderUi::enrichOrderRow($item, $lang);
                }
            }
            json_response(['orders' => $ordersOut]);
        }

        /** Detalle de un pedido desde MySQL (mismo alcance que listado from-db). */
        if ($method === 'GET' && count($parts) === 3 && $parts[0] === 'orders' && $parts[1] === 'from-db'
            && ctype_digit((string) $parts[2])) {
            if (!fullvendor_db_configured()) {
                json_response(['error' => 'Base de datos FullVendor no configurada (FULLVENDOR_DB_HOST, NAME, USER)'], 503);
            }
            $session = Auth::getSession();
            if ($session === null || empty($session['approved'])) {
                json_response(['error' => 'Unauthorized'], 401);
            }
            $rol = (int) ($session['rolId'] ?? 0);
            $lang = (string) ($_GET['lang'] ?? 'en');
            $orderId = (int) $parts[2];
            if ($orderId <= 0) {
                json_response(['error' => 'Invalid order id'], 400);
            }
            require_once dirname(__DIR__) . '/lib/FullVendorOrdersDb.php';
            try {
                $detail = FullVendorOrdersDb::getOrderDetail(
                    $orderId,
                    $rol,
                    (int) ($session['userId'] ?? 0),
                    (int) ($session['customerId'] ?? 0),
                    $lang
                );
            } catch (Throwable $e) {
                if (function_exists('app_debug') && app_debug()) {
                    json_response(['error' => $e->getMessage()], 500);
                }
                json_response(['error' => 'No se pudo cargar el pedido'], 500);
            }
            if ($detail === null) {
                json_response(['error' => 'Pedido no encontrado o sin permiso'], 404);
            }
            json_response(['status' => '1', 'order' => $detail]);
        }

        /** Pedidos leyendo MySQL FullVendor. Rol 1: compañía/tipo_d; 2: user_id; 3: customer_id. */
        if ($parts === ['orders', 'from-db'] && $method === 'GET') {
            if (!fullvendor_db_configured()) {
                json_response(['error' => 'Base de datos FullVendor no configurada (FULLVENDOR_DB_HOST, NAME, USER)'], 503);
            }
            $session = Auth::getSession();
            if ($session === null || empty($session['approved'])) {
                json_response(['error' => 'Unauthorized'], 401);
            }
            $rol = (int) ($session['rolId'] ?? 0);
            $lang = (string) ($_GET['lang'] ?? 'en');
            require_once dirname(__DIR__) . '/lib/FullVendorOrdersDb.php';
            try {
                $uid = (int) ($session['userId'] ?? 0);
                $cid = (int) ($session['customerId'] ?? 0);
                if ($rol === 2 && $uid <= 0) {
                    json_response(['error' => 'Sesión sin user_id de vendedor FullVendor'], 400);
                }
                if ($rol === 3 && $cid <= 0) {
                    json_response(['error' => 'Sesión sin customer_id FullVendor'], 400);
                }
                if (!in_array($rol, [1, 2, 3], true)) {
                    json_response(['error' => 'Rol no autorizado para listado de pedidos'], 403);
                }
                $orderList = FullVendorOrdersDb::listForAccount($rol, $uid, $cid, $lang);
            } catch (Throwable $e) {
                if (function_exists('app_debug') && app_debug()) {
                    json_response(['error' => $e->getMessage()], 500);
                }
                json_response(['error' => 'No se pudieron cargar los pedidos desde la base de datos'], 500);
            }
            $ordersSlim = [];
            foreach ($orderList as $o) {
                if (!is_array($o)) {
                    continue;
                }
                $ordersSlim[] = [
                    'order_id' => $o['order_id'] ?? 0,
                    'id' => $o['id'] ?? $o['order_id'] ?? 0,
                    'order_number' => $o['order_number'] ?? '',
                    'order_date' => $o['order_date'] ?? $o['created'] ?? '',
                    'date' => $o['date'] ?? $o['created'] ?? '',
                    'status' => $o['status'] ?? '',
                    'total' => $o['total'] ?? $o['total_amount'] ?? '',
                    'customer_name' => $o['customer_name'] ?? '',
                    'seller_name' => $o['seller_name'] ?? '',
                    'warehouse_name' => $o['warehouse_name'] ?? '',
                    'order_comments' => $o['order_comments'] ?? '',
                    'updated' => $o['updated'] ?? null,
                    'total_value' => $o['total_value'] ?? 0,
                    'assigned_value' => $o['assigned_value'] ?? 0,
                    'status_label' => $o['status_label'] ?? '',
                    'is_mobile_order' => !empty($o['is_mobile_order']),
                ];
            }
            json_response([
                'status' => '1',
                'language_id' => lang_to_id($lang),
                'user_id' => (int) ($session['userId'] ?? 0),
                'customer_id' => (int) ($session['customerId'] ?? 0),
                'orders' => $ordersSlim,
                'order_list' => $orderList,
            ]);
        }

        // Cliente rol 3: mismo patrón que seller-orders → api.php (sin carpeta /api/orders/).
        // POST /api/customer-orders (recomendado) o POST /api/orders (compat).
        if (($parts === ['customer-orders'] && $method === 'POST') || ($parts === ['orders'] && $method === 'POST')) {
            api_require_fullvendor();
            $session = Auth::getSession();
            if ($session === null || empty($session['approved'])) {
                json_response(['error' => 'Unauthorized'], 401);
            }
            $b = read_json_body();
            require_once dirname(__DIR__) . '/lib/PortalCustomerOrderPlace.php';
            PortalCustomerOrderPlace::execute($session, $b);
        }

        if ($parts === ['seller-orders'] && $method === 'POST') {
            api_require_fullvendor();
            $session = Auth::getSession();
            if ($session === null || empty($session['approved'])) {
                json_response(['error' => 'Unauthorized'], 401);
            }
            if ((int) ($session['rolId'] ?? 0) !== 2) {
                json_response(['error' => 'Forbidden'], 403);
            }
            if (!Db::enabled()) {
                json_response(['error' => 'Database not configured'], 500);
            }
            $sellerUid = (int) ($session['userId'] ?? 0);
            $companyId = (int) ($session['companyId'] ?? 0);
            if ($sellerUid <= 0) {
                json_response(['error' => 'Invalid session'], 400);
            }
            $b = read_json_body();
            $customerFvId = (int) ($b['customerFvId'] ?? 0);
            $items = $b['items'] ?? [];
            $orderComment = (string) ($b['orderComment'] ?? '');
            $lang = (string) ($b['lang'] ?? 'en');
            if ($customerFvId <= 0) {
                json_response(['error' => 'customerFvId required'], 400);
            }
            if (!is_array($items) || count($items) === 0) {
                json_response(['error' => 'At least one item required'], 400);
            }
            require_once dirname(__DIR__) . '/lib/SellerCustomers.php';
            $pdo = Db::pdo();
            $assigned = SellerCustomers::assignedCustomerForFvId($pdo, $sellerUid, $companyId, $customerFvId);
            if ($assigned === null) {
                json_response(['error' => 'Customer not assigned to this seller'], 403);
            }
            $mapped = [];
            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $pid = (int) ($it['product_id'] ?? 0);
                $qty = (int) ($it['qty'] ?? 0);
                $price = (float) ($it['sale_price'] ?? 0);
                if ($pid <= 0 || $qty <= 0) {
                    continue;
                }
                if (!is_finite($price) || $price < 0) {
                    $price = 0.0;
                }
                $mapped[] = [
                    'product_id' => $pid,
                    'qty' => $qty,
                    'sale_price' => $price,
                    'line_note' => trim((string) ($it['line_note'] ?? $it['lineNote'] ?? $it['comment'] ?? '')),
                ];
            }
            if (count($mapped) === 0) {
                json_response(['error' => 'No valid line items'], 400);
            }
            $subtotal = 0.0;
            foreach ($mapped as $row) {
                $subtotal += $row['qty'] * $row['sale_price'];
            }
            $discountPct = isset($assigned['discount']) ? (float) $assigned['discount'] : 0.0;
            if (!is_finite($discountPct) || $discountPct < 0) {
                $discountPct = 0.0;
            }
            if ($discountPct > 100) {
                $discountPct = 100.0;
            }
            $discountAmt = round($subtotal * ($discountPct / 100.0), 2);
            if (strlen($orderComment) > 8000) {
                $orderComment = substr($orderComment, 0, 8000);
            }
            $meta = [
                'rolId' => 2,
                'contactName' => (string) ($assigned['name'] ?? ''),
                'bussName' => (string) ($assigned['business_name'] ?? ''),
                'customerDiscountPct' => $discountPct,
                'groupcustomer' => (string) ($assigned['group_name'] ?? ''),
                'tipolista' => '',
                'percprice' => isset($assigned['percentage_on_price']) ? (float) $assigned['percentage_on_price'] : 0.0,
            ];
            try {
                // FullVendor REST: addOrder (mismo flujo que cliente rol 3).
                $res = FullVendor::createOrder(
                    $sellerUid,
                    $customerFvId,
                    lang_to_id($lang),
                    $orderComment,
                    $mapped,
                    $discountAmt,
                    'amount',
                    $meta
                );
            } catch (Throwable $e) {
                json_response(['error' => $e->getMessage()], 500);
            }
            if (($res['status'] ?? '') !== '1') {
                json_response(['error' => $res['message'] ?? 'Failed to create order'], 500);
            }
            json_response(['success' => true, 'orderId' => $res['order_id'] ?? null]);
        }

        if ($parts === ['contact'] && $method === 'POST') {
            $b = read_json_body();
            $name = trim((string) ($b['name'] ?? ''));
            $email = filter_var($b['email'] ?? '', FILTER_VALIDATE_EMAIL);
            $subject = trim((string) ($b['subject'] ?? ''));
            $message = trim((string) ($b['message'] ?? ''));
            if ($name === '' || !$email || $subject === '' || $message === '') {
                json_response(['error' => 'Invalid fields'], 400);
            }
            if (!EmailService::sendContactForm(['name' => $name, 'email' => $email, 'subject' => $subject, 'message' => $message])) {
                json_response(['error' => 'Failed to send message'], 500);
            }
            json_response(['success' => true]);
        }

        if ($parts === ['admin', 'registrations'] && $method === 'GET') {
            require_admin_session();
            if (!Db::enabled()) {
                json_response(['registrations' => []]);
            }
            $st = Db::pdo()->query('SELECT * FROM pending_registrations ORDER BY created_at DESC');
            json_response(['registrations' => $st ? $st->fetchAll() : []]);
        }

        if ($parts === ['admin', 'registrations'] && $method === 'POST') {
            $admin = require_admin_session();
            $b = read_json_body();
            $rid = (int) ($b['registrationId'] ?? 0);
            $action = (string) ($b['action'] ?? '');
            if ($rid <= 0 || !in_array($action, ['approve', 'reject'], true)) {
                json_response(['error' => 'Invalid request'], 400);
            }
            if (!Db::enabled()) {
                json_response(['error' => 'Database not configured'], 500);
            }
            $st = Db::pdo()->prepare('SELECT * FROM pending_registrations WHERE id = ? LIMIT 1');
            $st->execute([$rid]);
            $registration = $st->fetch();
            if (!$registration) {
                json_response(['error' => 'Registration not found'], 404);
            }
            if ($action === 'approve') {
                api_require_fullvendor();
                $fv = FullVendor::createCustomer([
                    'user_id' => $admin['userId'],
                    'language_id' => '1',
                    'business_name' => $registration['company_name'],
                    'name' => $registration['contact_name'],
                    'tax_id' => $registration['tax_id'] ?? '',
                    'email' => $registration['email'],
                    'phone' => $registration['phone'] ?? '',
                    'cell_phone' => $registration['mobile'] ?? '',
                    'term_id' => 1,
                    'group_id' => 1,
                    'commercial_address' => $registration['address'] ?? '',
                ]);
                if (($fv['status'] ?? '') !== '1') {
                    json_response(['error' => 'Failed to create customer in FullVendor.'], 502);
                }
                $info = $fv['info'] ?? [];
                $customerId = is_array($info) ? (int) ($info['customer_id'] ?? 0) : 0;
                $up = Db::pdo()->prepare('UPDATE pending_registrations SET status = ?, fullvendor_customer_id = ? WHERE id = ?');
                $up->execute(['approved', $customerId ?: null, $rid]);
                EmailService::sendApprovalEmail($registration['email'], $registration['contact_name']);
            } else {
                $up = Db::pdo()->prepare('UPDATE pending_registrations SET status = ? WHERE id = ?');
                $up->execute(['rejected', $rid]);
                EmailService::sendRejectionEmail($registration['email'], $registration['contact_name']);
            }
            json_response(['success' => true]);
        }

        json_response(['error' => 'Not found'], 404);
    } catch (Throwable $e) {
        error_log('[api] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        if (class_exists('AppLog', false)) {
            AppLog::appException($e, 'api_dispatch');
        }
        if (defined('HV_API_REQUEST') && HV_API_REQUEST && class_exists('ApiLogger', false)) {
            ApiLogger::logException($e);
        }
        if (function_exists('app_debug') && app_debug()) {
            json_response(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 500);
        }
        json_response(['error' => 'Server error'], 500);
    }
}
