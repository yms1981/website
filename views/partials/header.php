<?php
$bp = base_url();
$isEn = $lang === 'en';
$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$path = preg_replace('#^' . preg_quote(base_path() ?: '', '#') . '#', '', $path) ?: '/';
$pathTail = preg_replace('#^/(en|es)(?=($|/))#', '', $path);
$enUrl = $bp . '/en' . ($pathTail === '' ? '' : $pathTail);
$esUrl = $bp . '/es' . ($pathTail === '' ? '' : $pathTail);
$logoFs = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'logo.png';
$hasLogo = is_file($logoFs);
$menuAria = $lang === 'es' ? 'Abrir menú' : 'Open menu';
$hvShowMsgNav = false;
if (class_exists('Auth', false)) {
    $hvSNav = Auth::getSession();
    if (is_array($hvSNav) && !empty($hvSNav['approved'])) {
        $hvRNav = (int) ($hvSNav['rolId'] ?? 0);
        $hvShowMsgNav = ($hvRNav === 2 || $hvRNav === 3);
    }
}
?>
<div class="hidden md:block bg-gray-950 text-gray-400 text-xs py-1.5">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex items-center justify-between">
    <span class="font-medium text-gray-300">Home Value LLC</span>
    <div class="flex items-center gap-4">
      <span>📞 773.681.2440</span>
      <span>📍 525 W University Dr, Arlington Heights, IL 60004</span>
    </div>
  </div>
</div>
<header class="sticky top-0 z-50 bg-white/95 backdrop-blur-md border-b border-gray-100/80 shadow-sm">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="flex items-center justify-between h-16 sm:h-20 gap-2 min-w-0">
      <a href="<?= e($bp) ?>/<?= e($lang) ?>" class="flex-shrink-0 flex items-center min-w-0">
        <?php if ($hasLogo) { ?>
        <img src="<?= e($bp) ?>/assets/logo.png" alt="Home Value" class="h-9 sm:h-12 w-auto max-w-[148px] sm:max-w-[200px]" width="160" height="48" />
        <?php } else { ?>
        <span class="text-lg sm:text-xl font-bold text-red-800 tracking-tight truncate"><?= e($dict['nav']['brand'] ?? 'Home Value') ?></span>
        <?php } ?>
      </a>

      <div class="flex items-center gap-1 flex-shrink-0 md:hidden">
        <?php if ($hvShowMsgNav) { ?>
        <a id="hv-nav-messages-mobile" href="<?= e($bp) ?>/<?= e($lang) ?>/account/messages" class="hv-messages-nav-trigger relative inline-flex items-center justify-center p-2.5 rounded-xl text-gray-700 hover:bg-gray-100 touch-manipulation" aria-label="<?= e($dict['nav']['messages'] ?? 'Messages') ?>">
          <svg class="w-6 h-6 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
          <span data-hv-msg-badge class="absolute -top-0.5 -right-0.5 bg-emerald-600 text-white text-[10px] min-w-[18px] h-[18px] flex items-center justify-center rounded-full px-0.5 font-bold" style="display:none">0</span>
        </a>
        <?php } ?>
        <a id="nav-cart-mobile" class="hv-nav-cart-link relative inline-flex items-center justify-center p-2.5 rounded-xl text-gray-700 hover:bg-gray-100 touch-manipulation" href="<?= e($bp) ?>/<?= e($lang) ?>/cart">
          <svg class="w-6 h-6 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
          <span data-hv-cart-count class="absolute -top-0.5 -right-0.5 bg-red-700 text-white text-xs min-w-[20px] h-5 flex items-center justify-center rounded-full px-0.5 font-semibold" style="display:none">0</span>
        </a>
        <button type="button" id="hv-nav-menu-btn" class="p-2.5 rounded-xl text-gray-800 hover:bg-gray-100 border border-gray-200 touch-manipulation" aria-controls="hv-nav-drawer" aria-expanded="false" aria-label="<?= e($menuAria) ?>">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
      </div>

      <nav class="hidden md:flex items-center gap-6 min-w-0 flex-shrink-0">
        <a href="<?= e($bp) ?>/<?= e($lang) ?>/account/catalog" class="text-sm font-medium tracking-wide transition text-gray-500 hover:text-gray-900">
          <span class="flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
            <?= e($dict['nav']['catalog']) ?>
          </span>
        </a>
        <a href="<?= e($bp) ?>/<?= e($lang) ?>/contact" class="text-sm font-medium tracking-wide transition text-gray-500 hover:text-gray-900">
          <span class="flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            <?= e($dict['nav']['contact']) ?>
          </span>
        </a>
        <span id="nav-auth-extra" class="hidden md:contents"></span>
      </nav>
      <div class="hidden md:flex items-center gap-3 flex-shrink-0">
        <?php if ($hvShowMsgNav) { ?>
        <a id="hv-nav-messages-desktop" href="<?= e($bp) ?>/<?= e($lang) ?>/account/messages" class="hv-messages-nav-trigger relative flex items-center justify-center p-2 rounded-lg text-gray-600 hover:text-emerald-800 hover:bg-gray-50 transition" aria-label="<?= e($dict['nav']['messages'] ?? 'Messages') ?>" title="<?= e($dict['nav']['messages'] ?? 'Messages') ?>">
          <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
          <span data-hv-msg-badge class="absolute -top-0.5 -right-0.5 bg-emerald-600 text-white text-[10px] min-w-[18px] h-[18px] flex items-center justify-center rounded-full px-0.5 font-bold" style="display:none">0</span>
        </a>
        <?php } ?>
        <a id="nav-cart" class="hv-nav-cart-link flex items-center gap-2 text-gray-600 hover:text-gray-900 transition px-3 py-2 rounded-lg hover:bg-gray-50" href="<?= e($bp) ?>/<?= e($lang) ?>/cart">
          <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
          <span class="text-sm font-medium"><?= e($dict['nav']['cart']) ?></span>
          <span data-hv-cart-count class="bg-red-700 text-white text-xs min-w-[20px] h-5 flex items-center justify-center rounded-full px-1" style="display:none">0</span>
        </a>
        <div class="flex items-center gap-1">
          <a href="<?= e($enUrl) ?>" class="flex items-center gap-1 px-3 py-1.5 rounded-md text-sm font-medium transition <?= $isEn ? 'bg-gray-100 text-gray-900' : 'text-gray-500 hover:text-gray-900 hover:bg-gray-50' ?>" aria-label="English">
            <svg class="w-5 h-3.5 rounded-sm flex-shrink-0" viewBox="0 0 20 14" fill="none" aria-hidden="true"><rect width="20" height="14" fill="#B22234"/><rect y="2" width="20" height="2" fill="white"/><rect y="6" width="20" height="2" fill="white"/><rect y="10" width="20" height="2" fill="white"/><rect width="8" height="8" fill="#3C3B6E"/></svg>
            <span>EN</span>
          </a>
          <a href="<?= e($esUrl) ?>" class="flex items-center gap-1 px-3 py-1.5 rounded-md text-sm font-medium transition <?= !$isEn ? 'bg-gray-100 text-gray-900' : 'text-gray-500 hover:text-gray-900 hover:bg-gray-50' ?>" aria-label="Español">
            <svg class="w-5 h-3.5 rounded-sm flex-shrink-0" viewBox="0 0 20 14" fill="none" aria-hidden="true"><rect width="20" height="14" fill="#AA151B"/><rect y="3.5" width="20" height="7" fill="#F1BF00"/></svg>
            <span>ES</span>
          </a>
        </div>
        <span id="nav-login-btns" class="hv-nav-login-btns flex items-center gap-2">
          <a href="<?= e($bp) ?>/<?= e($lang) ?>/login" class="px-5 py-2.5 rounded-xl text-sm font-semibold border-2 border-gray-200 text-gray-700 hover:border-gray-400 hover:bg-gray-50 transition"><?= e($dict['nav']['login']) ?></a>
          <a href="<?= e($bp) ?>/<?= e($lang) ?>/register" class="px-5 py-2.5 rounded-xl text-sm font-semibold bg-red-700 text-white hover:bg-red-800 transition"><?= e($dict['nav']['register']) ?></a>
        </span>
        <span id="nav-user" class="hv-nav-user-desktop hidden md:flex items-center gap-2"></span>
      </div>
    </div>
  </div>
</header>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var base = window.HV_BASE || '';
  var lang = <?= json_encode($lang) ?>;
  /** Misma pestaña y cookies: evita fetch a otro host que el de la barra (localhost vs 127.0.0.1). */
  var basePath = (typeof window.HV_BASE_PATH === 'string' ? window.HV_BASE_PATH : '').replace(/\/$/, '');
  var apiLogout = (basePath ? basePath : '') + '/api/auth/logout';
  if (apiLogout.charAt(0) !== '/') {
    apiLogout = '/' + apiLogout;
  }
  function hvNavHomeAfterLogout() {
    return window.location.origin + (basePath || '') + '/' + lang + '?_=' + Date.now();
  }
  try {
    var usp = new URLSearchParams(window.location.search || '');
    if (usp.get('hv_clear_cart') === '1') {
      if (window.HV && HV.cart && typeof HV.cart.clearClientStorageOnLogout === 'function') {
        HV.cart.clearClientStorageOnLogout();
      }
      usp.delete('hv_clear_cart');
      var nq = usp.toString();
      var clean = window.location.pathname + (nq ? '?' + nq : '') + (window.location.hash || '');
      if (window.history && history.replaceState) {
        history.replaceState(null, '', clean);
      }
    }
  } catch (eUrl) {}
  try {
    if (sessionStorage.getItem('hv_post_logout') === '1') {
      sessionStorage.removeItem('hv_post_logout');
      if (window.HV && HV.cart && typeof HV.cart.clearClientStorageOnLogout === 'function') {
        HV.cart.clearClientStorageOnLogout();
      }
    }
  } catch (e0) {}
  function hvNavClearLoggedInCookie() {
    var secure = window.location.protocol === 'https:';
    document.cookie = 'hv-logged-in=; Max-Age=0; path=/; SameSite=Lax' + (secure ? '; Secure' : '');
  }
  function hvNavAfterLogoutGo() {
    try {
      if (window.HV && HV.cart && typeof HV.cart.clearClientStorageOnLogout === 'function') {
        HV.cart.clearClientStorageOnLogout();
      }
    } catch (e) {}
    try {
      sessionStorage.setItem('hv_post_logout', '1');
    } catch (e1) {}
    hvNavClearLoggedInCookie();
    window.location.replace(hvNavHomeAfterLogout());
  }
  function hvNavDoLogout() {
    fetch(apiLogout, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: '{}' })
      .then(function (r) { return r.text().then(function () { return r.ok; }); })
      .then(function (ok) {
        if (ok) {
          hvNavAfterLogoutGo();
          return;
        }
        window.location.replace(apiLogout + '?lang=' + encodeURIComponent(lang) + '&_=' + Date.now());
      })
      .catch(function () {
        window.location.replace(apiLogout + '?lang=' + encodeURIComponent(lang) + '&_=' + Date.now());
      });
  }
  /** Sesión aprobada en servidor (layout). `logged` puede ampliarse con cookie; el badge de mensajes solo con sesión real. */
  var authApproved = document.body && document.body.getAttribute('data-hv-auth-approved') === '1';
  var logged = authApproved;
  var cartLinks = document.querySelectorAll('.hv-nav-cart-link');
  var loginGroups = document.querySelectorAll('.hv-nav-login-btns');
  var nu = document.getElementById('nav-user');
  var nuMobile = document.getElementById('nav-user-mobile');
  var extra = document.getElementById('nav-auth-extra');
  var mextra = document.getElementById('hv-nav-mobile-extra');
  var ordersLabel = <?= json_encode($dict['nav']['orders'] ?? 'Orders') ?>;
  var messagesLabel = <?= json_encode($dict['nav']['messages'] ?? 'Messages') ?>;

  function makeLogoutButton() {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'w-full py-3 rounded-xl text-sm font-semibold text-gray-600 border border-gray-200 bg-white hover:bg-red-50 hover:text-red-700 hover:border-red-200 transition';
    btn.textContent = <?= json_encode($dict['nav']['logout']) ?>;
    btn.addEventListener('click', function () { hvNavDoLogout(); });
    return btn;
  }

  if (logged) {
    loginGroups.forEach(function (el) { el.classList.add('hidden'); });
    cartLinks.forEach(function (el) { el.setAttribute('href', base + '/' + lang + '/account/cart'); });
    if (extra) {
      var msgNav = (typeof window.HV_ROL !== 'undefined' && (window.HV_ROL === 2 || window.HV_ROL === 3))
        ? '<a href="'+base+'/'+lang+'/account/messages" class="hv-messages-nav-trigger relative text-sm font-medium tracking-wide transition text-gray-500 hover:text-gray-900 inline-flex items-center gap-1.5"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>'+messagesLabel+'<span data-hv-msg-badge class="absolute -top-1.5 -right-2 bg-emerald-600 text-white text-[9px] min-w-[16px] h-4 flex items-center justify-center rounded-full px-0.5 font-bold" style="display:none">0</span></a>' : '';
      extra.innerHTML = msgNav +
        '<a href="'+base+'/'+lang+'/account/orders" class="text-sm font-medium tracking-wide transition text-gray-500 hover:text-gray-900"><span class="flex items-center gap-1.5"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>'+ordersLabel+'</span></a>';
      extra.classList.remove('hidden');
    }
    if (mextra) {
      var msgM = (typeof window.HV_ROL !== 'undefined' && (window.HV_ROL === 2 || window.HV_ROL === 3))
        ? '<a href="'+base+'/'+lang+'/account/messages" class="hv-messages-nav-trigger relative flex items-center gap-3 py-3 px-3 rounded-xl text-base font-medium text-gray-800 hover:bg-gray-50"><svg class="w-5 h-5 text-gray-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>'+messagesLabel+'<span data-hv-msg-badge class="ml-auto bg-emerald-600 text-white text-xs min-w-[20px] h-5 flex items-center justify-center rounded-full px-1 font-bold" style="display:none">0</span></a>' : '';
      mextra.innerHTML = msgM +
        '<a href="'+base+'/'+lang+'/account/orders" class="flex items-center gap-3 py-3 px-3 rounded-xl text-base font-medium text-gray-800 hover:bg-gray-50"><svg class="w-5 h-5 text-gray-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>'+ordersLabel+'</a>';
      mextra.classList.remove('hidden');
      mextra.classList.add('flex');
    }
    if (nu) {
      nu.classList.remove('hidden');
      var dbtn = document.createElement('button');
      dbtn.type = 'button';
      dbtn.className = 'px-4 py-2.5 rounded-xl text-sm font-medium text-gray-500 hover:text-red-700 hover:bg-red-50 transition';
      dbtn.textContent = <?= json_encode($dict['nav']['logout']) ?>;
      dbtn.addEventListener('click', function () { hvNavDoLogout(); });
      nu.appendChild(dbtn);
    }
    if (nuMobile) {
      nuMobile.classList.remove('hidden');
      nuMobile.classList.add('flex');
      nuMobile.appendChild(makeLogoutButton());
    }
  }

  var drawer = document.getElementById('hv-nav-drawer');
  var menuBtn = document.getElementById('hv-nav-menu-btn');
  var closeBtn = document.getElementById('hv-nav-drawer-close');
  var back = document.getElementById('hv-nav-drawer-back');
  if (drawer && menuBtn) {
    function closeNavDrawer() {
      drawer.classList.add('hidden');
      drawer.setAttribute('aria-hidden', 'true');
      menuBtn.setAttribute('aria-expanded', 'false');
      document.body.classList.remove('overflow-hidden');
    }
    function openNavDrawer() {
      drawer.classList.remove('hidden');
      drawer.setAttribute('aria-hidden', 'false');
      menuBtn.setAttribute('aria-expanded', 'true');
      document.body.classList.add('overflow-hidden');
    }
    menuBtn.addEventListener('click', function () {
      if (drawer.classList.contains('hidden')) openNavDrawer(); else closeNavDrawer();
    });
    if (closeBtn) closeBtn.addEventListener('click', closeNavDrawer);
    if (back) back.addEventListener('click', closeNavDrawer);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && drawer && !drawer.classList.contains('hidden')) closeNavDrawer();
    });
    drawer.addEventListener('click', function (e) {
      var t = e.target;
      if (!t || !t.closest) return;
      var a = t.closest('a');
      if (a && drawer.contains(a)) closeNavDrawer();
    });
  }

  function refreshMsgBadge() {
    if (typeof window.HV_ROL === 'undefined' || (window.HV_ROL !== 2 && window.HV_ROL !== 3)) return;
    fetch(base + '/api/messages?badge=1&lang=' + encodeURIComponent(lang), { credentials: 'same-origin', hvNoLoader: true })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
      .then(function (d) {
        var n = parseInt(d && d.unread, 10) || 0;
        document.querySelectorAll('[data-hv-msg-badge]').forEach(function (el) {
          el.textContent = n > 99 ? '99+' : String(n);
          el.style.display = n > 0 ? 'flex' : 'none';
        });
      })
      .catch(function () {});
  }
  if (authApproved && typeof window.HV_ROL !== 'undefined' && (window.HV_ROL === 2 || window.HV_ROL === 3)) {
    refreshMsgBadge();
    setInterval(refreshMsgBadge, 45000);
  }
});
</script>
