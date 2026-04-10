<?php

declare(strict_types=1);

/**
 * Punto de entrada HTTP para el carrito vía index.php (/{lang}/account/cart-db).
 * Misma sesión que el resto del sitio; escribe/lee MySQL con CustomerCatalogCart / PortalCart.
 */
header('X-Content-Type-Options: nosniff');
require_once dirname(__DIR__, 2) . '/lib/LocalCart.php';
LocalCart::dispatch();
