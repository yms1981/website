<?php

declare(strict_types=1);

/**
 * Sincroniza clientes desde FullVendor (softcustomerList) hacia `customers` y crea/enlaza `users`.
 *
 * Las filas en `users` creadas o actualizadas desde el email del cliente (esta sync) son siempre
 * clientes: {@see self::ROL_ID_CUSTOMER} (3). Los vendedores (rol 2) vienen de {@see UsersListSync},
 * no de softcustomerList.
 */
final class CustomerSync
{
    /** Email de cliente FullVendor → fila en `users`: cliente. */
    private const ROL_ID_CUSTOMER = 3;

    private const NOTES_MAX = 50;

    /** Un solo bcrypt para el password por defecto 123456 (evita miles de password_hash en listas grandes). */
    private static ?string $defaultPassword123456Hash = null;

    private static function hashForDefaultPassword123456(): string
    {
        if (self::$defaultPassword123456Hash === null) {
            self::$defaultPassword123456Hash = password_hash('123456', PASSWORD_DEFAULT);
        }

        return self::$defaultPassword123456Hash;
    }

    /** Sync de muchas filas: sin límite de tiempo de PHP (Apache/php.ini suele ser 120s). */
    private static function relaxExecutionLimits(): void
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
    }

    /**
     * API + customers.json; con BD aplica el mismo volcado que POST customers/sync (tablas customers + users).
     */
    public static function refreshCustomersDataDump(): void
    {
        if (!function_exists('fullvendor_api_configured') || !fullvendor_api_configured()) {
            return;
        }
        try {
            $res = FullVendor::softCustomerList();
            $list = self::extractCustomerList($res);
            CatalogCache::saveDataDump('customers', $list);
            if (Db::enabled() && $list !== []) {
                $acc = self::applyCustomerListToDatabase(Db::pdo(), $list);
                self::trace(
                    'refreshCustomersDataDump DB: upserted=' . $acc['upserted']
                    . ' users_creados=' . $acc['users_created']
                );
            }
        } catch (Throwable $e) {
            error_log('[CustomerSync] refreshCustomersDataDump: ' . $e->getMessage());
        }
    }

    /** Siempre: PHP error_log + archivo log/sync (si AppLog está cargado). */
    private static function trace(string $message, string $level = 'INFO'): void
    {
        error_log('[CustomerSync] ' . $message);
        if (class_exists('AppLog', false)) {
            AppLog::sync($message, $level);
        }
    }

    /**
     * @return array{
     *   processed:int,
     *   skipped:int,
     *   upserted:int,
     *   users_created:int,
     *   customers_without_email:int,
     *   errors:list<string>,
     *   received?:array<string, mixed>,
     *   sync_debug?:array<string, mixed>
     * }
     */
    public static function syncFromFullVendor(): array
    {
        $out = [
            'processed' => 0,
            'skipped' => 0,
            'upserted' => 0,
            'users_created' => 0,
            'customers_without_email' => 0,
            'errors' => [],
        ];

        $verbose = function_exists('customer_sync_log_verbose') && customer_sync_log_verbose();
        $cidEnv = trim((string) config('FULLVENDOR_COMPANY_ID', ''));
        $bodyLog = json_encode(['company_id' => ctype_digit($cidEnv) ? (int) $cidEnv : $cidEnv], JSON_UNESCAPED_UNICODE);

        self::trace(str_repeat('=', 64));
        self::trace('Inicio syncFromFullVendor ' . date('c'));
        self::trace('softcustomerList POST body: ' . $bodyLog);

        try {
            $res = FullVendor::softCustomerList();
        } catch (Throwable $e) {
            self::trace('FALLO llamada softCustomerList: ' . $e->getMessage(), 'ERROR');
            if (class_exists('AppLog', false)) {
                AppLog::syncException($e, 'softCustomerList');
            }
            $out['errors'][] = 'FullVendor softCustomerList: ' . $e->getMessage();
            $logAbs = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'sync-' . date('Y-m-d') . '.log';
            $out['received'] = [
                'list_count' => 0,
                'response_keys' => [],
                'log_file' => 'log/sync-' . date('Y-m-d') . '.log',
                'log_absolute' => $logAbs,
                'note' => 'También mira el error_log de PHP/Apache: ahí se duplica cada línea [CustomerSync].',
            ];

            return $out;
        }

        $topKeys = array_keys($res);
        $list = self::extractCustomerList($res);
        $listCount = count($list);

        CatalogCache::saveDataDump('customers', $list);

        self::trace('Respuesta parseada: claves JSON=' . implode(',', $topKeys) . ' | filas extraídas=' . $listCount);

        $fullJson = json_encode($res, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_string($fullJson) && $fullJson !== '') {
            $len = strlen($fullJson);
            $maxLog = 12000;
            self::trace(
                'JSON recibido (' . $len . ' bytes). Vista previa log: ' . mb_substr($fullJson, 0, $maxLog)
                . ($len > $maxLog ? '…[truncado]' : '')
            );
        }

        if ($listCount > 0 && isset($list[0]) && is_array($list[0])) {
            self::trace('Claves primera fila: ' . implode(',', array_keys($list[0])));
            self::trace('Muestra fila 0: ' . json_encode(array_slice($list[0], 0, 12, true), JSON_UNESCAPED_UNICODE));
        }

        if ($verbose) {
            if (class_exists('AppLog', false)) {
                AppLog::sync('JSON respuesta (verbose completo truncado 12k): ' . mb_substr((string) $fullJson, 0, 12000), 'DEBUG');
            }
        }

        $sampleRow = null;
        if ($listCount > 0 && isset($list[0]) && is_array($list[0])) {
            $r0 = $list[0];
            $sampleRow = [
                'customer_id' => $r0['customer_id'] ?? $r0['Customer_Id'] ?? null,
                'company_id' => $r0['company_id'] ?? null,
                'email' => $r0['email'] ?? $r0['Email'] ?? null,
                'business_name' => $r0['business_name'] ?? null,
            ];
        }

        $logAbs = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'sync-' . date('Y-m-d') . '.log';
        $out['received'] = [
            'list_count' => $listCount,
            'response_keys' => $topKeys,
            'first_row_sample' => $sampleRow,
            'log_file' => 'log/sync-' . date('Y-m-d') . '.log',
            'log_absolute' => $logAbs,
        ];

        if ($listCount === 0) {
            $out['errors'][] = 'Respuesta sin lista de clientes reconocible (revisa claves list/data/info).';
            self::trace('Sin filas en lista tras extractCustomerList.', 'WARNING');
            if ($verbose) {
                $out['sync_debug'] = [
                    'post_body' => $bodyLog,
                    'response_top_keys' => $topKeys,
                    'list_count' => 0,
                ];
            }
            self::trace('Fin syncFromFullVendor (sin datos) ' . date('c'));
            self::trace(str_repeat('=', 64));

            return $out;
        }

        if (!Db::enabled()) {
            $out['errors'][] = 'Database not configured';
            self::trace('Fin syncFromFullVendor: volcado en caché OK, BD desactivada (DB_NAME vacío).', 'WARNING');
            self::trace(str_repeat('=', 64));

            return $out;
        }

        $pdo = Db::pdo();
        $acc = self::applyCustomerListToDatabase($pdo, $list);
        $out['processed'] = $acc['processed'];
        $out['skipped'] = $acc['skipped'];
        $out['upserted'] = $acc['upserted'];
        $out['users_created'] = $acc['users_created'];
        $out['errors'] = array_merge($out['errors'], $acc['errors']);
        $customersNoEmailForUser = $acc['customers_without_email'];
        $skipFvId = $acc['skip_fv_id'];
        $skipCompany = $acc['skip_company'];
        $skipNotArray = $acc['skip_not_array'];

        $summary = sprintf(
            'Fin sync: upserted=%d skipped=%d users_created=%d | clientes sin email (sin fila users)=%d | omitidos: sin_customer_id=%d sin_company=%d fila_no_array=%d errors=%d',
            $out['upserted'],
            $out['skipped'],
            $out['users_created'],
            $customersNoEmailForUser,
            $skipFvId,
            $skipCompany,
            $skipNotArray,
            count($out['errors'])
        );
        self::trace($summary);
        if ($out['errors'] !== []) {
            self::trace('Errores: ' . json_encode($out['errors'], JSON_UNESCAPED_UNICODE), 'WARNING');
        }
        self::trace('Fin syncFromFullVendor ' . date('c'));
        self::trace(str_repeat('=', 64));

        $out['customers_without_email'] = $customersNoEmailForUser;

        if ($verbose) {
            $out['sync_debug'] = [
                'post_body' => $bodyLog,
                'response_top_keys' => $topKeys,
                'list_count' => $listCount,
                'skip_breakdown' => [
                    'not_array_row' => $skipNotArray,
                    'customers_synced_without_user_row' => $customersNoEmailForUser,
                    'missing_customer_id' => $skipFvId,
                    'missing_company_id' => $skipCompany,
                ],
            ];
        }

        return $out;
    }

    /**
     * Volcado de lista ya parseada a `customers` y `users` (rol cliente, {@see self::ROL_ID_CUSTOMER}; customerId = ID FullVendor del cliente).
     *
     * @param list<array<string, mixed>> $list
     * @return array{
     *   processed:int,
     *   skipped:int,
     *   upserted:int,
     *   users_created:int,
     *   customers_without_email:int,
     *   skip_not_array:int,
     *   skip_fv_id:int,
     *   skip_company:int,
     *   errors:list<string>
     * }
     */
    private static function applyCustomerListToDatabase(\PDO $pdo, array $list): array
    {
        self::relaxExecutionLimits();
        self::$defaultPassword123456Hash = null;

        $acc = [
            'processed' => 0,
            'skipped' => 0,
            'upserted' => 0,
            'users_created' => 0,
            'customers_without_email' => 0,
            'skip_not_array' => 0,
            'skip_fv_id' => 0,
            'skip_company' => 0,
            'errors' => [],
        ];

        foreach ($list as $idx => $row) {
            if (!is_array($row)) {
                $acc['skipped']++;
                $acc['skip_not_array']++;

                continue;
            }
            $acc['processed']++;

            try {
                $emailRaw = trim((string) ($row['email'] ?? $row['Email'] ?? ''));
                $emailForUser = strtolower($emailRaw);
                $hasValidEmail = $emailForUser !== '' && filter_var($emailForUser, FILTER_VALIDATE_EMAIL);

                $fvCustomerId = self::rowPositiveInt($row, [
                    'customer_id', 'Customer_Id', 'customerId', 'customerID', 'CustomerID', 'id', 'ID',
                ]);
                if ($fvCustomerId <= 0) {
                    $acc['skipped']++;
                    $acc['skip_fv_id']++;

                    continue;
                }

                $companyId = self::rowPositiveInt($row, ['company_id', 'Company_Id']);
                if ($companyId <= 0) {
                    $companyId = (int) config('FULLVENDOR_COMPANY_ID', '0');
                }
                if ($companyId <= 0) {
                    $acc['skipped']++;
                    $acc['skip_company']++;

                    continue;
                }

                $emailForDb = $hasValidEmail ? $emailForUser : '';
                self::upsertCustomer($pdo, $row, $fvCustomerId, $companyId, $emailForDb);
                if ($hasValidEmail) {
                    self::ensureUserForCustomer($pdo, $emailForUser, $fvCustomerId, $row, $acc);
                } else {
                    $acc['customers_without_email']++;
                }
                $acc['upserted']++;
            } catch (Throwable $e) {
                $acc['errors'][] = 'Fila ' . (string) $idx . ': ' . $e->getMessage();
                self::trace('Error fila ' . (string) $idx . ': ' . $e->getMessage(), 'ERROR');
                if (class_exists('AppLog', false)) {
                    AppLog::syncException($e, 'Fila ' . (string) $idx);
                }
            }
        }

        return $acc;
    }

    /** @param array<string, mixed> $res */
    private static function extractCustomerList(array $res): array
    {
        $topKeys = [
            'list', 'customers', 'customerList', 'customer_list', 'softcustomerList',
            'softcustomer_list', 'rows', 'records', 'result', 'items',
        ];
        foreach ($topKeys as $k) {
            if (isset($res[$k]) && is_array($res[$k])) {
                $rows = self::coerceCustomerRows($res[$k]);
                if ($rows !== null) {
                    return $rows;
                }
            }
        }

        $data = $res['data'] ?? null;
        if (is_array($data)) {
            foreach (['list', 'customers', 'customerList', 'rows', 'records'] as $dk) {
                if (isset($data[$dk]) && is_array($data[$dk])) {
                    $rows = self::coerceCustomerRows($data[$dk]);
                    if ($rows !== null) {
                        return $rows;
                    }
                }
            }
            $rows = self::coerceCustomerRows($data);
            if ($rows !== null) {
                return $rows;
            }
            if (self::looksLikeCustomerRow($data)) {
                return [$data];
            }
        }

        // FullVendor suele devolver { status, info: [ {...}, {...} ] } — info es el array de clientes
        $info = $res['info'] ?? null;
        if (is_array($info)) {
            foreach (['list', 'customers', 'customerList', 'rows', 'records'] as $ik) {
                if (isset($info[$ik]) && is_array($info[$ik])) {
                    $rows = self::coerceCustomerRows($info[$ik]);
                    if ($rows !== null) {
                        return $rows;
                    }
                }
            }
            $rows = self::coerceCustomerRows($info);
            if ($rows !== null) {
                return $rows;
            }
            if (self::looksLikeCustomerRow($info)) {
                return [$info];
            }
        }

        return [];
    }

    /**
     * @param array<mixed> $arr
     * @return list<array<string, mixed>>|null null = no parece lista de filas
     */
    private static function coerceCustomerRows(array $arr): ?array
    {
        if ($arr === []) {
            return [];
        }
        $rows = array_values(array_filter($arr, static fn ($x): bool => is_array($x)));

        return $rows !== [] ? $rows : null;
    }

    /** @param array<string, mixed> $r */
    private static function looksLikeCustomerRow(array $r): bool
    {
        $hasId = self::rowPositiveInt($r, [
            'customer_id', 'Customer_Id', 'customerId', 'customerID', 'id', 'ID',
        ]) > 0;

        return $hasId && (
            isset($r['email']) || isset($r['Email'])
            || isset($r['business_name']) || isset($r['Business_Name'])
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $keys
     */
    private static function rowPositiveInt(array $row, array $keys): int
    {
        foreach ($keys as $k) {
            if (!isset($row[$k])) {
                continue;
            }
            $v = $row[$k];
            if (is_string($v)) {
                $v = trim($v);
            }
            $n = (int) $v;
            if ($n > 0) {
                return $n;
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function upsertCustomer(\PDO $pdo, array $row, int $fvCustomerId, int $companyId, string $email): int
    {
        $balance = self::parseDecimal($row['balance'] ?? 0);
        $discount = self::parseFloatish($row['discount'] ?? 0);
        $notes = self::truncateNote($row['notes'] ?? $row['dispatch_shipping_notes'] ?? '');
        $dispatchNotes = self::truncateNote($row['dispatch_shipping_notes'] ?? '');

        $emailCol = trim($email) === '' ? ' ' : $email;

        $data = [
            'customeridfullvendor' => $fvCustomerId,
            'language_id' => (int) ($row['language_id'] ?? 1),
            'company_id' => $companyId,
            'user_id' => trim((string) ($row['user_id'] ?? ' ')) ?: ' ',
            'name' => trim((string) ($row['name'] ?? ' ')) ?: ' ',
            'business_name' => trim((string) ($row['business_name'] ?? ' ')) ?: ' ',
            'tax_id' => trim((string) ($row['tax_id'] ?? ' ')) ?: ' ',
            'balance' => $balance,
            'discount' => $discount,
            'term_id' => self::nullableInt($row['term_id'] ?? null),
            'term_name' => trim((string) ($row['term_name'] ?? ' ')) ?: ' ',
            'group_id' => self::nullableInt($row['group_id'] ?? null),
            'group_name' => trim((string) ($row['group_name'] ?? ' ')) ?: ' ',
            'percentage_on_price' => self::parseFloatish($row['percentage_on_price'] ?? 0),
            'percent_price_amount' => self::parseFloatish($row['percent_price_amount'] ?? 0),
            'email' => $emailCol,
            'phone' => trim((string) ($row['phone'] ?? ' ')) ?: ' ',
            'cell_phone' => trim((string) ($row['cell_phone'] ?? ' ')) ?: ' ',
            'notes' => $notes,
            'commercial_address' => trim((string) ($row['commercial_address'] ?? ' ')) ?: ' ',
            'commercial_delivery_address' => trim((string) ($row['commercial_delivery_address'] ?? ' ')) ?: ' ',
            'commercial_country' => trim((string) ($row['commercial_country'] ?? ' ')) ?: ' ',
            'commercial_state' => trim((string) ($row['commercial_state'] ?? ' ')) ?: ' ',
            'commercial_city' => trim((string) ($row['commercial_city'] ?? ' ')) ?: ' ',
            'commercial_zone' => trim((string) ($row['commercial_zone'] ?? ' ')) ?: ' ',
            'commercial_zip_code' => trim((string) ($row['commercial_zip_code'] ?? ' ')) ?: ' ',
            'dispatch_address' => trim((string) ($row['dispatch_address'] ?? ' ')) ?: ' ',
            'dispatch_delivery_address' => trim((string) ($row['dispatch_delivery_address'] ?? ' ')) ?: ' ',
            'dispatch_country' => trim((string) ($row['dispatch_country'] ?? ' ')) ?: ' ',
            'dispatch_state' => trim((string) ($row['dispatch_state'] ?? ' ')) ?: ' ',
            'dispatch_city' => trim((string) ($row['dispatch_city'] ?? ' ')) ?: ' ',
            'dispatch_zone' => trim((string) ($row['dispatch_zone'] ?? ' ')) ?: ' ',
            'dispatch_zip_code' => trim((string) ($row['dispatch_zip_code'] ?? ' ')) ?: ' ',
            'dispatch_shipping_notes' => $dispatchNotes,
            'catalog_emails' => (int) ($row['catalog_emails'] ?? 0),
            'customer_created_at' => self::nullableDateTime($row['customer_created_at'] ?? null)
                ?? date('Y-m-d H:i:s'),
            'customer_status' => (int) ($row['customer_status'] ?? 1),
            'cust_id_kor' => trim((string) ($row['cust_id_kor'] ?? '')),
            'id_kor' => (int) ($row['id_kor'] ?? 0),
        ];

        $sel = $pdo->prepare('SELECT customer_id FROM customers WHERE customeridfullvendor = ? LIMIT 1');
        $sel->execute([$fvCustomerId]);
        $rowFound = $sel->fetch(PDO::FETCH_NUM);
        $localPk = ($rowFound !== false && isset($rowFound[0])) ? (int) $rowFound[0] : 0;

        if ($localPk > 0) {
            self::updateCustomer($pdo, $data, $fvCustomerId);

            return $localPk;
        }

        return self::insertCustomer($pdo, $data);
    }

    /** @param array<string, mixed> $d */
    private static function updateCustomer(\PDO $pdo, array $d, int $fvCustomerId): void
    {
        $sql = 'UPDATE customers SET
            language_id = ?, company_id = ?, user_id = ?, name = ?, business_name = ?, tax_id = ?,
            balance = ?, discount = ?, term_id = ?, term_name = ?, group_id = ?, group_name = ?,
            percentage_on_price = ?, percent_price_amount = ?,
            email = ?, phone = ?, cell_phone = ?, notes = ?,
            commercial_address = ?, commercial_delivery_address = ?, commercial_country = ?, commercial_state = ?, commercial_city = ?, commercial_zone = ?, commercial_zip_code = ?,
            dispatch_address = ?, dispatch_delivery_address = ?, dispatch_country = ?, dispatch_state = ?, dispatch_city = ?, dispatch_zone = ?, dispatch_zip_code = ?, dispatch_shipping_notes = ?,
            catalog_emails = ?, customer_created_at = ?, customer_status = ?, cust_id_kor = ?, id_kor = ?
            WHERE customeridfullvendor = ?';

        $st = $pdo->prepare($sql);
        $st->execute([
            $d['language_id'],
            $d['company_id'],
            $d['user_id'],
            $d['name'],
            $d['business_name'],
            $d['tax_id'],
            $d['balance'],
            $d['discount'],
            $d['term_id'],
            $d['term_name'],
            $d['group_id'],
            $d['group_name'],
            $d['percentage_on_price'],
            $d['percent_price_amount'],
            $d['email'],
            $d['phone'],
            $d['cell_phone'],
            $d['notes'],
            $d['commercial_address'],
            $d['commercial_delivery_address'],
            $d['commercial_country'],
            $d['commercial_state'],
            $d['commercial_city'],
            $d['commercial_zone'],
            $d['commercial_zip_code'],
            $d['dispatch_address'],
            $d['dispatch_delivery_address'],
            $d['dispatch_country'],
            $d['dispatch_state'],
            $d['dispatch_city'],
            $d['dispatch_zone'],
            $d['dispatch_zip_code'],
            $d['dispatch_shipping_notes'],
            $d['catalog_emails'],
            $d['customer_created_at'],
            $d['customer_status'],
            $d['cust_id_kor'],
            $d['id_kor'],
            $fvCustomerId,
        ]);
    }

    /** @param array<string, mixed> $d */
    private static function insertCustomer(\PDO $pdo, array $d): int
    {
        $sql = 'INSERT INTO customers (
            customeridfullvendor, language_id, company_id, user_id, name, business_name, tax_id,
            balance, discount, term_id, term_name, group_id, group_name, percentage_on_price, percent_price_amount,
            email, phone, cell_phone, notes,
            commercial_address, commercial_delivery_address, commercial_country, commercial_state, commercial_city, commercial_zone, commercial_zip_code,
            dispatch_address, dispatch_delivery_address, dispatch_country, dispatch_state, dispatch_city, dispatch_zone, dispatch_zip_code, dispatch_shipping_notes,
            catalog_emails, customer_created_at, customer_status, cust_id_kor, id_kor, assign_catalog, sales, password
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';

        $st = $pdo->prepare($sql);
        $st->execute([
            $d['customeridfullvendor'],
            $d['language_id'],
            $d['company_id'],
            $d['user_id'],
            $d['name'],
            $d['business_name'],
            $d['tax_id'],
            $d['balance'],
            $d['discount'],
            $d['term_id'],
            $d['term_name'],
            $d['group_id'],
            $d['group_name'],
            $d['percentage_on_price'],
            $d['percent_price_amount'],
            $d['email'],
            $d['phone'],
            $d['cell_phone'],
            $d['notes'],
            $d['commercial_address'],
            $d['commercial_delivery_address'],
            $d['commercial_country'],
            $d['commercial_state'],
            $d['commercial_city'],
            $d['commercial_zone'],
            $d['commercial_zip_code'],
            $d['dispatch_address'],
            $d['dispatch_delivery_address'],
            $d['dispatch_country'],
            $d['dispatch_state'],
            $d['dispatch_city'],
            $d['dispatch_zone'],
            $d['dispatch_zip_code'],
            $d['dispatch_shipping_notes'],
            $d['catalog_emails'],
            $d['customer_created_at'],
            $d['customer_status'],
            $d['cust_id_kor'],
            $d['id_kor'],
            0,
            0.00,
            null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * `users.customerId` = ID de cliente en FullVendor (customer_id de la API), no la PK local de `customers`.
     * Solo filas con email válido. Contraseña: la de la API (bcrypt tal cual, texto plano re-hasheado) o por defecto 123456.
     *
     * @param array{users_created?:int} $stats
     */
    private static function ensureUserForCustomer(
        \PDO $pdo,
        string $email,
        int $fvCustomerId,
        array $apiRow,
        array &$stats
    ): void {
        $emailNorm = strtolower(trim($email));
        $passRaw = trim((string) ($apiRow['password'] ?? $apiRow['Password'] ?? ''));

        if ($passRaw !== '') {
            if (preg_match('/^\$2[ayb]\$\d{2}\$/', $passRaw) === 1) {
                $passForDb = strlen($passRaw) > 300 ? mb_substr($passRaw, 0, 300) : $passRaw;
            } else {
                $passForDb = password_hash($passRaw, PASSWORD_DEFAULT);
            }
        } else {
            $passForDb = self::hashForDefaultPassword123456();
        }

        $st = $pdo->prepare('SELECT `userId`, `password` FROM `users` WHERE LOWER(TRIM(`username`)) = ? LIMIT 1');
        $st->execute([$emailNorm]);
        $existing = $st->fetch(PDO::FETCH_ASSOC);

        if (is_array($existing) && isset($existing['userId'])) {
            $uid = (int) $existing['userId'];
            if ($passRaw !== '') {
                $upd = $pdo->prepare(
                    'UPDATE `users` SET `customerId` = ?, `rolId` = ?, `password` = ? WHERE `userId` = ?'
                );
                $upd->execute([$fvCustomerId, self::ROL_ID_CUSTOMER, $passForDb, $uid]);
            } else {
                $upd = $pdo->prepare(
                    'UPDATE `users` SET `customerId` = ?, `rolId` = ? WHERE `userId` = ?'
                );
                $upd->execute([$fvCustomerId, self::ROL_ID_CUSTOMER, $uid]);
            }

            return;
        }

        $ins = $pdo->prepare(
            'INSERT INTO `users` (`username`, `password`, `rolId`, `customerId`) VALUES (?,?,?,?)'
        );
        $ins->execute([$emailNorm, $passForDb, self::ROL_ID_CUSTOMER, $fvCustomerId]);
        $stats['users_created'] = ($stats['users_created'] ?? 0) + 1;
    }

    private static function parseDecimal(mixed $v): float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string) $v));

        return $s === '' ? 0.0 : (float) $s;
    }

    /** Número desde API (evita "Nothing", vacío, texto). */
    private static function parseFloatish(mixed $v): float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = trim((string) $v);
        if ($s === '' || preg_match('/^(nothing|n\/a|null)$/i', $s) === 1) {
            return 0.0;
        }
        $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $s));

        return $clean === '' ? 0.0 : (float) $clean;
    }

    private static function nullableInt(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }

        return (int) $v;
    }

    private static function nullableDateTime(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }

        return $s;
    }

    private static function truncateNote(mixed $v): string
    {
        $s = trim((string) $v);

        return $s === '' ? ' ' : mb_substr($s, 0, self::NOTES_MAX);
    }
}
