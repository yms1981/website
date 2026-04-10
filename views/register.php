<?php
ob_start();
?>
<div class="max-w-lg mx-auto px-4 py-12">
  <h1 class="text-2xl font-bold text-gray-900"><?= e($dict['auth']['register_title']) ?></h1>
  <form id="hv-reg" class="mt-8 space-y-4">
    <div>
      <label class="block text-sm font-medium text-gray-700"><?= e($dict['auth']['contact_name']) ?></label>
      <input name="contactName" required class="mt-1 w-full px-4 py-3 border-2 border-gray-200 rounded-xl" />
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700"><?= e($dict['auth']['company_name']) ?></label>
      <input name="companyName" required class="mt-1 w-full px-4 py-3 border-2 border-gray-200 rounded-xl" />
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700"><?= e($dict['auth']['email']) ?></label>
      <input name="email" type="email" required class="mt-1 w-full px-4 py-3 border-2 border-gray-200 rounded-xl" />
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700"><?= e($dict['auth']['password']) ?></label>
      <input name="password" type="password" required minlength="6" class="mt-1 w-full px-4 py-3 border-2 border-gray-200 rounded-xl" />
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700"><?= e($dict['auth']['tax_id']) ?></label>
      <input name="taxId" class="mt-1 w-full px-4 py-3 border-2 border-gray-200 rounded-xl" />
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700"><?= e($dict['auth']['address']) ?></label>
      <textarea name="address" rows="2" class="mt-1 w-full px-4 py-3 border-2 border-gray-200 rounded-xl"></textarea>
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700"><?= e($dict['auth']['phone']) ?></label>
      <input name="phone" class="mt-1 w-full px-4 py-3 border-2 border-gray-200 rounded-xl" />
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700"><?= e($dict['auth']['mobile']) ?></label>
      <input name="mobile" class="mt-1 w-full px-4 py-3 border-2 border-gray-200 rounded-xl" />
    </div>
    <label class="flex items-center gap-2 text-sm">
      <input type="checkbox" name="hasWhatsapp" value="1" />
      <?= e($dict['auth']['has_whatsapp']) ?>
    </label>
    <p id="hv-reg-err" class="text-sm text-red-700 hidden"></p>
    <p id="hv-reg-ok" class="text-sm text-emerald-800 hidden"></p>
    <button type="submit" class="w-full py-3 bg-red-700 text-white rounded-xl font-semibold"><?= e($dict['auth']['register_button']) ?></button>
  </form>
  <p class="mt-6 text-center text-sm text-gray-600"><?= e($dict['auth']['has_account']) ?>
    <a href="<?= e(base_url()) ?>/<?= e($lang) ?>/login" class="text-red-800 font-semibold"><?= e($dict['nav']['login']) ?></a>
  </p>
</div>
<script>
(function(){
  var base = window.HV_BASE || '';
  var dict = <?= json_encode($dict, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  document.getElementById('hv-reg').addEventListener('submit', function(e) {
    e.preventDefault();
    var fd = new FormData(e.target);
    var err = document.getElementById('hv-reg-err');
    var ok = document.getElementById('hv-reg-ok');
    err.classList.add('hidden'); ok.classList.add('hidden');
    var body = {
      contactName: fd.get('contactName'),
      companyName: fd.get('companyName'),
      email: fd.get('email'),
      password: fd.get('password'),
      taxId: fd.get('taxId') || '',
      address: fd.get('address') || '',
      phone: fd.get('phone') || '',
      mobile: fd.get('mobile') || '',
      hasWhatsapp: fd.get('hasWhatsapp') === '1'
    };
    fetch(base + '/api/auth/register', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(body)
    }).then(function(r) { return r.json().then(function(j) { return { ok: r.ok, j: j }; }); })
    .then(function(x) {
      if (!x.ok) {
        err.textContent = (x.j && x.j.error) ? x.j.error : dict.common.error;
        err.classList.remove('hidden');
        return;
      }
      ok.textContent = dict.auth.registration_success;
      ok.classList.remove('hidden');
      e.target.reset();
    }).catch(function() {
      err.textContent = dict.common.error;
      err.classList.remove('hidden');
    });
  });
})();
</script>
<?php
$content = ob_get_clean();
$pageTitle = $dict['auth']['register_title'] . ' — ' . ($dict['seo']['title'] ?? '');
require __DIR__ . '/layout.php';
