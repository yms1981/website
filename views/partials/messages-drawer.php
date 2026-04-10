<?php

declare(strict_types=1);

$m = $dict['messaging'] ?? [];
$bp = base_url();
?>
<div id="hv-global-msg-root" class="hv-global-msg-root" aria-hidden="true">
  <div id="hv-global-msg-backdrop" class="hv-global-msg-backdrop" aria-hidden="true"></div>
  <aside id="hv-global-msg-panel" class="hv-global-msg-panel" role="dialog" aria-modal="true" aria-labelledby="hv-global-msg-title" aria-hidden="true">
    <div class="hv-global-msg-header-bar">
      <button type="button" id="hv-global-msg-back" class="hv-global-msg-header-btn hv-global-msg-back-btn hidden" aria-label="<?= e($m['drawer_back'] ?? 'Back') ?>" title="<?= e($m['drawer_back'] ?? 'Back') ?>">‹</button>
      <div id="hv-global-msg-header-list" class="hv-global-msg-header-main">
        <span id="hv-global-msg-title" class="hv-global-msg-header-title"><?= e($m['chats'] ?? 'Chats') ?></span>
        <span id="hv-global-msg-total-unread" class="hv-global-msg-header-badge hidden"></span>
      </div>
      <div id="hv-global-msg-header-thread" class="hv-global-msg-header-thread hidden" aria-hidden="true">
        <span id="hv-global-msg-thread-avatar" class="hv-msg-drawer-avatar hv-msg-drawer-avatar--compact shrink-0" aria-hidden="true"></span>
        <div class="hv-global-msg-header-thread-text">
          <div id="hv-global-msg-thread-peer-name" class="hv-global-msg-thread-peer-name"></div>
          <div id="hv-global-msg-thread-peer-sub" class="hv-global-msg-thread-peer-sub"></div>
        </div>
      </div>
      <button type="button" id="hv-global-msg-close" class="hv-global-msg-header-btn" aria-label="<?= e($m['hide_panel'] ?? 'Close') ?>">×</button>
    </div>
    <div id="hv-global-msg-new-row" class="px-3 py-2 border-b border-gray-100 shrink-0 bg-white">
      <button type="button" id="hv-global-msg-new" class="w-full text-sm font-semibold text-emerald-800 bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 px-3 py-2.5 rounded-xl touch-manipulation">
        <?= e($m['new_chat'] ?? 'New chat') ?>
      </button>
    </div>
    <div id="hv-global-msg-stack" class="hv-global-msg-stack">
      <div id="hv-global-msg-list" class="hv-global-msg-list"></div>
      <div id="hv-global-msg-contacts-list" class="hv-global-msg-list hv-global-msg-contacts-list hidden" aria-hidden="true"></div>
      <div id="hv-global-msg-thread-wrap" class="hv-global-msg-thread-wrap" aria-hidden="true">
        <div id="hv-global-msg-thread-scroll" class="hv-global-msg-thread-scroll">
          <div id="hv-global-msg-thread-msgs"></div>
        </div>
        <div class="hv-global-msg-composer">
          <input type="file" id="hv-global-msg-file" class="hidden" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.zip" />
          <button type="button" id="hv-global-msg-attach" class="hv-global-msg-icon-btn" title="<?= e($m['attach'] ?? '') ?>" aria-label="<?= e($m['attach'] ?? '') ?>">
            <svg class="hv-global-msg-icon-svg" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
          </button>
          <textarea id="hv-global-msg-input" class="hv-global-msg-input" rows="1" placeholder="<?= e($m['type_message'] ?? '') ?>"></textarea>
          <button type="button" id="hv-global-msg-send" class="hv-global-msg-send-btn"><?= e($m['send'] ?? 'Send') ?></button>
        </div>
      </div>
    </div>
    <div id="hv-global-msg-footer-row" class="shrink-0 p-2 border-t border-gray-100 bg-gray-50">
      <a id="hv-global-msg-full-link" href="<?= e($bp) ?>/<?= e($lang) ?>/account/messages" class="block text-center text-sm font-semibold text-sky-700 hover:text-sky-900 py-2 rounded-lg hover:bg-white">
        <?= e($m['drawer_open_full'] ?? 'Open messages page') ?>
      </a>
    </div>
  </aside>
</div>
