<?php
ob_start();
?>
<div class="max-w-lg mx-auto px-4 py-12">
  <h1 class="text-2xl font-bold text-gray-900"><?= e($dict['contact']['title']) ?></h1>
  <form id="hv-contact" class="mt-8 space-y-4">
    <div>
      <label class="block text-sm font-medium text-gray-700"><?= e($dict['contact']['name']) ?></label>
      <input name="name" required class="mt-1 w-full px-4 py-3 border-2 border-gray-200 rounded-xl" />
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700"><?= e($dict['contact']['email']) ?></label>
      <input name="email" type="email" required class="mt-1 w-full px-4 py-3 border-2 border-gray-200 rounded-xl" />
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700"><?= e($dict['contact']['subject']) ?></label>
      <input name="subject" required class="mt-1 w-full px-4 py-3 border-2 border-gray-200 rounded-xl" />
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700"><?= e($dict['contact']['message']) ?></label>
      <textarea name="message" rows="5" required class="mt-1 w-full px-4 py-3 border-2 border-gray-200 rounded-xl"></textarea>
    </div>
    <p id="hv-c-err" class="text-sm text-red-700 hidden"></p>
    <p id="hv-c-ok" class="text-sm text-emerald-800 hidden"></p>
    <button type="submit" class="w-full py-3 bg-red-700 text-white rounded-xl font-semibold"><?= e($dict['contact']['send']) ?></button>
  </form>
</div>
<script>
(function(){
  var base = window.HV_BASE || '';
  var dict = <?= json_encode($dict, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  document.getElementById('hv-contact').addEventListener('submit', function(e) {
    e.preventDefault();
    var fd = new FormData(e.target);
    var err = document.getElementById('hv-c-err');
    var ok = document.getElementById('hv-c-ok');
    err.classList.add('hidden'); ok.classList.add('hidden');
    fetch(base + '/api/contact', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        name: fd.get('name'),
        email: fd.get('email'),
        subject: fd.get('subject'),
        message: fd.get('message')
      })
    }).then(function(r) { return r.json().then(function(j) { return { ok: r.ok, j: j }; }); })
    .then(function(x) {
      if (!x.ok) {
        err.textContent = (x.j && x.j.error) ? x.j.error : dict.common.error;
        err.classList.remove('hidden');
        return;
      }
      ok.textContent = dict.contact.success;
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
$pageTitle = $dict['contact']['title'] . ' — ' . ($dict['seo']['title'] ?? '');
require __DIR__ . '/layout.php';
