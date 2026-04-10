<?php
$session = Auth::getSession();
if ($session !== null && !empty($session['approved'])) {
    header('Location: ' . base_url() . '/' . $lang . '/account/cart', true, 302);
    exit;
}
ob_start();
?>
<div class="max-w-3xl mx-auto px-4 py-8">
  <h1 class="text-2xl font-bold text-gray-900"><?= e($dict['cart']['title']) ?></h1>
  <p class="text-sm text-gray-500 mt-2"><?= e($dict['home']['login_to_see_prices'] ?? '') ?></p>
  <div id="hv-guest-cart" class="mt-8"></div>
  <div class="mt-8 flex gap-4 flex-wrap">
    <a href="<?= e(base_url()) ?>/<?= e($lang) ?>" class="px-6 py-3 border-2 border-gray-300 rounded-xl font-semibold"><?= e($dict['cart']['continue_shopping']) ?></a>
    <a href="<?= e(base_url()) ?>/<?= e($lang) ?>/login" class="px-6 py-3 bg-red-700 text-white rounded-xl font-semibold"><?= e($dict['nav']['login']) ?></a>
  </div>
</div>
<script>
(function(){
  var dict = <?= json_encode($dict, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  var lang = <?= json_encode($lang) ?>;
  var base = window.HV_BASE || '';
  function fmt(n) {
    return new Intl.NumberFormat(lang === 'es' ? 'es-US' : 'en-US', { style: 'currency', currency: 'USD' }).format(n);
  }
  function render() {
    var root = document.getElementById('hv-guest-cart');
    if (!root || !window.HV) return;
    var items = HV.cart.load();
    if (!items.length) {
      root.innerHTML = '<p class="text-gray-500 text-center py-12">'+dict.cart.empty+'</p>';
      return;
    }
    var rows = items.map(function(it) {
      return '<div class="flex gap-4 py-4 border-b border-gray-100 items-center">'+
        '<img src="'+(it.image||'')+'" alt="" class="w-20 h-20 object-contain bg-gray-50 rounded-lg" />'+
        '<div class="flex-1 min-w-0"><p class="font-semibold text-gray-900 truncate">'+(it.name||'')+'</p>'+
        '<p class="text-xs text-gray-500">SKU '+(it.sku||'')+'</p>'+
        '<p class="text-sm mt-1">'+dict.cart.quantity+': <strong>'+it.qty+'</strong></p></div>'+
        '<button type="button" class="hv-rm text-sm text-red-700 underline" data-id="'+it.productId+'">'+dict.cart.remove+'</button></div>';
    }).join('');
    root.innerHTML = '<div class="bg-white rounded-2xl border border-gray-100 p-4">'+rows+'</div>'+
      '<div class="mt-4 flex gap-3"><button type="button" id="hv-clear" class="text-sm text-gray-600 underline">'+dict.cart.clear_all+'</button></div>';
    root.querySelectorAll('.hv-rm').forEach(function(btn) {
      btn.addEventListener('click', function() {
        HV.cart.remove(parseInt(btn.getAttribute('data-id'), 10));
        render();
      });
    });
    var clr = document.getElementById('hv-clear');
    if (clr) clr.addEventListener('click', function() {
      var doClear = function () { HV.cart.clear(); render(); };
      if (window.HV && typeof HV.confirm === 'function') {
        HV.confirm({ text: dict.cart.confirm_clear, icon: 'warning' }).then(function (ok) { if (ok) doClear(); });
      } else if (window.confirm(dict.cart.confirm_clear)) {
        doClear();
      }
    });
  }
  render();
  window.addEventListener('hv-cart-change', render);
})();
</script>
<?php
$content = ob_get_clean();
$pageTitle = $dict['cart']['title'] . ' — ' . ($dict['seo']['title'] ?? '');
require __DIR__ . '/layout.php';
