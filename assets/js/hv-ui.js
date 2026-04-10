(function () {
  function swalLabels() {
    var g = window.HV_SWAL_I18N || {};
    return {
      ok: g.confirm || 'OK',
      cancel: g.cancel || 'Cancel'
    };
  }

  function updateCartBadge() {
    if (!window.HV || !HV.cart) return;
    var n = HV.cart.count();
    document.querySelectorAll('[data-hv-cart-count]').forEach(function (el) {
      el.textContent = n > 99 ? '99+' : String(n);
      el.style.display = n > 0 ? '' : 'none';
    });
  }

  window.addEventListener('hv-cart-change', updateCartBadge);
  document.addEventListener('DOMContentLoaded', updateCartBadge);

  window.HV = window.HV || {};

  /**
   * @param {string} msg
   * @param {{ icon?: string, timer?: number }} [opts]
   */
  window.HV.toast = function (msg, opts) {
    opts = opts || {};
    var m = String(msg == null ? '' : msg);
    if (typeof Swal !== 'undefined') {
      Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: opts.timer != null ? opts.timer : 2800,
        timerProgressBar: true
      }).fire({
        icon: opts.icon || 'success',
        title: m
      });
      return;
    }
    var t = document.createElement('div');
    t.className = 'hv-toast';
    t.textContent = m;
    document.body.appendChild(t);
    setTimeout(function () {
      t.classList.add('hv-toast-out');
      setTimeout(function () { t.remove(); }, 300);
    }, 2500);
  };

  /**
   * @param {{ title?: string, text?: string, html?: string, icon?: string, confirmButtonText?: string, cancelButtonText?: string, confirmButtonColor?: string, cancelButtonColor?: string, showCancelButton?: boolean }} [opts]
   * @returns {Promise<boolean>}
   */
  window.HV.confirm = function (opts) {
    opts = opts || {};
    var text = opts.text != null ? String(opts.text) : '';
    if (typeof Swal === 'undefined') {
      return Promise.resolve(window.confirm(text || opts.title || ''));
    }
    var lb = swalLabels();
    return Swal.fire({
      title: opts.title || '',
      html: opts.html,
      text: opts.html ? undefined : text,
      icon: opts.icon || 'question',
      showCancelButton: opts.showCancelButton !== false,
      confirmButtonText: opts.confirmButtonText || lb.ok,
      cancelButtonText: opts.cancelButtonText || lb.cancel,
      confirmButtonColor: opts.confirmButtonColor || '#b91c1c',
      cancelButtonColor: opts.cancelButtonColor || '#6b7280',
      reverseButtons: true,
      focusCancel: true
    }).then(function (r) {
      return !!r.isConfirmed;
    });
  };

  /**
   * @param {string} msg
   * @param {{ title?: string, html?: string, icon?: string, confirmButtonText?: string, confirmButtonColor?: string }} [opts]
   * @returns {Promise<void>}
   */
  window.HV.alert = function (msg, opts) {
    opts = opts || {};
    var m = msg != null ? String(msg) : '';
    if (typeof Swal === 'undefined') {
      window.alert(m);
      return Promise.resolve();
    }
    var lb = swalLabels();
    return Swal.fire({
      title: opts.title || '',
      text: opts.html ? undefined : m,
      html: opts.html,
      icon: opts.icon || 'info',
      confirmButtonText: opts.confirmButtonText || lb.ok,
      confirmButtonColor: opts.confirmButtonColor || '#b91c1c'
    }).then(function () {});
  };
})();
