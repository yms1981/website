<?php
/** Admin HTML already gated in index */
ob_start();
?>
<div class="max-w-5xl mx-auto px-4 py-8">
  <h1 class="text-2xl font-bold text-gray-900"><?= e($dict['admin']['title']) ?></h1>
  <div id="hv-admin" class="mt-6 text-sm text-gray-500"><?= e($dict['common']['loading']) ?></div>
</div>
<script>
(function(){
  var base = window.HV_BASE || '';
  var dict = <?= json_encode($dict, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  var root = document.getElementById('hv-admin');
  function load() {
    fetch(base + '/api/admin/registrations', { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        var list = data.registrations || [];
        if (!list.length) {
          root.innerHTML = '<p class="text-gray-500">'+dict.admin.no_registrations+'</p>';
          return;
        }
        var rows = list.map(function(r) {
          var id = r.id;
          var st = r.status || '';
          var actions = '';
          if (st === 'pending') {
            actions = '<button type="button" class="hv-ap mr-2 px-3 py-1 bg-emerald-600 text-white rounded-lg text-sm" data-id="'+id+'" data-act="approve">'+dict.admin.approve+'</button>'+
              '<button type="button" class="hv-rj px-3 py-1 bg-gray-200 rounded-lg text-sm" data-id="'+id+'" data-act="reject">'+dict.admin.reject+'</button>';
          } else {
            actions = st === 'approved' ? dict.admin.approved : dict.admin.rejected;
          }
          return '<div class="border border-gray-200 rounded-xl p-4 mb-3">'+
            '<p class="font-semibold">'+(r.company_name||'')+' — '+(r.contact_name||'')+'</p>'+
            '<p class="text-sm text-gray-600">'+(r.email||'')+'</p>'+
            '<p class="text-xs text-gray-400 mt-1">'+dict.admin.tax_id+': '+(r.tax_id||'')+' · '+dict.admin.address+': '+(r.address||'')+'</p>'+
            '<div class="mt-3 flex items-center gap-2">'+actions+'</div></div>';
        }).join('');
        root.innerHTML = rows;
        root.querySelectorAll('.hv-ap, .hv-rj').forEach(function(btn) {
          btn.addEventListener('click', function() {
            var id = parseInt(btn.getAttribute('data-id'), 10);
            var act = btn.getAttribute('data-act');
            fetch(base + '/api/admin/registrations', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              credentials: 'same-origin',
              body: JSON.stringify({ registrationId: id, action: act })
            }).then(function(r) {
              if (r.ok) load();
              else if (window.HV && typeof HV.alert === 'function') {
                HV.alert(dict.common.error, { icon: 'error' });
              } else {
                window.alert(dict.common.error);
              }
            });
          });
        });
      })
      .catch(function() { root.textContent = dict.common.error; });
  }
  load();
})();
</script>
<?php
$content = ob_get_clean();
$pageTitle = $dict['admin']['title'] . ' — ' . ($dict['seo']['title'] ?? '');
require __DIR__ . '/layout.php';
