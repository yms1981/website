<?php
ob_start();
?>
<div class="max-w-lg mx-auto px-4 py-24 text-center">
  <p class="text-6xl font-bold text-gray-200">404</p>
  <h1 class="text-2xl font-semibold text-gray-900 mt-4"><?= e($dict['common']['page_not_found'] ?? 'Not found') ?></h1>
  <a href="<?= e(base_url()) ?>/<?= e($lang) ?>" class="inline-block mt-8 px-6 py-3 bg-red-700 text-white rounded-xl font-semibold"><?= e($dict['common']['return_home'] ?? 'Home') ?></a>
</div>
<?php
$content = ob_get_clean();
$pageTitle = ($dict['common']['page_not_found'] ?? '404') . ' — ' . ($dict['seo']['title'] ?? '');
require __DIR__ . '/../layout.php';
