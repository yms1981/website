<?php

declare(strict_types=1);

/**
 * Campos de presentación para listados de pedidos (API legacy + lectura BD).
 */
final class HvOrderUi
{
    /** @var array<int, string> */
    private const STATUS_EN = [
        0 => 'New',
        1 => 'Placed',
        2 => 'Processing',
        3 => 'Packed',
        4 => 'Shipped',
        5 => 'Delivered',
        6 => 'Completed',
        7 => 'Cancelled',
        8 => 'On hold',
        9 => 'Pending payment',
        10 => 'Partial',
        11 => 'Returned',
        12 => 'Rejected',
    ];

    /** @var array<int, string> */
    private const STATUS_ES = [
        0 => 'Nuevo',
        1 => 'Colocado',
        2 => 'En proceso',
        3 => 'Empacado',
        4 => 'Enviado',
        5 => 'Entregado',
        6 => 'Completado',
        7 => 'Cancelado',
        8 => 'En espera',
        9 => 'Pago pendiente',
        10 => 'Parcial',
        11 => 'Devuelto',
        12 => 'Rechazado',
    ];

    /**
     * Estados tipo "Warehouse Completed" que no deben tratarse como el completado final del listado.
     */
    private static function statusTextIsWarehouseCompleted(string $s): bool
    {
        $s = mb_strtolower(trim($s));
        if ($s === '') {
            return false;
        }
        if (preg_match('/warehouse\s*[-\s]*completed\b/u', $s) === 1) {
            return true;
        }
        if (preg_match('/\bcompleted\s+in\s+warehouse\b/u', $s) === 1) {
            return true;
        }
        if (preg_match('/almac[eé]n\s+completad[oa]s?\b/u', $s) === 1) {
            return true;
        }
        if (preg_match('/bodega\s+completad[oa]s?\b/u', $s) === 1) {
            return true;
        }
        if (preg_match('/completad[oa]s?\s+en\s+(el\s+)?(almac[eé]n|bodega)\b/u', $s) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Fila de listado: pedido en estado completado (código legacy 6 o etiqueta EN/ES desde BD).
     * Excluye "Warehouse Completed" y equivalentes (no es el mismo cierre que "Completed" solo).
     *
     * @param array<string, mixed> $o
     */
    public static function orderRowIsCompleted(array $o): bool
    {
        $checks = [
            mb_strtolower(trim((string) ($o['status_label'] ?? ''))),
            mb_strtolower(trim((string) ($o['name_status_english'] ?? ''))),
            mb_strtolower(trim((string) ($o['name_status_spanish'] ?? ''))),
        ];
        foreach ($checks as $s) {
            if ($s !== '' && self::statusTextIsWarehouseCompleted($s)) {
                return false;
            }
        }

        $code = null;
        if (isset($o['order_status']) && is_numeric($o['order_status'])) {
            $code = (int) $o['order_status'];
        } elseif (isset($o['status']) && is_numeric($o['status'])) {
            $code = (int) $o['status'];
        }
        if ($code === 6) {
            return true;
        }
        foreach ($checks as $s) {
            if ($s === '') {
                continue;
            }
            if (preg_match('/\bcompleted\b/u', $s) === 1 || preg_match('/\bcompletad[oa]s?\b/u', $s) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function enrichOrderRow(array $o, string $lang): array
    {
        $langNorm = strtolower(explode('-', trim($lang), 2)[0] ?? 'en');
        $isEs = str_starts_with($langNorm, 'es');

        $customerName = trim((string) ($o['customer_name'] ?? ''));
        if ($customerName === '') {
            $customerName = self::deriveCustomerName($o);
        }

        $sellerName = trim((string) ($o['seller_name'] ?? ''));

        $warehouse = trim((string) ($o['warehouse_name'] ?? ''));

        $comments = (string) ($o['order_comments'] ?? $o['comments'] ?? '');

        $totalVal = self::parseAmount($o['total_value'] ?? $o['total_amount_raw'] ?? null);
        if ($totalVal <= 0) {
            $totalVal = self::parseAmount($o['total_amount'] ?? $o['ordered_total'] ?? $o['total'] ?? 0);
        }

        $assignedVal = self::parseAmount($o['assigned_value'] ?? $o['assigned_amount_raw'] ?? null);
        if ($assignedVal <= 0 && isset($o['total_delivered'])) {
            $assignedVal = self::parseAmount($o['total_delivered']);
        }

        $statusLabel = trim((string) ($o['status_label'] ?? ''));
        if ($statusLabel === '') {
            $ne = trim((string) ($o['name_status_english'] ?? ''));
            $ns = trim((string) ($o['name_status_spanish'] ?? ''));
            if ($isEs && $ns !== '') {
                $statusLabel = $ns;
            } elseif ($ne !== '') {
                $statusLabel = $ne;
            } elseif ($isEs && $ns === '' && $ne !== '') {
                $statusLabel = $ne;
            } else {
                $rawSt = $o['status'] ?? null;
                $code = isset($o['order_status']) ? (int) $o['order_status'] : null;
                if ($code === null && is_numeric($rawSt)) {
                    $code = (int) $rawSt;
                }
                if ($code !== null) {
                    $map = $isEs ? self::STATUS_ES : self::STATUS_EN;
                    $statusLabel = $map[$code] ?? ('#' . $code);
                } else {
                    $fallback = trim((string) ($rawSt ?? ''));
                    $statusLabel = $fallback !== '' ? $fallback : '#0';
                }
            }
        }

        $src = strtolower(trim((string) ($o['order_source'] ?? $o['source'] ?? '')));
        $isMobile = $src === 'app' || str_contains($src, 'movil') || str_contains($src, 'mobile');

        $statusColor = trim((string) ($o['status_color'] ?? $o['scolor'] ?? ''));
        $statusIconColor = trim((string) ($o['status_icon_color'] ?? ''));

        return array_merge($o, [
            'customer_name' => $customerName,
            'customer_display_name' => $customerName,
            'seller_name' => $sellerName,
            'warehouse_name' => $warehouse,
            'order_comments' => $comments,
            'total_value' => $totalVal,
            'assigned_value' => $assignedVal,
            'status_label' => $statusLabel,
            'is_mobile_order' => $isMobile,
            'status_color' => $statusColor,
            'status_icon_color' => $statusIconColor !== '' ? $statusIconColor : $statusColor,
        ]);
    }

    /**
     * @param array<string, mixed> $o
     */
    private static function deriveCustomerName(array $o): string
    {
        $bn = trim((string) ($o['business_name'] ?? ''));
        $nm = trim((string) ($o['name'] ?? ''));
        if ($bn !== '' && $nm !== '' && strcasecmp($bn, $nm) !== 0) {
            return $bn . ' (' . $nm . ')';
        }

        return $bn !== '' ? $bn : $nm;
    }

    private static function parseAmount(mixed $v): float
    {
        if ($v === null || $v === '') {
            return 0.0;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = preg_replace('/[^0-9.\-]/', '', (string) $v);

        return $s === '' || $s === '-' ? 0.0 : (float) $s;
    }
}
