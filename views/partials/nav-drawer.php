<?php
declare(strict_types=1);
/** Menú móvil: al final del body para que position:fixed cubra bien el viewport (no como hijo flex temprano). */
$ndBp = base_url();
$ndIsEn = $lang === 'en';
$ndPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$ndPath = preg_replace('#^' . preg_quote(base_path() ?: '', '#') . '#', '', $ndPath) ?: '/';
$ndPathTail = preg_replace('#^/(en|es)(?=($|/))#', '', $ndPath);
$ndEnUrl = $ndBp . '/en' . ($ndPathTail === '' ? '' : $ndPathTail);
$ndEsUrl = $ndBp . '/es' . ($ndPathTail === '' ? '' : $ndPathTail);
$ndMenuAria = $lang === 'es' ? 'Abrir menú' : 'Open menu';
?>
<div id="hv-nav-drawer" class="hv-nav-drawer hidden" aria-hidden="true">
  <div class="hv-nav-drawer-back" id="hv-nav-drawer-back" tabindex="-1"></div>
  <div class="hv-nav-drawer-panel" id="hv-nav-drawer-panel" role="dialog" aria-modal="true" aria-label="<?= e($ndMenuAria) ?>">
    <div class="flex items-center justify-between p-4 border-b border-gray-100 flex-shrink-0 min-w-0">
      <span class="font-semibold text-gray-900 truncate pr-2"><?= e($dict['nav']['brand'] ?? 'Home Value') ?></span>
      <button type="button" id="hv-nav-drawer-close" class="p-2 rounded-lg text-gray-500 hover:bg-gray-100 touch-manipulation flex-shrink-0" aria-label="<?= $lang === 'es' ? 'Cerrar menú' : 'Close menu' ?>">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <nav class="flex flex-col p-2 flex-1 min-h-0 overflow-y-auto" aria-label="<?= $lang === 'es' ? 'Navegación' : 'Navigation' ?>">
      <a href="<?= e($ndBp) ?>/<?= e($lang) ?>/account/catalog" class="flex items-center gap-3 py-3 px-3 rounded-xl text-base font-medium text-gray-800 hover:bg-gray-50 min-w-0">
        <svg class="w-5 h-5 text-gray-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
        <?= e($dict['nav']['catalog']) ?>
      </a>
      <div id="hv-nav-mobile-extra" class="hidden flex-col gap-1 border-t border-gray-100 mt-2 pt-2"></div>
    </nav>
    <div class="p-4 border-t border-gray-100 bg-gray-50 flex-shrink-0 space-y-4 min-w-0">
      <a id="nav-cart-drawer" class="hv-nav-cart-link flex items-center justify-center gap-2 w-full py-3 rounded-xl font-semibold bg-white border-2 border-gray-200 text-gray-800 hover:border-gray-300 min-w-0" href="<?= e($ndBp) ?>/<?= e($lang) ?>/cart">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
        <span class="truncate"><?= e($dict['nav']['cart']) ?></span>
        <span data-hv-cart-count class="bg-red-700 text-white text-xs min-w-[20px] h-5 flex items-center justify-center rounded-full px-1 flex-shrink-0" style="display:none">0</span>
      </a>
      <div class="flex items-center justify-center gap-2 min-w-0">
        <a href="<?= e($ndEnUrl) ?>" class="flex-1 min-w-0 flex items-center justify-center gap-2 py-2.5 rounded-xl text-sm font-semibold transition <?= $ndIsEn ? 'bg-gray-900 text-white' : 'bg-white border border-gray-200 text-gray-700' ?>">EN</a>
        <a href="<?= e($ndEsUrl) ?>" class="flex-1 min-w-0 flex items-center justify-center gap-2 py-2.5 rounded-xl text-sm font-semibold transition <?= !$ndIsEn ? 'bg-gray-900 text-white' : 'bg-white border border-gray-200 text-gray-700' ?>">ES</a>
      </div>
      <div id="nav-login-btns-mobile" class="hv-nav-login-btns flex flex-col gap-2 w-full">
        <a href="<?= e($ndBp) ?>/<?= e($lang) ?>/login" class="w-full text-center py-3 rounded-xl text-sm font-semibold border-2 border-gray-200 text-gray-800 hover:bg-white"><?= e($dict['nav']['login']) ?></a>
        <a href="<?= e($ndBp) ?>/<?= e($lang) ?>/register" class="w-full text-center py-3 rounded-xl text-sm font-semibold bg-red-700 text-white hover:bg-red-800"><?= e($dict['nav']['register']) ?></a>
      </div>
      <div id="nav-user-mobile" class="hidden flex flex-col gap-2 w-full"></div>
    </div>
  </div>
</div>
