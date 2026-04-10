<?php

declare(strict_types=1);

/**
 * Lectura de `customer_groups` en la BD del sitio (tras CustomerGroupsSync) para precios / UI.
 */
final class CustomerGroupsLocal
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function list(?string $languageId = null, ?int $companyId = null): array
    {
        if (!Db::enabled()) {
            return [];
        }
        $pdo = Db::pdo();
        $sql = 'SELECT `group_id`, `language_id`, `company_id`, `user_id`, `name`, `percentage_on_price`,
            `percent_price_amount`, `created_at`, `group_status`, `id_kor`, `default` AS `default_flag`
            FROM `customer_groups` WHERE 1=1';
        $params = [];
        if ($languageId !== null && $languageId !== '') {
            $sql .= ' AND `language_id` = :lid';
            $params[':lid'] = (int) $languageId;
        }
        $cidFilter = $companyId;
        if (($cidFilter === null || $cidFilter <= 0) && ctype_digit(trim((string) config('FULLVENDOR_COMPANY_ID', '')))) {
            $cidFilter = (int) trim((string) config('FULLVENDOR_COMPANY_ID', ''));
        }
        if ($cidFilter !== null && $cidFilter > 0) {
            $sql .= ' AND `company_id` = :cid';
            $params[':cid'] = $cidFilter;
        }
        $sql .= ' ORDER BY `group_id` ASC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public static function byGroupId(int $groupId): ?array
    {
        if (!Db::enabled() || $groupId <= 0) {
            return null;
        }
        $st = Db::pdo()->prepare(
            'SELECT `group_id`, `language_id`, `company_id`, `user_id`, `name`, `percentage_on_price`,
                `percent_price_amount`, `created_at`, `group_status`, `id_kor`, `default` AS `default_flag`
             FROM `customer_groups` WHERE `group_id` = ? LIMIT 1'
        );
        $st->execute([$groupId]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);

        return is_array($r) ? $r : null;
    }
}
