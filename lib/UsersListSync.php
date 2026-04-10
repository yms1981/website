<?php

declare(strict_types=1);

/**
 * Sincroniza la lista FullVendor hacia `usersList`, `users` y data/cache/usersList.json.
 *
 * Los registros locales en `users` creados o actualizados desde esta lista (usersList API)
 * son siempre vendedores: {@see self::ROL_ID_SELLER} (2). Los clientes (rol 3) vienen de
 * {@see CustomerSync}, no de usersList.
 */
final class UsersListSync
{
    /** FullVendor usersList → fila en `users`: vendedor. */
    private const ROL_ID_SELLER = 2;
    /**
     * API + usersList.json; si hay BD, aplica la misma lógica que el sync admin (usersList + users).
     */
    public static function refreshUsersListDataDump(): void
    {
        if (!function_exists('fullvendor_api_configured') || !fullvendor_api_configured()) {
            return;
        }
        try {
            $res = FullVendor::usersList();
            $list = self::extractUserRows($res);
            CatalogCache::saveDataDump('usersList', $list);
            if (Db::enabled() && $list !== []) {
                $stats = self::persistUserListToDatabase($list);
                self::trace(
                    'refreshUsersListDataDump DB: usersList filas=' . $stats['userslist_upserted']
                    . ' users creados=' . $stats['users_created'] . ' users actualizados=' . $stats['users_updated']
                );
            }
        } catch (Throwable $e) {
            error_log('[UsersListSync] refreshUsersListDataDump: ' . $e->getMessage());
        }
    }

    private static function trace(string $message, string $level = 'INFO'): void
    {
        error_log('[UsersListSync] ' . $message);
        if (class_exists('AppLog', false)) {
            AppLog::sync('[usersList] ' . $message, $level);
        }
    }

    /**
     * @return array{
     *   userslist_upserted:int,
     *   users_created:int,
     *   users_updated:int,
     *   skipped_no_email:int,
     *   skipped_no_user_id:int,
     *   errors:list<string>,
     *   users_errors:list<string>
     * }
     */
    public static function syncFromFullVendor(): array
    {
        $out = [
            'userslist_upserted' => 0,
            'users_created' => 0,
            'users_updated' => 0,
            'skipped_no_email' => 0,
            'skipped_no_user_id' => 0,
            'errors' => [],
            'users_errors' => [],
        ];

        if (!Db::enabled()) {
            $out['errors'][] = 'Database not configured';

            return $out;
        }

        try {
            $res = FullVendor::usersList();
        } catch (Throwable $e) {
            self::trace('Fallo usersList: ' . $e->getMessage(), 'ERROR');
            if (class_exists('AppLog', false)) {
                AppLog::syncException($e, 'FullVendor usersList');
            }
            $out['errors'][] = 'FullVendor usersList: ' . $e->getMessage();

            return $out;
        }

        $list = self::extractUserRows($res);
        CatalogCache::saveDataDump('usersList', $list);

        if ($list === []) {
            $out['errors'][] = 'Sin filas de usuarios en la respuesta. Claves: ' . implode(',', array_keys($res))
                . '. Revisa data/cache/fv-*usersList*.json';

            return $out;
        }

        $persisted = self::persistUserListToDatabase($list);
        $out['userslist_upserted'] = $persisted['userslist_upserted'];
        $out['users_created'] = $persisted['users_created'];
        $out['users_updated'] = $persisted['users_updated'];
        $out['skipped_no_email'] = $persisted['skipped_no_email'];
        $out['skipped_no_user_id'] = $persisted['skipped_no_user_id'];
        $out['users_errors'] = $persisted['users_errors'];
        if ($persisted['errors'] !== []) {
            $out['errors'] = array_merge($out['errors'], $persisted['errors']);
        }

        self::trace(
            'OK total: usersList=' . $out['userslist_upserted']
            . ' users nuevos=' . $out['users_created'] . ' users actualizados=' . $out['users_updated']
        );

        return $out;
    }

    /**
     * Escribe `usersList` y `users` a partir de la lista ya parseada (misma lógica para sync admin y refresh en home).
     *
     * @param list<array<string, mixed>> $list
     * @return array{
     *   userslist_upserted:int,
     *   users_created:int,
     *   users_updated:int,
     *   skipped_no_email:int,
     *   skipped_no_user_id:int,
     *   errors:list<string>,
     *   users_errors:list<string>
     * }
     */
    private static function persistUserListToDatabase(array $list): array
    {
        $out = [
            'userslist_upserted' => 0,
            'users_created' => 0,
            'users_updated' => 0,
            'skipped_no_email' => 0,
            'skipped_no_user_id' => 0,
            'errors' => [],
            'users_errors' => [],
        ];

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            foreach ($list as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $fvUserId = self::coerceFvUserId($row);
                if ($fvUserId <= 0) {
                    $out['skipped_no_user_id']++;

                    continue;
                }

                $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                $existsSt = $pdo->prepare('SELECT 1 FROM `usersList` WHERE `user_id` = ? LIMIT 1');
                $existsSt->execute([$fvUserId]);
                if ($existsSt->fetch() === false) {
                    self::insertUsersListRow($pdo, $row, $json);
                } else {
                    self::upsertUsersListRow($pdo, $row, $json);
                }
                $out['userslist_upserted']++;
            }
            $pdo->commit();
            self::trace('OK tabla usersList: filas procesadas=' . $out['userslist_upserted']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::trace('Error al escribir usersList: ' . $e->getMessage(), 'ERROR');
            if (class_exists('AppLog', false)) {
                AppLog::syncException($e, 'UsersListSync usersList');
            }
            $out['errors'][] = 'usersList DB: ' . $e->getMessage();

            return $out;
        }

        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            self::syncLocalUserFromFvRow($pdo, $row, $out);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param array{users_created:int, users_updated:int, skipped_no_email:int, users_errors:list<string>} $out
     */
    private static function syncLocalUserFromFvRow(\PDO $pdo, array $row, array &$out): void
    {
        $fvUserId = self::coerceFvUserId($row);
        if ($fvUserId <= 0) {
            return;
        }

        $emailRaw = trim((string) ($row['email'] ?? ''));
        $emailNorm = strtolower($emailRaw);
        if ($emailNorm === '' || !filter_var($emailNorm, FILTER_VALIDATE_EMAIL)) {
            $out['skipped_no_email']++;

            return;
        }

        $passFromApi = (string) ($row['password'] ?? '');

        try {
            $byEmail = $pdo->prepare(
                'SELECT `userId`, `password` FROM `users` WHERE LOWER(TRIM(`username`)) = ? LIMIT 1'
            );
            $byEmail->execute([$emailNorm]);
            $existing = $byEmail->fetch(PDO::FETCH_ASSOC);

            if ($passFromApi !== '') {
                $passForDb = strlen($passFromApi) > 300 ? mb_substr($passFromApi, 0, 300) : $passFromApi;
                $exact = $pdo->prepare(
                    'SELECT `userId` FROM `users` WHERE LOWER(TRIM(`username`)) = ? AND `password` = ? LIMIT 1'
                );
                $exact->execute([$emailNorm, $passForDb]);
                if ($exact->fetch() !== false) {
                    return;
                }

                if (is_array($existing) && isset($existing['userId'])) {
                    $upd = $pdo->prepare(
                        'UPDATE `users` SET `password` = ?, `rolId` = ?, `customerId` = ? WHERE `userId` = ?'
                    );
                    $upd->execute([$passForDb, 2, $fvUserId, (int) $existing['userId']]);
                    $out['users_updated']++;

                    return;
                }

                $ins = $pdo->prepare(
                    'INSERT INTO `users` (`username`, `password`, `rolId`, `customerId`) VALUES (?,?,?,?)'
                );
                $ins->execute([$emailNorm, $passForDb, self::ROL_ID_SELLER, $fvUserId]);
                $out['users_created']++;

                return;
            }

            if (is_array($existing) && isset($existing['userId'])) {
                $upd = $pdo->prepare(
                    'UPDATE `users` SET `rolId` = ?, `customerId` = ? WHERE `userId` = ?'
                );
                $upd->execute([self::ROL_ID_SELLER, $fvUserId, (int) $existing['userId']]);
                $out['users_updated']++;

                return;
            }

            $passNew = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
            $ins = $pdo->prepare(
                'INSERT INTO `users` (`username`, `password`, `rolId`, `customerId`) VALUES (?,?,?,?)'
            );
            $ins->execute([$emailNorm, $passNew, self::ROL_ID_SELLER, $fvUserId]);
            $out['users_created']++;
        } catch (Throwable $e) {
            $msg = 'email ' . $emailNorm . ': ' . $e->getMessage();
            $out['users_errors'][] = $msg;
            self::trace('users local: ' . $msg, 'WARNING');
        }
    }

    /**
     * Primera inserción por user_id (sin ON DUPLICATE).
     *
     * @param array<string, mixed> $row
     */
    private static function insertUsersListRow(\PDO $pdo, array $row, string $json): void
    {
        $uid = (int) ($row['user_id'] ?? 0);
        $uniqueId = self::strOrNull($row['unique_id'] ?? null);
        $companyId = self::intOrNull($row['company_id'] ?? null);
        $firstName = (string) ($row['first_name'] ?? '');
        $lastName = (string) ($row['last_name'] ?? '');
        $username = (string) ($row['username'] ?? '');
        $email = (string) ($row['email'] ?? '');
        $password = (string) ($row['password'] ?? '');
        if (strlen($password) > 255) {
            $password = mb_substr($password, 0, 255);
        }
        $profile = self::intOrNull($row['profile'] ?? null);
        $phoneNumber = (string) ($row['phone_number'] ?? '');
        $cellNumber = (string) ($row['cell_number'] ?? '');
        $fax = (string) ($row['fax'] ?? '');
        $profileImage = (string) ($row['profile_image'] ?? '');
        $emailVerification = self::tinyInt($row['email_verification'] ?? 0);
        $otp = (string) ($row['otp'] ?? '');
        $created = self::datetimeOrNull($row['created'] ?? null);
        $updatedAt = self::datetimeOrNull($row['updated_at'] ?? null);
        $status = self::smallInt($row['status'] ?? 1);
        $idKor = self::bigint($row['id_kor'] ?? 0);
        $defaultCol = self::intOrNull($row['default'] ?? null);
        $token = (string) ($row['token'] ?? '');
        $addCustomer = self::reqInt($row['add_customer'] ?? 0);
        $updateCustomer = self::reqInt($row['update_customer'] ?? 0);
        $sendCatalog = self::reqInt($row['send_catalog'] ?? 0);
        $allCustomers = self::reqInt($row['all_customers'] ?? 0);
        $proforma = self::reqInt($row['proforma'] ?? 0);
        $emailOnSalesmanOrder = self::reqInt($row['email_on_salesman_order'] ?? 1);

        $sql = 'INSERT INTO `usersList` (
            `user_id`, `unique_id`, `company_id`, `first_name`, `last_name`, `username`, `email`, `password`,
            `profile`, `phone_number`, `cell_number`, `fax`, `profile_image`, `email_verification`, `otp`,
            `created`, `updated_at`, `status`, `id_kor`, `default`, `token`,
            `add_customer`, `update_customer`, `send_catalog`, `all_customers`, `proforma`, `email_on_salesman_order`,
            `json_payload`
        ) VALUES (
            ?,?,?,?,?,?,?,?,
            ?,?,?,?,?,?,?,
            ?,?,?,?,?,?,
            ?,?,?,?,?,?,
            ?
        )';

        $st = $pdo->prepare($sql);
        $st->execute([
            $uid,
            $uniqueId,
            $companyId,
            $firstName,
            $lastName,
            $username,
            $email,
            $password,
            $profile,
            $phoneNumber,
            $cellNumber,
            $fax,
            $profileImage,
            $emailVerification,
            $otp,
            $created,
            $updatedAt,
            $status,
            $idKor,
            $defaultCol,
            $token,
            $addCustomer,
            $updateCustomer,
            $sendCatalog,
            $allCustomers,
            $proforma,
            $emailOnSalesmanOrder,
            $json,
        ]);
    }

    /**
     * @param array<string, mixed> $res
     * @return list<array<string, mixed>>
     */
    private static function extractUserRows(array $res): array
    {
        $topKeys = [
            'list', 'users', 'usersList', 'userList', 'rows', 'records', 'items', 'user_list',
        ];
        foreach ($topKeys as $k) {
            if (isset($res[$k]) && is_array($res[$k])) {
                $rows = self::coerceAssocRows($res[$k]);
                if ($rows !== []) {
                    return $rows;
                }
            }
        }

        $data = $res['data'] ?? null;
        if (is_array($data)) {
            foreach (['list', 'users', 'usersList', 'userList', 'rows', 'records'] as $dk) {
                if (isset($data[$dk]) && is_array($data[$dk])) {
                    $rows = self::coerceAssocRows($data[$dk]);
                    if ($rows !== []) {
                        return $rows;
                    }
                }
            }
            $rows = self::coerceAssocRows($data);
            if ($rows !== []) {
                return $rows;
            }
        }

        $info = $res['info'] ?? null;
        if (is_array($info)) {
            foreach (['list', 'users', 'usersList', 'rows', 'records'] as $ik) {
                if (isset($info[$ik]) && is_array($info[$ik])) {
                    $rows = self::coerceAssocRows($info[$ik]);
                    if ($rows !== []) {
                        return $rows;
                    }
                }
            }
        }

        return [];
    }

    /**
     * @param array<mixed> $arr
     * @return list<array<string, mixed>>
     */
    private static function coerceAssocRows(array $arr): array
    {
        $rows = array_values(array_filter($arr, static fn ($x): bool => is_array($x)));

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function coerceFvUserId(array $row): int
    {
        foreach (['user_id', 'id', 'userId'] as $k) {
            if (!isset($row[$k])) {
                continue;
            }
            $v = $row[$k];
            if (is_numeric($v)) {
                $n = (int) $v;

                return $n > 0 ? $n : 0;
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function upsertUsersListRow(\PDO $pdo, array $row, string $json): void
    {
        $uid = (int) ($row['user_id'] ?? 0);
        $uniqueId = self::strOrNull($row['unique_id'] ?? null);
        $companyId = self::intOrNull($row['company_id'] ?? null);
        $firstName = (string) ($row['first_name'] ?? '');
        $lastName = (string) ($row['last_name'] ?? '');
        $username = (string) ($row['username'] ?? '');
        $email = (string) ($row['email'] ?? '');
        $password = (string) ($row['password'] ?? '');
        if (strlen($password) > 255) {
            $password = mb_substr($password, 0, 255);
        }
        $profile = self::intOrNull($row['profile'] ?? null);
        $phoneNumber = (string) ($row['phone_number'] ?? '');
        $cellNumber = (string) ($row['cell_number'] ?? '');
        $fax = (string) ($row['fax'] ?? '');
        $profileImage = (string) ($row['profile_image'] ?? '');
        $emailVerification = self::tinyInt($row['email_verification'] ?? 0);
        $otp = (string) ($row['otp'] ?? '');
        $created = self::datetimeOrNull($row['created'] ?? null);
        $updatedAt = self::datetimeOrNull($row['updated_at'] ?? null);
        $status = self::smallInt($row['status'] ?? 1);
        $idKor = self::bigint($row['id_kor'] ?? 0);
        $defaultCol = self::intOrNull($row['default'] ?? null);
        $token = (string) ($row['token'] ?? '');
        $addCustomer = self::reqInt($row['add_customer'] ?? 0);
        $updateCustomer = self::reqInt($row['update_customer'] ?? 0);
        $sendCatalog = self::reqInt($row['send_catalog'] ?? 0);
        $allCustomers = self::reqInt($row['all_customers'] ?? 0);
        $proforma = self::reqInt($row['proforma'] ?? 0);
        $emailOnSalesmanOrder = self::reqInt($row['email_on_salesman_order'] ?? 1);

        $sql = 'INSERT INTO `usersList` (
            `user_id`, `unique_id`, `company_id`, `first_name`, `last_name`, `username`, `email`, `password`,
            `profile`, `phone_number`, `cell_number`, `fax`, `profile_image`, `email_verification`, `otp`,
            `created`, `updated_at`, `status`, `id_kor`, `default`, `token`,
            `add_customer`, `update_customer`, `send_catalog`, `all_customers`, `proforma`, `email_on_salesman_order`,
            `json_payload`
        ) VALUES (
            ?,?,?,?,?,?,?,?,
            ?,?,?,?,?,?,?,
            ?,?,?,?,?,?,
            ?,?,?,?,?,?,
            ?
        ) ON DUPLICATE KEY UPDATE
            `unique_id` = VALUES(`unique_id`),
            `company_id` = VALUES(`company_id`),
            `first_name` = VALUES(`first_name`),
            `last_name` = VALUES(`last_name`),
            `username` = VALUES(`username`),
            `email` = VALUES(`email`),
            `password` = VALUES(`password`),
            `profile` = VALUES(`profile`),
            `phone_number` = VALUES(`phone_number`),
            `cell_number` = VALUES(`cell_number`),
            `fax` = VALUES(`fax`),
            `profile_image` = VALUES(`profile_image`),
            `email_verification` = VALUES(`email_verification`),
            `otp` = VALUES(`otp`),
            `created` = VALUES(`created`),
            `updated_at` = VALUES(`updated_at`),
            `status` = VALUES(`status`),
            `id_kor` = VALUES(`id_kor`),
            `default` = VALUES(`default`),
            `token` = VALUES(`token`),
            `add_customer` = VALUES(`add_customer`),
            `update_customer` = VALUES(`update_customer`),
            `send_catalog` = VALUES(`send_catalog`),
            `all_customers` = VALUES(`all_customers`),
            `proforma` = VALUES(`proforma`),
            `email_on_salesman_order` = VALUES(`email_on_salesman_order`),
            `json_payload` = VALUES(`json_payload`)';

        $st = $pdo->prepare($sql);
        $st->execute([
            $uid,
            $uniqueId,
            $companyId,
            $firstName,
            $lastName,
            $username,
            $email,
            $password,
            $profile,
            $phoneNumber,
            $cellNumber,
            $fax,
            $profileImage,
            $emailVerification,
            $otp,
            $created,
            $updatedAt,
            $status,
            $idKor,
            $defaultCol,
            $token,
            $addCustomer,
            $updateCustomer,
            $sendCatalog,
            $allCustomers,
            $proforma,
            $emailOnSalesmanOrder,
            $json,
        ]);
    }

    private static function strOrNull(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    private static function intOrNull(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return (int) $v;
        }

        return null;
    }

    private static function reqInt(mixed $v): int
    {
        if ($v === null || $v === '') {
            return 0;
        }
        if (is_numeric($v)) {
            return (int) $v;
        }

        return 0;
    }

    private static function bigint(mixed $v): int
    {
        if ($v === null || $v === '') {
            return 0;
        }
        if (is_numeric($v)) {
            return (int) $v;
        }

        return 0;
    }

    private static function tinyInt(mixed $v): int
    {
        return self::reqInt($v);
    }

    private static function smallInt(mixed $v): int
    {
        return self::reqInt($v);
    }

    private static function datetimeOrNull(mixed $v): ?string
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
}
