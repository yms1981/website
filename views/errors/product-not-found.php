<?php
ob_start();
?>
<div class="max-w-lg mx-auto px-4 py-24 text-center">
  <h1 class="text-2xl font-semibold text-gray-900"><?= e($dict['product']['product_not_found']) ?></h1>
  <a href="<?= e(base_url()) ?>/<?= e($lang) ?>" class="inline-block mt-8 px-6 py-3 border-2 border-gray-300 rounded-xl font-semibold"><?= e($dict['home']['continue_shopping']) ?></a>
</div>
<?php
$content = ob_get_clean();
$pageTitle = $dict['product']['product_not_found'] . ' — ' . ($dict['seo']['title'] ?? '');
require __DIR__ . '/../layout.php';
