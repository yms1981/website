/**
 * Loader global: navegación, envío de formularios y fetch (salvo init.hvNoLoader).
 */
(function () {
  window.HV = window.HV || {};

  var ref = 0;
  var defaultLabel = '';

  function root() {
    return document.getElementById('hv-global-loader');
  }

  function sync() {
    var el = root();
    if (!el) return;
    var on = ref > 0;
    el.classList.toggle('hv-global-loader--on', on);
    el.setAttribute('aria-busy', on ? 'true' : 'false');
    el.setAttribute('aria-hidden', on ? 'false' : 'true');
    if (!on) {
      var span = el.querySelector('[data-hv-loader-label]');
      if (span && defaultLabel) span.textContent = defaultLabel;
    }
  }

  HV.loader = {
    show: function (message) {
      ref++;
      var el = root();
      if (el && message) {
        var span = el.querySelector('[data-hv-loader-label]');
        if (span) span.textContent = message;
      }
      sync();
    },
    hide: function () {
      ref = Math.max(0, ref - 1);
      sync();
    },
    forceHide: function () {
      ref = 0;
      sync();
    },
    setMessage: function (message) {
      var el = root();
      if (!el || !message) return;
      var span = el.querySelector('[data-hv-loader-label]');
      if (span) span.textContent = message;
    }
  };

  function fetchUrlString(input) {
    if (typeof input === 'string') {
      return input;
    }
    if (input && typeof input.url === 'string') {
      return input.url;
    }
    return '';
  }

  /** Polling de mensajería (badge, conversaciones, hilo): no mostrar overlay. */
  function isMessagingApiFetch(input) {
    try {
      var s = fetchUrlString(input);
      if (!s) return false;
      var path = new URL(s, window.location.href).pathname;
      return path.indexOf('/api/messages') !== -1;
    } catch (e) {
      return false;
    }
  }

  var origFetch = window.fetch;
  window.fetch = function (input, init) {
    var p = origFetch.apply(this, arguments);
    if (!p || typeof p.then !== 'function') return p;
    if (init && init.hvNoLoader) return p;
    if (isMessagingApiFetch(input)) return p;
    HV.loader.show();
    return p.then(
      function (v) {
        HV.loader.hide();
        return v;
      },
      function (e) {
        HV.loader.hide();
        throw e;
      }
    );
  };

  document.addEventListener('DOMContentLoaded', function () {
    var el = root();
    if (el) {
      var span = el.querySelector('[data-hv-loader-label]');
      if (span) defaultLabel = span.textContent;
    }

    document.addEventListener(
      'click',
      function (e) {
        var a = e.target.closest && e.target.closest('a[href]');
        if (!a) return;
        if (a.target === '_blank' || a.hasAttribute('download')) return;
        var href = a.getAttribute('href');
        if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return;
        if (e.defaultPrevented) return;
        if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        try {
          var u = new URL(a.href, window.location.href);
          if (u.origin !== window.location.origin) return;
        } catch (err) {
          return;
        }
        HV.loader.show();
      },
      true
    );

  });

  window.addEventListener('load', function () {
    HV.loader.forceHide();
  });

  window.addEventListener('pageshow', function (ev) {
    if (ev.persisted) HV.loader.forceHide();
  });
})();
