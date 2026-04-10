<?php
$pending = isset($_GET['pending']);
$from = isset($_GET['from']) ? (string) $_GET['from'] : '';
$hvLoginError = '';
if (!empty($_SESSION['hv_login_error'])) {
    $errCode = (string) $_SESSION['hv_login_error'];
    unset($_SESSION['hv_login_error']);
    $hvLoginError = $errCode === 'invalid'
        ? (string) ($dict['auth']['invalid_credentials'] ?? 'Error')
        : $errCode;
}
$hvLoginOldEmail = '';
if (isset($_SESSION['hv_login_old_email'])) {
    $hvLoginOldEmail = (string) $_SESSION['hv_login_old_email'];
    unset($_SESSION['hv_login_old_email']);
}
ob_start();
?>
<style>
  /* Un solo bloque visual con el ojito; evita el fondo amarillo del autofill desalineado */
  .hv-login-field .hv-login-input:-webkit-autofill,
  .hv-login-field .hv-login-input:-webkit-autofill:hover,
  .hv-login-field .hv-login-input:-webkit-autofill:focus {
    -webkit-box-shadow: 0 0 0 1000px #ffffff inset;
    box-shadow: 0 0 0 1000px #ffffff inset;
    -webkit-text-fill-color: #111827;
    caret-color: #111827;
  }
</style>
<div class="max-w-md mx-auto px-4 py-12">
  <h1 class="text-2xl font-bold text-gray-900"><?= e($dict['auth']['login_title']) ?></h1>
  <?php if ($pending) { ?>
  <p class="mt-4 p-4 bg-amber-50 border border-amber-200 rounded-xl text-sm text-amber-900"><?= e($dict['auth']['pending_approval']) ?></p>
  <?php } ?>
  <form id="hv-login" method="post" action="<?= e(base_url()) ?>/<?= e($lang) ?>/login" class="mt-8 space-y-4" autocomplete="on">
    <input type="hidden" name="from" value="<?= e($from) ?>" />
    <div>
      <label class="block text-sm font-medium text-gray-700" for="hv-login-email"><?= e($dict['auth']['email']) ?></label>
      <div class="hv-login-field mt-1 rounded-xl border-2 border-gray-200 bg-white shadow-sm transition focus-within:border-gray-400 focus-within:shadow-md">
        <input id="hv-login-email" name="email" type="email" required value="<?= e($hvLoginOldEmail) ?>" class="hv-login-input w-full rounded-xl border-0 bg-transparent px-4 py-3 text-gray-900 placeholder-gray-400 focus:ring-0 focus:outline-none" autocomplete="email" />
      </div>
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700" for="hv-login-password"><?= e($dict['auth']['password']) ?></label>
      <div class="hv-login-field mt-1 flex min-h-[3rem] items-stretch overflow-hidden rounded-xl border-2 border-gray-200 bg-white shadow-sm transition focus-within:border-gray-400 focus-within:shadow-md">
        <input id="hv-login-password" name="password" type="password" required class="hv-login-input min-w-0 flex-1 border-0 bg-transparent py-3 pl-4 pr-2 text-gray-900 placeholder-gray-400 focus:ring-0 focus:outline-none" autocomplete="current-password" />
        <button type="button" id="hv-login-password-toggle" class="flex shrink-0 items-center justify-center border-0 border-l border-gray-100 bg-transparent px-3 text-gray-500 transition hover:bg-gray-50 hover:text-gray-800 focus:outline-none focus-visible:bg-gray-50 focus-visible:text-gray-900" aria-pressed="false" aria-label="<?= e($dict['auth']['show_password'] ?? 'Show password') ?>">
          <span class="hv-pw-icon-show flex" aria-hidden="true"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg></span>
          <span class="hv-pw-icon-hide hidden flex" aria-hidden="true"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg></span>
        </button>
      </div>
    </div>
    <p id="hv-login-err" class="text-sm text-red-700<?= $hvLoginError === '' ? ' hidden' : '' ?>" role="<?= $hvLoginError === '' ? 'none' : 'alert' ?>"><?= $hvLoginError !== '' ? e($hvLoginError) : '' ?></p>
    <button type="submit" class="w-full py-3 bg-red-700 text-white rounded-xl font-semibold"><?= e($dict['auth']['login_button']) ?></button>
  </form>
  <p class="mt-6 text-center text-sm text-gray-600"><?= e($dict['auth']['no_account']) ?>
    <a href="<?= e(base_url()) ?>/<?= e($lang) ?>/register" class="text-red-800 font-semibold"><?= e($dict['nav']['register']) ?></a>
  </p>
</div>
<script>
(function () {
  var passInput = document.getElementById('hv-login-password');
  var passToggle = document.getElementById('hv-login-password-toggle');
  if (!passToggle || !passInput) return;
  var showPwLabel = <?= json_encode($dict['auth']['show_password'] ?? 'Show password') ?>;
  var hidePwLabel = <?= json_encode($dict['auth']['hide_password'] ?? 'Hide password') ?>;
  var iconShow = passToggle.querySelector('.hv-pw-icon-show');
  var iconHide = passToggle.querySelector('.hv-pw-icon-hide');
  passToggle.addEventListener('click', function () {
    var revealing = passInput.type === 'password';
    passInput.type = revealing ? 'text' : 'password';
    if (iconShow) iconShow.classList.toggle('hidden', revealing);
    if (iconHide) iconHide.classList.toggle('hidden', !revealing);
    passToggle.setAttribute('aria-pressed', revealing ? 'true' : 'false');
    passToggle.setAttribute('aria-label', revealing ? hidePwLabel : showPwLabel);
  });
})();
</script>
<?php
$content = ob_get_clean();
$pageTitle = $dict['auth']['login_title'] . ' — ' . ($dict['seo']['title'] ?? '');
require __DIR__ . '/layout.php';
