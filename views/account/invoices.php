<?php
require_account_session($lang, true);
ob_start();
?>
<div class="max-w-4xl mx-auto px-4 py-8">
  <h1 class="text-2xl font-bold text-gray-900"><?= e($dict['invoices']['title']) ?></h1>
  <p class="text-gray-500 mt-4"><?= e($dict['invoices']['no_invoices']) ?></p>
</div>
<?php
$content = ob_get_clean();
$pageTitle = $dict['invoices']['title'] . ' — ' . ($dict['seo']['title'] ?? '');
require __DIR__ . '/../layout.php';
