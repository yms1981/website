<?php

declare(strict_types=1);

/**
 * Productos y categorías: desde FullVendor por petición (HTTP o MySQL según FULLVENDOR_DATA_SOURCE en .env).
 * Otros volcados (customers.json, etc.) siguen usando saveDataDump.
 */
final class CatalogCache
{
    /**
     * Guarda `{ "ts", "data" }` en data/cache (p. ej. customers, usersList).
     * No lanza: fallos de disco solo se registran en error_log.
     *
     * @param non-empty-string $filenameBase solo letras, números, guiones
     */
    public static function saveDataDump(string $filenameBase, mixed $data): void
    {
        try {
            $dir = dirname(__DIR__) . '/data/cache';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (!is_dir($dir) || !is_writable($dir)) {
                return;
            }
            $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $filenameBase);
            if ($safe === '') {
                return;
            }
            $path = $dir . '/' . $safe . '.json';
            $payload = json_encode(
                ['ts' => time(), 'data' => $data],
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );
            if ($payload !== false) {
                @file_put_contents($path, $payload, LOCK_EX);
            }
        } catch (Throwable $e) {
            error_log('[CatalogCache] saveDataDump: ' . $e->getMessage());
        }
    }

    /** @param array<int, array<string, mixed>> $products */
    private static function dedup(array $products): array
    {
        $seen = [];
        $out = [];
        foreach ($products as $p) {
            $sku = isset($p['sku']) ? strtolower(trim((string) $p['sku'])) : '';
            if ($sku !== '' && isset($seen[$sku])) {
                continue;
            }
            if ($sku !== '') {
                $seen[$sku] = true;
            }
            $out[] = $p;
        }

        return $out;
    }

    /** @param array<int, array<string, mixed>> $products */
    private static function filterActive(array $products): array
    {
        return array_values(array_filter($products, static function (array $p): bool {
            $active = (string) ($p['status'] ?? '') === '1';
            $hasImg = !empty($p['images'][0]['pic']);

            return $active && $hasImg;
        }));
    }

    /** @return array<int, array<string, mixed>> */
    public static function getProducts(string $languageId): array
    {
        if (!fullvendor_configured()) {
            return [];
        }
        try {
            $list = FullVendor::getProducts(null, null, $languageId);
            $processed = self::dedup(self::filterActive($list));
            usort($processed, static fn ($a, $b) => ((int) ($a['catalog_order'] ?? 999)) <=> ((int) ($b['catalog_order'] ?? 999)));

            return $processed;
        } catch (Throwable $e) {
            error_log('[CatalogCache] getProducts: ' . $e->getMessage());

            return [];
        }
    }

    /** @return array<int, array<string, mixed>> */
    public static function getCategories(string $languageId): array
    {
        if (!fullvendor_configured()) {
            return [];
        }
        try {
            $cats = FullVendor::getCategories($languageId);
            /* Orden: el de FullVendor (modo db = order_categories.order del catálogo; modo api = orden de categoryList). No reordenar alfabéticamente. */
            $filtered = array_values(array_filter($cats, static fn ($c): bool => is_array($c)));

            return array_values(array_map(
                static fn ($c): array => hv_normalize_category(is_array($c) ? $c : []),
                $filtered
            ));
        } catch (Throwable $e) {
            error_log('[CatalogCache] getCategories: ' . $e->getMessage());

            return [];
        }
    }
}
