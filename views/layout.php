<?php
$layoutHeadExtra = $layoutHeadExtra ?? '';
$pageTitle = $pageTitle ?? ($dict['seo']['title'] ?? 'Home Value');
$hvBodyRol = 0;
$hvBodySellerUid = 0;
$hvPortalCart = false;
$hvShowMsgDrawer = false;
$hvSellerCartHint = '';
/** Coherente con {@see Auth::getSession()}: la UI no debe basarse solo en la cookie pública `hv-logged-in`. */
$hvAuthApproved = false;
if (class_exists('Auth', false)) {
    $hvSessBody = Auth::getSession();
    if (is_array($hvSessBody)) {
        $hvAuthApproved = !empty($hvSessBody['approved']);
        $hvBodyRol = (int) ($hvSessBody['rolId'] ?? 0);
        if ($hvBodyRol === 2) {
            $hvBodySellerUid = (int) ($hvSessBody['userId'] ?? 0);
            $hvSellerCartHint = (string) (($dict['cart'] ?? [])['seller_cart_hint'] ?? '');
        } elseif ($hvAuthApproved && class_exists('Db', false) && Db::enabled()) {
            $hvPortalCart = true;
        }
        if ($hvAuthApproved) {
            $hvRDrawer = (int) ($hvSessBody['rolId'] ?? 0);
            $hvShowMsgDrawer = ($hvRDrawer === 2 || $hvRDrawer === 3);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>" class="<?= !empty($showCatalogLoader) ? 'hv-catalog-load-lock' : '' ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle) ?></title>
  <meta name="description" content="<?= e($dict['seo']['description'] ?? '') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(base_url()) ?>/assets/css/app.css?v=14">
  <?php if ($layoutHeadExtra !== '') { ?>
  <?= $layoutHeadExtra ?>
  <?php } ?>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.24.0/dist/sweetalert2.min.css" crossorigin="anonymous">
  <?php if (!empty($showCatalogLoader) || !empty($hvAuthLoaderStyles)) { ?>
  <style id="hv-catalog-loader-css"><?php include __DIR__ . '/partials/catalog-loader.css.php'; ?></style>
  <?php } ?>
  <script>window.HV_BASE = <?= json_encode(base_url(), JSON_THROW_ON_ERROR) ?>; window.HV_BASE_PATH = <?= json_encode(base_path(), JSON_THROW_ON_ERROR) ?>; window.HV_SELLER_USER_ID = <?= (int) $hvBodySellerUid ?>; window.HV_ROL = <?= (int) $hvBodyRol ?>; window.HV_SWAL_I18N = <?= json_encode([
      'confirm' => $dict['common']['confirm'] ?? 'OK',
      'cancel' => $dict['common']['cancel'] ?? 'Cancel',
  ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;</script>
  <?php if (!empty($hvShowMsgDrawer)) {
      $hvM = $dict['messaging'] ?? []; ?>
  <script>
  (function () {
    document.addEventListener('click', function (e) {
      var a = e.target.closest && e.target.closest('a.hv-messages-nav-trigger');
      if (!a) return;
      if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
      var h = a.getAttribute('href') || '';
      if (h.indexOf('account/messages') === -1) return;
      var p = (window.location.pathname || '').replace(/\/$/, '');
      if (/\/account\/messages$/.test(p)) {
        e.preventDefault();
        document.dispatchEvent(new CustomEvent('hv-messages-toggle-sidebar'));
        return;
      }
      if (typeof window.HV_openMessagesDrawer === 'function') {
        e.preventDefault();
        window.HV_openMessagesDrawer();
      }
    }, true);
  })();
  </script>
  <script>
  window.HV_MSG_DRAWER_LANG = <?= json_encode($lang, JSON_THROW_ON_ERROR) ?>;
  window.HV_MSG_DRAWER_DICT = <?= json_encode([
      'chats' => $hvM['chats'] ?? 'Chats',
      'no_chats' => $hvM['no_chats'] ?? '',
      'unread' => $hvM['unread'] ?? '',
      'pick_contact' => $hvM['pick_contact'] ?? '',
      'drawer_open_full' => $hvM['drawer_open_full'] ?? '',
      'drawer_back' => $hvM['drawer_back'] ?? 'Back',
      'type_message' => $hvM['type_message'] ?? '',
      'send' => $hvM['send'] ?? 'Send',
      'attach' => $hvM['attach'] ?? '',
      'image' => $hvM['image'] ?? 'Photo',
      'video' => $hvM['video'] ?? 'Video',
      'audio' => $hvM['audio'] ?? 'Audio',
      'file' => $hvM['file'] ?? 'File',
      'download' => $hvM['download'] ?? 'Download',
      '_err' => $dict['common']['error'] ?? 'Error',
      'loading' => $dict['common']['loading'] ?? 'Loading…',
  ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;
  </script>
  <script src="<?= e(base_url()) ?>/assets/js/hv-messages-drawer.js?v=11" defer></script>
  <?php } ?>
  <script src="<?= e(base_url()) ?>/assets/js/hv-loader.js?v=2" defer></script>
  <script src="<?= e(base_url()) ?>/assets/js/hv-cart.js?v=7" defer></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.24.0/dist/sweetalert2.all.min.js" crossorigin="anonymous" defer></script>
  <script src="<?= e(base_url()) ?>/assets/js/hv-ui.js?v=1" defer></script>
</head>
<body class="min-h-screen min-w-0 flex flex-col<?= !empty($showCatalogLoader) ? ' hv-catalog-load-lock' : '' ?>" data-hv-auth-approved="<?= $hvAuthApproved ? '1' : '0' ?>" data-hv-rol="<?= (int) $hvBodyRol ?>" data-hv-portal-cart="<?= $hvPortalCart ? '1' : '0' ?>" data-hv-seller-cart-hint="<?= e($hvSellerCartHint) ?>">
  <?php require __DIR__ . '/partials/header.php'; ?>
  <main class="flex-1 min-w-0 pb-20 md:pb-0 w-full overflow-x-hidden"><?= $content ?></main>
  <?php require __DIR__ . '/partials/loader.php'; ?>
  <?php require __DIR__ . '/partials/footer.php'; ?>
  <?php require __DIR__ . '/partials/nav-drawer.php'; ?>
  <?php if (!empty($hvShowMsgDrawer)) {
      require __DIR__ . '/partials/messages-drawer.php';
  } ?>
</body>
</html>
