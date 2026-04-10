<?php
$dict = $dict ?? load_dictionary($lang ?? 'en');
$lang = $lang ?? 'en';
ob_start();
?>
<div class="max-w-lg mx-auto px-4 py-24 text-center">
  <h1 class="text-xl font-semibold text-gray-900"><?= $lang === 'es' ? 'Catálogo no disponible' : 'Catalog unavailable' ?></h1>
  <p class="text-gray-600 mt-4"><?= $lang === 'es'
    ? 'Falta configurar la API de FullVendor en el archivo .env del servidor (FULLVENDOR_BASE_URL, FULLVENDOR_TOKEN, FULLVENDOR_COMPANY_ID).'
    : 'The FullVendor API must be configured in the server .env file (FULLVENDOR_BASE_URL, FULLVENDOR_TOKEN, FULLVENDOR_COMPANY_ID).' ?></p>
  <a href="<?= e(base_url()) ?>/<?= e($lang) ?>" class="inline-block mt-8 px-6 py-3 bg-red-700 text-white rounded-xl font-semibold"><?= e($dict['common']['return_home'] ?? 'Home') ?></a>
</div>
<?php
$content = ob_get_clean();
$pageTitle = ($lang === 'es' ? 'No disponible' : 'Unavailable') . ' — ' . ($dict['seo']['title'] ?? '');
require __DIR__ . '/../layout.php';
