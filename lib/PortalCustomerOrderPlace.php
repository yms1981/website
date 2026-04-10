<?php

declare(strict_types=1);

/**
 * Colocar pedido cliente (rol 3).
 *
 * Acepta:
 * - Cuerpo tipo **OrderPlace** (FullVendor): `Id`, `created`, `contactName`, `itemList`, `user_id`, `customer_id`, etc. (como envía el carrito en JS).
 * - DTO compacto (compat.): `customerFvId`, `items`, `orderComment`, `lang` y opcional `userId` o cabecera `X-HV-Portal-Seller-User-Id`.
 *
 * El envío a FullVendor lo hace siempre {@see FullVendor::createOrder} → addOrder (misma lógica que el vendedor).
 */
final class PortalCustomerOrderPlace
{
    public static function execute(array $session, array $b): void
    {
        if ((int) ($session['rolId'] ?? 0) !== 3) {
            json_response(['error' => 'Forbidden'], 403);
        }
        if ((int) ($session['customerId'] ?? 0) <= 0) {
            json_response(['error' => 'No customer account linked. Please contact support to place orders.'], 403);
        }

        if (isset($b['itemList']) && is_array($b['itemList'])) {
            self::placeFromOrderPlaceShape($session, $b);

            return;
        }

        $items = $b['items'] ?? [];
        $customerFvIdReq = (int) ($b['customerFvId'] ?? 0);
        $userIdReq = (int) ($b['userId'] ?? $b['sellerUserId'] ?? 0);
        if ($userIdReq <= 0) {
            $hdr = (string) ($_SERVER['HTTP_X_HV_PORTAL_SELLER_USER_ID'] ?? '');
            if ($hdr !== '' && ctype_digit($hdr)) {
                $userIdReq = (int) $hdr;
            }
        }
        if ($userIdReq <= 0) {
            json_response(['error' => 'Select a seller (userId or header X-HV-Portal-Seller-User-Id)'], 400);
        }
        $orderComment = (string) ($b['orderComment'] ?? '');
        $lang = (string) ($b['lang'] ?? 'en');
        if ($customerFvIdReq <= 0) {
            json_response(['error' => 'customerFvId required'], 400);
        }
        if (!is_array($items) || count($items) === 0) {
            json_response(['error' => 'At least one item required'], 400);
        }
        $mapped = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $mapped[] = [
                'product_id' => (int) ($it['product_id'] ?? 0),
                'qty' => (int) ($it['qty'] ?? 0),
                'sale_price' => (float) ($it['sale_price'] ?? 0),
                'line_note' => trim((string) ($it['line_note'] ?? $it['lineNote'] ?? $it['comment'] ?? '')),
            ];
        }
        if (count($mapped) === 0) {
            json_response(['error' => 'No valid line items'], 400);
        }

        self::finalizePortalOrder($session, $customerFvIdReq, $userIdReq, $orderComment, $lang, $mapped);
    }

    /** @param array<string, mixed> $b */
    private static function placeFromOrderPlaceShape(array $session, array $b): void
    {
        $customerFvIdReq = (int) ($b['customer_id'] ?? 0);
        $userIdReq = (int) ($b['user_id'] ?? 0);
        if ($userIdReq <= 0) {
            $hdr = (string) ($_SERVER['HTTP_X_HV_PORTAL_SELLER_USER_ID'] ?? '');
            if ($hdr !== '' && ctype_digit($hdr)) {
                $userIdReq = (int) $hdr;
            }
        }
        if ($userIdReq <= 0) {
            json_response(['error' => 'user_id required (seller for this order)'], 400);
        }
        if ($customerFvIdReq <= 0) {
            json_response(['error' => 'customer_id required'], 400);
        }
        $orderComment = (string) ($b['order_comment'] ?? '');
        $lid = (int) ($b['language_id'] ?? 1);
        $lang = $lid === 2 ? 'es' : 'en';
        $itemList = $b['itemList'];
        $mapped = [];
        foreach ($itemList as $it) {
            if (!is_array($it)) {
                continue;
            }
            $pid = (int) ($it['product_id'] ?? 0);
            $qty = (int) ($it['qty'] ?? 0);
            $unit = (float) ($it['salesp'] ?? $it['sale_price'] ?? 0);
            if ($pid <= 0 || $qty <= 0) {
                continue;
            }
            if (!is_finite($unit) || $unit < 0) {
                $unit = 0.0;
            }
            $mapped[] = [
                'product_id' => $pid,
                'qty' => $qty,
                'sale_price' => $unit,
                'line_note' => trim((string) ($it['comment'] ?? $it['line_note'] ?? '')),
            ];
        }
        if (count($mapped) === 0) {
            json_response(['error' => 'No valid line items in itemList'], 400);
        }

        self::finalizePortalOrder($session, $customerFvIdReq, $userIdReq, $orderComment, $lang, $mapped);
    }

    /**
     * @param list<array{product_id:int,qty:int,sale_price:float,line_note:string}> $mapped
     */
    private static function finalizePortalOrder(
        array $session,
        int $customerFvIdReq,
        int $userIdReq,
        string $orderComment,
        string $lang,
        array $mapped
    ): void {
        $subtotal = 0.0;
        foreach ($mapped as $row) {
            $subtotal += $row['qty'] * $row['sale_price'];
        }
        $meta = ['rolId' => 3];
        $discountPct = 0.0;
        $orderCustomerId = (int) ($session['customerId'] ?? 0);
        if (Db::enabled()) {
            $pdoC = Db::pdo();
            $stc = $pdoC->prepare(
                'SELECT `customeridfullvendor`, `customer_id`, `name`, `business_name`, `discount`, `group_name`, `percentage_on_price`, `user_id` FROM `customers`'
                . ' WHERE `customeridfullvendor` = ? OR `customer_id` = ? LIMIT 1'
            );
            $cidFv = (int) ($session['customerId'] ?? 0);
            $stc->execute([$cidFv, $cidFv]);
            $crow = $stc->fetch(\PDO::FETCH_ASSOC);
            if (is_array($crow)) {
                $resolvedFvCustomerId = (int) ($crow['customeridfullvendor'] ?? 0);
                if ($resolvedFvCustomerId <= 0) {
                    $resolvedFvCustomerId = (int) ($crow['customer_id'] ?? 0);
                }
                if ($resolvedFvCustomerId > 0) {
                    $orderCustomerId = $resolvedFvCustomerId;
                }
                $meta['contactName'] = (string) ($crow['name'] ?? '');
                $meta['bussName'] = (string) ($crow['business_name'] ?? '');
                $discountPct = isset($crow['discount']) ? (float) $crow['discount'] : 0.0;
                $meta['customerDiscountPct'] = $discountPct;
                $meta['groupcustomer'] = (string) ($crow['group_name'] ?? '');
                $meta['tipolista'] = '';
                $meta['percprice'] = isset($crow['percentage_on_price']) ? (float) $crow['percentage_on_price'] : 0.0;
                $allowed = false;
                foreach (preg_split('/\s*,\s*/', trim((string) ($crow['user_id'] ?? ''))) ?: [] as $sidRaw) {
                    if ((int) trim((string) $sidRaw) === $userIdReq) {
                        $allowed = true;
                        break;
                    }
                }
                if (!$allowed) {
                    json_response(['error' => 'Selected seller is not assigned to this customer'], 400);
                }
                $meta['preferredSellerUserId'] = $userIdReq;
            }
        }
        if ($orderCustomerId <= 0) {
            json_response(['error' => 'Customer FullVendor ID not found in customers table'], 400);
        }
        $sessCustomerId = (int) ($session['customerId'] ?? 0);
        $allowedFv = array_values(array_unique(array_filter(
            [$orderCustomerId, $sessCustomerId],
            static function ($n) {
                return (int) $n > 0;
            }
        )));
        if (!in_array($customerFvIdReq, $allowedFv, true)) {
            json_response(['error' => 'customer_id does not match this account'], 403);
        }
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
        try {
            $res = FullVendor::createOrder(
                (int) ($session['userId'] ?? 0),
                $orderCustomerId,
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
        try {
            FullVendor::clearCart((int) ($session['userId'] ?? 0));
        } catch (Throwable) {
        }
        if (Db::enabled()) {
            require_once dirname(__DIR__) . '/lib/CustomerCatalogCart.php';
            require_once dirname(__DIR__) . '/lib/PortalCart.php';
            $pdoOrd = Db::pdo();
            if (CustomerCatalogCart::uses($session)) {
                try {
                    CustomerCatalogCart::clearAll($pdoOrd, $session);
                } catch (Throwable) {
                }
            } elseif (PortalCart::usesPortalCart($session)) {
                try {
                    PortalCart::clearAll($pdoOrd, $session);
                } catch (Throwable) {
                }
            }
        }
        json_response(['success' => true, 'orderId' => $res['order_id'] ?? null]);
    }
}
