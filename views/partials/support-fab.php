<?php
declare(strict_types=1);
/** Asistente flotante solo cliente mayorista (rol 3). */
if (!class_exists('Auth', false)) {
    return;
}
$hvSupSess = Auth::getSession();
if (!is_array($hvSupSess) || empty($hvSupSess['approved']) || (int) ($hvSupSess['rolId'] ?? 0) !== 3) {
    return;
}
$sa = $dict['support_assistant'] ?? [];
$hvSupUserJson = json_encode([
    'name' => trim((string) ($hvSupSess['name'] ?? '')),
    'email' => trim((string) ($hvSupSess['email'] ?? '')),
    'customerId' => (int) ($hvSupSess['customerId'] ?? 0),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$hvSupDictJson = json_encode($sa, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$bpFab = e(base_url());
?>
<style>
#hv-support-fab-btn{position:fixed;bottom:1.25rem;right:1.25rem;z-index:99990;width:3.5rem;height:3.5rem;border-radius:9999px;border:none;cursor:pointer;box-shadow:0 10px 25px -5px rgba(15,23,42,0.25),0 8px 10px -6px rgba(15,23,42,0.15);background:linear-gradient(135deg,#dc2626 0%,#991b1b 100%);color:#fff;display:flex;align-items:center;justify-content:center;transition:transform .15s ease,box-shadow .15s ease;}
#hv-support-fab-btn:hover{transform:scale(1.05);box-shadow:0 14px 28px -6px rgba(15,23,42,0.3);}
#hv-support-fab-btn:focus-visible{outline:2px solid #fecaca;outline-offset:2px;}
#hv-support-panel{position:fixed;bottom:5.5rem;right:1rem;z-index:99991;width:min(22rem,calc(100vw - 2rem));max-height:min(32rem,70vh);background:#fff;border-radius:1rem;box-shadow:0 25px 50px -12px rgba(15,23,42,0.25);border:1px solid #e5e7eb;display:flex;flex-direction:column;overflow:hidden;}
#hv-support-panel.hidden{display:none!important;}
.hv-support-head{display:flex;align-items:center;justify-content:space-between;padding:.75rem 1rem;background:linear-gradient(135deg,#1e293b 0%,#0f172a 100%);color:#fff;}
.hv-support-head h2{margin:0;font-size:0.95rem;font-weight:700;letter-spacing:.02em;}
#hv-support-close{background:transparent;border:none;color:#cbd5e1;cursor:pointer;padding:.35rem;border-radius:.375rem;line-height:1;}
#hv-support-close:hover{color:#fff;background:rgba(255,255,255,.1);}
#hv-support-messages{flex:1;min-height:10rem;max-height:16rem;overflow-y:auto;padding:.75rem;background:#f8fafc;}
#hv-support-ask-row{display:flex;gap:.4rem;padding:.5rem .75rem;border-top:1px solid #e5e7eb;background:#fff;align-items:center;}
#hv-support-ask-input{flex:1;min-width:0;font-size:.8125rem;padding:.45rem .55rem;border:1px solid #d1d5db;border-radius:.5rem;}
#hv-support-ask-send{flex-shrink:0;padding:.45rem .65rem;border:none;border-radius:.5rem;background:#1e293b;color:#fff;font-weight:700;font-size:.75rem;cursor:pointer;}
#hv-support-ask-send:hover{background:#0f172a;}
#hv-support-ask-send:disabled{opacity:.55;cursor:not-allowed;}
.hv-support-msg{margin-bottom:.6rem;display:flex;}
.hv-support-msg--bot{justify-content:flex-start;}
.hv-support-msg--user{justify-content:flex-end;}
.hv-support-msg__bubble{max-width:92%;padding:.55rem .75rem;border-radius:.75rem;font-size:.8125rem;line-height:1.45;}
.hv-support-msg--bot .hv-support-msg__bubble{background:#fff;border:1px solid #e2e8f0;color:#1e293b;border-bottom-left-radius:.25rem;}
.hv-support-msg--user .hv-support-msg__bubble{background:#dbeafe;color:#0c4a6e;border-bottom-right-radius:.25rem;}
.hv-support-msg__bubble strong{font-weight:700;}
#hv-support-chips{display:flex;flex-wrap:wrap;gap:.4rem;padding:.5rem .75rem;border-top:1px solid #e5e7eb;background:#fff;}
#hv-support-chips button{font-size:.7rem;padding:.4rem .55rem;border-radius:9999px;border:1px solid #e5e7eb;background:#f9fafb;color:#374151;cursor:pointer;font-weight:600;}
#hv-support-chips button:hover{background:#f3f4f6;border-color:#d1d5db;}
#hv-support-human-form.hidden{display:none!important;}
#hv-support-human-form{padding:.6rem .75rem .85rem;border-top:1px solid #e5e7eb;background:#fff;}
#hv-support-human-text{width:100%;min-height:4rem;resize:vertical;font-size:.8125rem;padding:.5rem;border:1px solid #d1d5db;border-radius:.5rem;box-sizing:border-box;}
#hv-support-send-team{margin-top:.5rem;width:100%;padding:.55rem .75rem;border:none;border-radius:.5rem;background:#b91c1c;color:#fff;font-weight:700;font-size:.8125rem;cursor:pointer;}
#hv-support-send-team:hover{background:#991b1b;}
#hv-support-send-team:disabled{opacity:.6;cursor:not-allowed;}
@media (min-width:768px){#hv-support-fab-btn{bottom:1.5rem;right:1.5rem;}#hv-support-panel{bottom:6rem;right:1.5rem;}}
</style>
<button type="button" id="hv-support-fab-btn" aria-haspopup="dialog" aria-expanded="false" aria-controls="hv-support-panel" title="<?= e($sa['fab_aria'] ?? '') ?>" aria-label="<?= e($sa['fab_aria'] ?? '') ?>">
  <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-4a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"/></svg>
</button>
<div id="hv-support-panel" class="hidden" role="dialog" aria-modal="false" aria-labelledby="hv-support-title" aria-hidden="true">
  <div class="hv-support-head">
    <h2 id="hv-support-title"><?= e($sa['panel_title'] ?? '') ?></h2>
    <button type="button" id="hv-support-close" aria-label="<?= e($sa['close_aria'] ?? '') ?>">×</button>
  </div>
  <div id="hv-support-messages" role="log" aria-live="polite"></div>
  <div id="hv-support-ask-row">
    <input type="text" id="hv-support-ask-input" placeholder="<?= e($sa['placeholder_ask'] ?? '') ?>" autocomplete="off" aria-label="<?= e($sa['placeholder_ask'] ?? '') ?>" />
    <button type="button" id="hv-support-ask-send"><?= e($sa['btn_ask'] ?? 'Ask') ?></button>
  </div>
  <div id="hv-support-chips" role="group" aria-label="<?= e($sa['fab_aria'] ?? '') ?>">
    <button type="button" data-hv-support-chip="orders"><?= e($sa['chip_orders'] ?? '') ?></button>
    <button type="button" data-hv-support-chip="catalog"><?= e($sa['chip_catalog'] ?? '') ?></button>
    <button type="button" data-hv-support-chip="human"><?= e($sa['chip_human'] ?? '') ?></button>
  </div>
  <div id="hv-support-human-form" class="hidden">
    <textarea id="hv-support-human-text" placeholder="<?= e($sa['placeholder_issue'] ?? '') ?>" aria-label="<?= e($sa['placeholder_issue'] ?? '') ?>"></textarea>
    <button type="button" id="hv-support-send-team"><?= e($sa['btn_send_team'] ?? '') ?></button>
  </div>
</div>
<script>
window.HV_SUPPORT_ASSISTANT = <?= $hvSupDictJson ?>;
window.HV_SUPPORT_USER = <?= $hvSupUserJson ?>;
window.HV_SUPPORT_LANG = <?= json_encode($lang, JSON_THROW_ON_ERROR) ?>;
</script>
<script src="<?= $bpFab ?>/assets/js/hv-support-fab.js?v=3" defer></script>
