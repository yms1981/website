<?php

declare(strict_types=1);

/**
 * Rellena la tabla local `customer_groups` desde:
 * - FULLVENDOR_DATA_SOURCE=api → POST customergroupsList (o FULLVENDOR_CUSTOMER_GROUPS_LIST_ENDPOINT) por idioma
 * - FULLVENDOR_DATA_SOURCE=db  → SELECT en la tabla remota customer_group (FullVendorDb)
 */
final class CustomerGroupsSync
{
    /** @var list<string> */
    private const LANGUAGE_IDS = ['1', '2'];

    private static function trace(string $message, string $level = 'INFO'): void
    {
        error_log('[CustomerGroupsSync] ' . $message);
        if (class_exists('AppLog', false)) {
            AppLog::sync('[customer_groups] ' . $message, $level);
        }
    }

    /**
     * @return array{upserted:int, source:string, errors:list<string>}
     */
    public static function syncFromFullVendor(): array
    {
        $out = [
            'upserted' => 0,
            'source' => '',
            'errors' => [],
            'rows_seen' => 0,
            'skipped_no_group_id' => 0,
            'hint' => '',
        ];

        if (!Db::enabled()) {
            $out['errors'][] = 'Database not configured';

            return $out;
        }

        $ds = fullvendor_data_source();
        if ($ds === 'db') {
            if (!fullvendor_db_configured()) {
                $out['errors'][] = 'FULLVENDOR_DB_* no configurado (modo db)';

                return $out;
            }
            $out['source'] = 'db';

            try {
                require_once __DIR__ . '/FullVendorDb.php';
                $rows = FullVendorDb::fetchCustomerGroups(null);
                $out['rows_seen'] = count($rows);
                foreach ($rows as $r) {
                    if (!is_array($r)) {
                        continue;
                    }
                    $norm = self::normalizeRow($r);
                    if ($norm === null) {
                        $out['skipped_no_group_id']++;
                        continue;
                    }
                    try {
                        self::upsert(Db::pdo(), $norm);
                        $out['upserted']++;
                    } catch (Throwable $e) {
                        $out['errors'][] = 'group_id ' . ($norm['group_id'] ?? '?') . ': ' . $e->getMessage();
                    }
                }
            } catch (Throwable $e) {
                $out['errors'][] = $e->getMessage();
                self::trace($e->getMessage(), 'ERROR');
            }
            self::fillHint($out);

            return $out;
        }

        if (!fullvendor_api_configured()) {
            $out['errors'][] = 'FullVendor API no configurada (modo api)';

            return $out;
        }
        $out['source'] = 'api';

        try {
            foreach (self::LANGUAGE_IDS as $lid) {
                $list = FullVendor::customerGroupsList($lid);
                $out['rows_seen'] += count($list);
                foreach ($list as $r) {
                    if (!is_array($r)) {
                        continue;
                    }
                    $norm = self::normalizeRow($r);
                    if ($norm === null) {
                        $out['skipped_no_group_id']++;
                        continue;
                    }
                    try {
                        self::upsert(Db::pdo(), $norm);
                        $out['upserted']++;
                    } catch (Throwable $e) {
                        $out['errors'][] = 'group_id ' . ($norm['group_id'] ?? '?') . ': ' . $e->getMessage();
                    }
                }
            }
        } catch (Throwable $e) {
            $out['errors'][] = $e->getMessage();
            self::trace($e->getMessage(), 'ERROR');
        }
        self::fillHint($out);

        return $out;
    }

    /**
     * @param array{upserted:int, source:string, errors:list<string>, rows_seen:int, skipped_no_group_id:int, hint:string} $out
     */
    private static function fillHint(array &$out): void
    {
        if ((int) $out['upserted'] > 0) {
            return;
        }
        if ($out['source'] === 'db' && (int) $out['rows_seen'] === 0) {
            $out['hint'] = 'La consulta a customer_group no devolvió filas: revise FULLVENDOR_COMPANY_ID, nombre de tabla (FULLVENDOR_DB_TABLE_CUSTOMER_GROUP) y FULLVENDOR_DB_CUSTOMER_GROUP_RELAX_COMPANY=1.';

            return;
        }
        if ($out['source'] === 'api' && (int) $out['rows_seen'] === 0) {
            $out['hint'] = 'La API no devolvió filas en ningún idioma: confirme FULLVENDOR_CUSTOMER_GROUPS_LIST_ENDPOINT y que el token tenga permiso. Pruebe FULLVENDOR_SAVE_API_JSON=lists y revise data/cache/fv-*.json.';

            return;
        }
        if ((int) $out['skipped_no_group_id'] > 0) {
            $out['hint'] = 'Hubo filas pero ninguna tenía group_id reconocible: revise columnas del JSON/API o de la BD remota.';
        }
    }

    /**
     * @param array<string, mixed> $r
     * @return array{
     *   group_id:int,
     *   language_id:?int,
     *   company_id:?int,
     *   user_id:?int,
     *   name:?string,
     *   percentage_on_price:?string,
     *   percent_price_amount:?float,
     *   created_at:?string,
     *   group_status:int,
     *   id_kor:int,
     *   default_flag:?int
     * }|null
     */
    private static function normalizeRow(array $r): ?array
    {
        $r = self::augmentLowercaseKeys($r);

        $gid = self::pickInt($r, [
            'group_id', 'Group_Id', 'groupId', 'customer_group_id', 'Customer_Group_Id',
            'customergroup_id', 'groupID', 'ID',
        ]);
        if ($gid === null || $gid <= 0) {
            $gid = self::guessGroupIdFromRow($r);
        }
        if ($gid === null || $gid <= 0) {
            return null;
        }

        $companyFromCfg = trim((string) config('FULLVENDOR_COMPANY_ID', ''));
        $companyCfgInt = ctype_digit($companyFromCfg) ? (int) $companyFromCfg : null;

        $lang = self::pickInt($r, ['language_id', 'Language_Id', 'languageId']);
        $comp = self::pickInt($r, ['company_id', 'Company_Id', 'companyId']) ?? $companyCfgInt;
        $uid = self::pickInt($r, ['user_id', 'User_Id', 'userId']);

        $name = self::pickString($r, ['name', 'Name', 'group_name']);
        $pctStr = self::pickString($r, ['percentage_on_price', 'percentageOnPrice', 'Percentage_On_Price']);
        $amt = self::pickFloat($r, ['percent_price_amount', 'percentPriceAmount', 'Percent_Price_Amount']);

        $created = self::pickString($r, ['created_at', 'createdAt', 'Created_At', 'group_created_at']);
        if ($created !== null && $created !== '') {
            $created = preg_replace('/\.\d+$/', '', $created) ?? $created;
        } else {
            $created = null;
        }

        $gstat = self::pickInt($r, ['group_status', 'groupStatus', 'Group_Status']);
        if ($gstat === null) {
            $gstat = 1;
        }
        $idKor = self::pickInt($r, ['id_kor', 'idKor', 'Id_Kor']);
        if ($idKor === null) {
            $idKor = 1;
        }
        $def = self::pickInt($r, ['default', 'is_default', 'group_default', 'Default']);

        return [
            'group_id' => $gid,
            'language_id' => $lang,
            'company_id' => $comp,
            'user_id' => $uid,
            'name' => $name,
            'percentage_on_price' => $pctStr,
            'percent_price_amount' => $amt,
            'created_at' => $created,
            'group_status' => $gstat,
            'id_kor' => $idKor,
            'default_flag' => $def,
        ];
    }

    /** @param array<string, mixed> $row */
    private static function pickInt(array $row, array $keys): ?int
    {
        $v = self::pickScalar($row, $keys);
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v)) {
            return (int) $v;
        }
        $s = trim((string) $v);
        if ($s === '' || !is_numeric($s)) {
            return null;
        }

        return (int) $s;
    }

    /** @param array<string, mixed> $row */
    private static function pickFloat(array $row, array $keys): ?float
    {
        $v = self::pickScalar($row, $keys);
        if ($v === null || $v === '') {
            return null;
        }
        if (is_float($v) || is_int($v)) {
            return (float) $v;
        }
        $s = trim((string) $v);
        if ($s === '' || !is_numeric($s)) {
            return null;
        }

        return (float) $s;
    }

    /** @param array<string, mixed> $row */
    private static function pickString(array $row, array $keys): ?string
    {
        $v = self::pickScalar($row, $keys);
        if ($v === null) {
            return null;
        }

        return trim((string) $v) === '' ? null : trim((string) $v);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $keys
     */
    private static function pickScalar(array $row, array $keys): mixed
    {
        if (!is_array($keys)) {
            return null;
        }
        foreach ($keys as $k) {
            if (array_key_exists($k, $row)) {
                return $row[$k];
            }
        }
        foreach ($row as $rk => $rv) {
            foreach ($keys as $k) {
                if (strcasecmp((string) $rk, $k) === 0) {
                    return $rv;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $r */
    private static function augmentLowercaseKeys(array $r): array
    {
        foreach ($r as $k => $v) {
            if (is_string($k) && $k !== strtolower($k)) {
                $r[strtolower($k)] = $v;
            }
        }

        return $r;
    }

    /**
     * Si la API usa otra convención de nombre (p. ej. solo "id" con prefijo en el padre).
     *
     * @param array<string, mixed> $r
     */
    private static function guessGroupIdFromRow(array $r): ?int
    {
        foreach ($r as $k => $v) {
            if (!is_string($k)) {
                continue;
            }
            $compact = strtolower(preg_replace('/[\s_-]+/', '', $k) ?? $k);
            if ($compact === 'groupid' || str_contains($compact, 'groupid') || $compact === 'customergroupid') {
                $i = self::coercePositiveInt($v);
                if ($i !== null) {
                    return $i;
                }
            }
        }

        return self::coercePositiveInt($r['id'] ?? null);
    }

    private static function coercePositiveInt(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v)) {
            return $v > 0 ? $v : null;
        }
        if (is_float($v)) {
            $i = (int) $v;

            return $i > 0 ? $i : null;
        }
        $s = trim((string) $v);
        if ($s === '' || !is_numeric($s)) {
            return null;
        }
        $i = (int) $s;

        return $i > 0 ? $i : null;
    }

    /**
     * @param array{
     *   group_id:int,
     *   language_id:?int,
     *   company_id:?int,
     *   user_id:?int,
     *   name:?string,
     *   percentage_on_price:?string,
     *   percent_price_amount:?float,
     *   created_at:?string,
     *   group_status:int,
     *   id_kor:int,
     *   default_flag:?int
     * } $n
     */
    private static function upsert(\PDO $pdo, array $n): void
    {
        $sql = 'INSERT INTO `customer_groups` (
            `group_id`, `language_id`, `company_id`, `user_id`, `name`, `percentage_on_price`, `percent_price_amount`,
            `created_at`, `group_status`, `id_kor`, `default`
        ) VALUES (
            :gid, :lid, :cid, :uid, :name, :pop, :ppa, :cat, :gst, :ikor, :dfl
        ) ON DUPLICATE KEY UPDATE
            `language_id` = VALUES(`language_id`),
            `company_id` = VALUES(`company_id`),
            `user_id` = VALUES(`user_id`),
            `name` = VALUES(`name`),
            `percentage_on_price` = VALUES(`percentage_on_price`),
            `percent_price_amount` = VALUES(`percent_price_amount`),
            `created_at` = VALUES(`created_at`),
            `group_status` = VALUES(`group_status`),
            `id_kor` = VALUES(`id_kor`),
            `default` = VALUES(`default`)';

        $st = $pdo->prepare($sql);
        $st->execute([
            ':gid' => $n['group_id'],
            ':lid' => $n['language_id'],
            ':cid' => $n['company_id'],
            ':uid' => $n['user_id'],
            ':name' => $n['name'],
            ':pop' => $n['percentage_on_price'],
            ':ppa' => $n['percent_price_amount'],
            ':cat' => $n['created_at'],
            ':gst' => $n['group_status'],
            ':ikor' => $n['id_kor'],
            ':dfl' => $n['default_flag'],
        ]);
    }
}
