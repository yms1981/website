<?php

declare(strict_types=1);

$session = require_account_session($lang, true);
$rol = (int) ($session['rolId'] ?? 0);
if ($rol !== 2 && $rol !== 3) {
    header('Location: ' . base_url() . '/' . $lang . '/account/catalog', true, 302);
    exit;
}
if (!class_exists('Db', false) || !Db::enabled()) {
    http_response_code(503);
    $pageTitle = ($dict['messaging']['title'] ?? 'Messages') . ' — ' . ($dict['seo']['title'] ?? '');
    ob_start();
    ?>
<div class="max-w-xl mx-auto px-4 py-16 text-center">
  <p class="text-gray-600"><?= e($dict['common']['error'] ?? 'Error') ?></p>
  <a href="<?= e(base_url()) ?>/<?= e($lang) ?>/account/catalog" class="inline-block mt-6 text-red-800 font-semibold"><?= e($dict['common']['back'] ?? 'Back') ?></a>
</div>
<?php
    $content = ob_get_clean();
    require __DIR__ . '/../layout.php';
    exit;
}

$msg = $dict['messaging'] ?? [];
ob_start();
$bp = base_url();
?>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8">
  <div class="mb-4 flex flex-col sm:flex-row sm:items-end sm:justify-between gap-2">
    <div>
      <h1 class="text-2xl font-bold text-gray-900 tracking-tight"><?= e($msg['title'] ?? 'Messages') ?></h1>
      <p class="text-sm text-gray-500 mt-1"><?= e($msg['subtitle'] ?? '') ?></p>
    </div>
  </div>

  <div id="hv-msg-shell" class="hv-msg-shell hv-msg-sidebar-open rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
    <div id="hv-msg-sidebar-backdrop" class="hv-msg-sidebar-backdrop" aria-hidden="true"></div>

    <aside id="hv-msg-sidebar" class="hv-msg-sidebar" role="complementary" aria-hidden="false" aria-label="<?= e($msg['chats'] ?? 'Chats') ?>">
      <div class="hv-msg-sidebar-header px-4 py-3 flex items-center justify-between gap-2 bg-sky-600 text-white">
        <div class="flex items-center gap-2 min-w-0">
          <span class="font-semibold text-base tracking-tight truncate"><?= e($msg['chats'] ?? 'Chats') ?></span>
          <span id="hv-msg-list-unread" class="hidden min-w-[1.25rem] h-5 px-1.5 rounded-full bg-white/25 text-white text-[11px] font-bold items-center justify-center"></span>
        </div>
        <button type="button" id="hv-msg-sidebar-close" class="p-2 rounded-lg text-white hover:bg-white/15 text-2xl leading-none font-light touch-manipulation shrink-0" aria-label="<?= e($msg['hide_panel'] ?? 'Close') ?>">×</button>
      </div>
      <div class="px-3 py-2 border-b border-gray-100 shrink-0 bg-white">
        <button type="button" id="hv-msg-new" class="w-full text-sm font-semibold text-sky-700 bg-sky-50 hover:bg-sky-100 border border-sky-200 px-3 py-2.5 rounded-xl touch-manipulation">
          <?= e($msg['new_chat'] ?? 'New chat') ?>
        </button>
      </div>
      <div id="hv-msg-conv-list" class="hv-msg-conv-list-inner bg-white"></div>
    </aside>

    <section class="hv-msg-panel-thread bg-[#e8e3dc] md:bg-[#ece7df]">
      <header class="shrink-0 flex items-center gap-2 px-3 py-3 border-b border-gray-200/80 bg-white/95 backdrop-blur-sm">
        <button type="button" id="hv-msg-toggle-sidebar" class="inline-flex items-center gap-2 shrink-0 px-3 py-2 rounded-xl bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 touch-manipulation shadow-sm" aria-expanded="true" aria-controls="hv-msg-sidebar">
          <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
          <span id="hv-msg-toggle-label"><?= e($msg['show_chats'] ?? 'Chats') ?></span>
          <span id="hv-msg-toggle-unread" class="hidden min-w-[1.25rem] h-5 px-1.5 rounded-full bg-white/25 text-white text-[11px] font-bold items-center justify-center"></span>
        </button>
        <div class="min-w-0 flex-1">
          <h2 id="hv-msg-thread-title" class="text-sm font-semibold text-gray-900 truncate"><?= e($msg['thread_idle'] ?? '') ?></h2>
          <p id="hv-msg-thread-sub" class="text-xs text-gray-500 truncate"></p>
        </div>
      </header>
      <div id="hv-msg-thread-scroll" class="flex-1 overflow-y-auto min-h-0 p-3 space-y-1">
        <div id="hv-msg-thread-placeholder" class="rounded-2xl border border-dashed border-gray-300/80 bg-white/60 px-4 py-10 text-center text-sm text-gray-600 max-w-md mx-auto">
          <?= e($msg['select_chat_hint'] ?? '') ?>
        </div>
        <div id="hv-msg-thread" class="min-h-[80px]"></div>
      </div>
      <div class="shrink-0 p-2 sm:p-3 border-t border-gray-200 bg-white flex flex-wrap items-end gap-2">
        <input type="file" id="hv-msg-file" class="hidden" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.zip" />
        <button type="button" id="hv-msg-attach" class="p-2.5 rounded-xl border border-gray-200 text-gray-600 hover:bg-gray-50 touch-manipulation shrink-0" title="<?= e($msg['attach'] ?? '') ?>" aria-label="<?= e($msg['attach'] ?? '') ?>">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
        </button>
        <button type="button" id="hv-msg-record" class="p-2.5 rounded-xl border border-gray-200 text-gray-600 hover:bg-gray-50 touch-manipulation shrink-0 select-none" title="<?= e($msg['record_audio'] ?? '') ?>" aria-label="<?= e($msg['record_audio'] ?? '') ?>">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"/></svg>
        </button>
        <textarea id="hv-msg-input" rows="1" class="flex-1 min-w-[8rem] max-h-32 rounded-xl border border-gray-200 px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 resize-y" placeholder="<?= e($msg['type_message'] ?? '') ?>"></textarea>
        <button type="button" id="hv-msg-send" class="px-4 py-2.5 rounded-xl bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 touch-manipulation shrink-0">
          <?= e($msg['send'] ?? 'Send') ?>
        </button>
      </div>
    </section>
  </div>
</div>

<div id="hv-msg-contacts-panel" class="fixed inset-0 z-[60] bg-black/50 p-4 overflow-y-auto hidden" aria-hidden="true">
  <div class="bg-white rounded-2xl shadow-xl max-w-md w-full mx-auto mt-8 sm:mt-16 max-h-[80vh] flex flex-col overflow-hidden border border-gray-100">
    <div class="p-4 border-b border-gray-100 flex items-center justify-between gap-2 shrink-0">
      <span class="font-semibold text-gray-900"><?= e($msg['pick_contact'] ?? '') ?></span>
      <button type="button" id="hv-msg-contacts-close" class="p-2 rounded-lg text-gray-500 hover:bg-gray-100 text-xl leading-none" aria-label="<?= e($dict['common']['cancel'] ?? 'Close') ?>">×</button>
    </div>
    <div id="hv-msg-contacts" class="overflow-y-auto flex-1 min-h-0"></div>
  </div>
</div>

<script>
window.HV_MSG_LANG = <?= json_encode($lang) ?>;
window.HV_MSG_DICT = <?= json_encode([
    'no_chats' => $msg['no_chats'] ?? '',
    'pick_contact' => $msg['pick_contact'] ?? '',
    'type_message' => $msg['type_message'] ?? '',
    'send' => $msg['send'] ?? '',
    'attach' => $msg['attach'] ?? '',
    'record_audio' => $msg['record_audio'] ?? '',
    'image' => $msg['image'] ?? '',
    'video' => $msg['video'] ?? '',
    'audio' => $msg['audio'] ?? '',
    'file' => $msg['file'] ?? '',
    'download' => $msg['download'] ?? '',
    'unread' => $msg['unread'] ?? '',
    'thread_idle' => $msg['thread_idle'] ?? '',
    'show_chats' => $msg['show_chats'] ?? 'Chats',
    'hide_panel' => $msg['hide_panel'] ?? 'Close',
    '_err' => $dict['common']['error'] ?? 'Error',
    '_mic' => $lang === 'es' ? 'Micrófono no disponible o denegado' : 'Microphone unavailable or denied',
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= e($bp) ?>/assets/js/hv-messages.js?v=8" defer></script>
<?php
$content = ob_get_clean();
$pageTitle = ($msg['title'] ?? 'Messages') . ' — ' . ($dict['seo']['title'] ?? '');
require __DIR__ . '/../layout.php';
