/**
 * Carrito: vendedores (rol 2) en localStorage por cliente FV.
 * Clientes logueados no vendedores: MySQL vía /{lang}/account/cart-db (misma sesión que index.php; data-hv-portal-cart=1). Invitados: localStorage.
 */
(function () {
  var BASE_KEY = 'hv-cart';
  var CTX_SESSION_LEGACY = 'hv-seller-cart-customer-fv';
  var CTX_LOCAL_PREFIX = 'hv-seller-selected-customer-fv';

  var portalCartCache = [];

  function usePortalCart() {
    if (!document.body) return false;
    if (bodyRol() === 2) return false;
    return document.body.getAttribute('data-hv-portal-cart') === '1';
  }

  function portalLang() {
    var h = document.documentElement && document.documentElement.getAttribute('lang');
    return h && String(h).trim() ? String(h).trim().slice(0, 8) : 'en';
  }

  /** Carrito persistido en PHP+MySQL por la misma cookie que el resto del sitio (evita /api/cart). */
  function portalCartDbUrl() {
    var base = (window.HV_BASE || '').replace(/\/?$/, '');
    return base + '/' + portalLang() + '/account/cart-db';
  }

  function portalPayloadFromRow(row) {
    var moq = Math.max(1, parseInt(row.moq, 10) || 1);
    var ln = row.lineNote != null ? String(row.lineNote) : '';
    if (ln.length > 2000) ln = ln.slice(0, 2000);
    return {
      productId: row.productId,
      qty: row.qty,
      name: row.name || '',
      sku: row.sku || '',
      image: row.image || '',
      moq: moq,
      sale_price: parseFloat(row.unitPrice) || 0,
      fob_price: 0,
      lineNote: ln
    };
  }

  function portalMapItems(items) {
    if (!Array.isArray(items)) return [];
    return items.map(function (it) {
      var lid = parseInt(it.cart_id, 10) || parseInt(it.cartId, 10) || 0;
      var noteRaw = it.line_note != null ? it.line_note : (it.lineNote != null ? it.lineNote : '');
      return {
        productId: parseInt(it.product_id, 10) || parseInt(it.productId, 10) || 0,
        cartLineId: isNaN(lid) ? 0 : lid,
        name: it.product_name || it.name || '',
        sku: it.sku || '',
        image: it.image || '',
        qty: parseInt(it.qty, 10) || 0,
        moq: Math.max(1, parseInt(it.moq, 10) || 1),
        unitPrice: parseFloat(it.sale_price != null ? it.sale_price : it.price) || 0,
        lineNote: String(noteRaw).slice(0, 2000)
      };
    }).filter(function (x) { return x.productId > 0; });
  }

  function portalNotify() {
    window.dispatchEvent(new CustomEvent('hv-cart-change'));
  }

  function parseJsonResponse(r, text) {
    var j = {};
    try {
      j = text && String(text).trim() ? JSON.parse(text) : {};
    } catch (e) {
      var snip = String(text || '').slice(0, 120);
      throw new Error(snip ? 'invalid_json' : 'empty_response');
    }
    if (!r.ok) {
      throw new Error((j && j.error) ? j.error : ('HTTP ' + r.status));
    }
    if (j.error) {
      throw new Error(j.error);
    }
    if (j.status != null && String(j.status) !== '1') {
      throw new Error(j.error || 'cart');
    }
    return j;
  }

  function portalPostLine(payload) {
    var body = Object.assign({ lang: portalLang() }, payload);
    if (body.productId != null) {
      body.productId = parseInt(body.productId, 10) || 0;
    }
    return fetch(portalCartDbUrl(), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
      hvNoLoader: true
    }).then(function (r) {
      return r.text().then(function (text) {
        return parseJsonResponse(r, text);
      });
    }).then(function (j) {
      return portalRefreshFromServer()
        .catch(function () {})
        .then(function () {
          return j;
        });
    }).catch(function () {
      return portalRefreshFromServer().catch(function () {
        portalCartCache = [];
        portalNotify();
      });
    });
  }

  function portalDeleteRequest(body) {
    var b = Object.assign({}, body);
    if (b.productId != null) {
      b.productId = parseInt(b.productId, 10) || 0;
    }
    if (b.cartId != null) {
      b.cartId = parseInt(b.cartId, 10) || 0;
    }
    return fetch(portalCartDbUrl(), {
      method: 'DELETE',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(b),
      hvNoLoader: true
    }).then(function (r) {
      return r.text().then(function (text) {
        return parseJsonResponse(r, text);
      });
    }).then(function (j) {
      return portalRefreshFromServer()
        .catch(function () {})
        .then(function () {
          return j;
        });
    }).catch(function () {
      return portalRefreshFromServer().catch(function () {
        portalCartCache = [];
        portalNotify();
      });
    });
  }

  function portalRefreshFromServer() {
    return fetch(portalCartDbUrl() + '?lang=' + encodeURIComponent(portalLang()), {
      credentials: 'same-origin',
      hvNoLoader: true
    })
      .then(function (r) {
        return r.text().then(function (text) {
          var data = {};
          try {
            data = text && String(text).trim() ? JSON.parse(text) : {};
          } catch (e) {
            throw new Error('cart_sync_invalid_json');
          }
          if (!r.ok) {
            throw new Error((data && data.error) ? data.error : ('HTTP ' + r.status));
          }
          if (data.error) {
            throw new Error(data.error);
          }
          return data;
        });
      })
      .then(function (data) {
        portalCartCache = portalMapItems(data.items || []);
        portalNotify();
      });
  }

  function portalMigrateGuestIfNeeded() {
    try {
      var raw = localStorage.getItem(BASE_KEY);
      var guest = raw ? JSON.parse(raw) : [];
      if (!Array.isArray(guest) || !guest.length) return Promise.resolve();
      var chain = Promise.resolve();
      guest.forEach(function (g) {
        chain = chain.then(function () {
          var q = parseInt(g.qty, 10) || 0;
          if (q <= 0) return Promise.resolve();
          return portalPostLine(portalPayloadFromRow({
            productId: g.productId,
            qty: q,
            name: g.name || '',
            sku: g.sku || '',
            image: g.image || '',
            moq: Math.max(1, parseInt(g.moq, 10) || 1),
            unitPrice: parseFloat(g.unitPrice) || 0,
            lineNote: g.lineNote || g.note || ''
          }));
        });
      });
      return chain.then(function () {
        try { localStorage.removeItem(BASE_KEY); } catch (e2) {}
        return portalRefreshFromServer();
      });
    } catch (e) {
      return Promise.resolve();
    }
  }

  function portalInit() {
    portalRefreshFromServer()
      .then(function () {
        if (portalCartCache.length === 0) {
          return portalMigrateGuestIfNeeded();
        }
      })
      .catch(function () {
        portalNotify();
      });
  }

  function bodyRol() {
    if (!document.body) return 0;
    var a = document.body.getAttribute('data-hv-rol');
    if (a == null || a === '') return 0;
    var n = parseInt(a, 10);
    return isNaN(n) ? 0 : n;
  }

  function ctxStorageKey() {
    var su = typeof window !== 'undefined' && window.HV_SELLER_USER_ID ? parseInt(String(window.HV_SELLER_USER_ID), 10) : 0;
    if (su > 0) {
      return CTX_LOCAL_PREFIX + ':' + su;
    }
    return CTX_LOCAL_PREFIX;
  }

  function ctxFvId() {
    try {
      if (bodyRol() !== 2) return '';
      var key = ctxStorageKey();
      var v = localStorage.getItem(key);
      var s = v && String(v).trim() ? String(v).trim() : '';
      if (!s) {
        try {
          var leg = sessionStorage.getItem(CTX_SESSION_LEGACY);
          if (leg && String(leg).trim()) {
            s = String(leg).trim();
            localStorage.setItem(key, s);
            sessionStorage.removeItem(CTX_SESSION_LEGACY);
          }
        } catch (e2) {}
      }
      return s;
    } catch (e) {
      return '';
    }
  }

  function activeKey() {
    if (bodyRol() === 2) {
      var id = ctxFvId();
      if (!id) return null;
      return BASE_KEY + '-seller-' + id;
    }
    return BASE_KEY;
  }

  /** Mismo productId aunque uno venga como string (p. ej. data-pid) y otro como número. */
  function pidEq(a, b) {
    return (parseInt(a, 10) || 0) === (parseInt(b, 10) || 0);
  }

  /** Vendedor sin cliente FV elegido: no hay clave localStorage → save() no persiste nada. */
  function sellerCartPersistBlocked() {
    return bodyRol() === 2 && activeKey() === null;
  }

  function toastSellerNeedsCustomer() {
    var m = '';
    try {
      if (document.body) {
        m = document.body.getAttribute('data-hv-seller-cart-hint') || '';
      }
    } catch (e) {}
    m = String(m).trim();
    if (!m) m = 'Choose a customer in the catalog to use the cart.';
    if (window.HV && typeof HV.toast === 'function') HV.toast(m, { icon: 'warning' });
  }

  function currentQtyForProduct(productId) {
    var items = load();
    var cur = items.find(function (i) { return pidEq(i.productId, productId); });
    return cur ? cur.qty : 0;
  }

  function sellerCartPrefix() {
    return BASE_KEY + '-seller-';
  }

  function clearAllSellerCartKeys() {
    try {
      var prefix = sellerCartPrefix();
      var toRemove = [];
      var i;
      for (i = 0; i < localStorage.length; i++) {
        var k = localStorage.key(i);
        if (k && k.indexOf(prefix) === 0) {
          toRemove.push(k);
        }
      }
      for (i = 0; i < toRemove.length; i++) {
        localStorage.removeItem(toRemove[i]);
      }
    } catch (e) {}
  }

  function normalizeSellerCartItem(it) {
    if (!it || typeof it !== 'object') return it;
    var u = parseFloat(it.unitPrice);
    it.unitPrice = isNaN(u) || u < 0 ? 0 : u;
    if (it.lineNote != null) {
      it.lineNote = String(it.lineNote).slice(0, 2000);
    }
    return it;
  }

  function normalizeSellerCartItems(arr) {
    if (!Array.isArray(arr)) return [];
    return arr.map(function (x) { return normalizeSellerCartItem(x); });
  }

  function load() {
    if (usePortalCart()) {
      return portalCartCache.map(function (x) {
        return Object.assign({}, x);
      });
    }
    var k = activeKey();
    if (!k) return [];
    try {
      var raw = localStorage.getItem(k);
      var arr = raw ? JSON.parse(raw) : [];
      if (bodyRol() === 2 && Array.isArray(arr)) {
        return normalizeSellerCartItems(arr);
      }
      return arr;
    } catch (e) {
      return [];
    }
  }

  function save(items) {
    if (usePortalCart()) {
      return;
    }
    var k = activeKey();
    if (!k) return;
    try {
      var toStore = items;
      if (bodyRol() === 2 && Array.isArray(items)) {
        toStore = normalizeSellerCartItems(items.slice());
      }
      localStorage.setItem(k, JSON.stringify(toStore));
      window.dispatchEvent(new CustomEvent('hv-cart-change'));
    } catch (e) {}
  }

  function imgForProduct(p) {
    if (!p.images || !p.images.length) return 'https://app.fullvendor.com/uploads/noimg.png';
    var im = p.images.find(function (x) { return x.img_default == 1; }) || p.images[0];
    return im.pic || 'https://app.fullvendor.com/uploads/noimg.png';
  }

  window.HV = window.HV || {};
  window.HV.cart = {
    load: load,
    save: save,
    /** Recarga carrito portal desde el servidor (clientes no vendedor). */
    refreshPortalFromServer: function () {
      if (!usePortalCart()) return Promise.resolve();
      return portalRefreshFromServer();
    },
    count: function () {
      return load().reduce(function (s, i) { return s + (i.qty || 0); }, 0);
    },
    add: function (productId, name, sku, image, qty, moq, unitPrice) {
      productId = parseInt(productId, 10) || 0;
      if (productId <= 0) return;
      if (usePortalCart()) {
        qty = qty || 1;
        moq = Math.max(1, moq || 1);
        var items = portalCartCache;
        var ix = items.findIndex(function (i) { return pidEq(i.productId, productId); });
        var upNew = unitPrice != null && !isNaN(parseFloat(unitPrice)) ? parseFloat(unitPrice) : 0;
        if (upNew < 0) upNew = 0;
        var newQty;
        if (ix >= 0) {
          items[ix].qty += qty;
          if (upNew >= 0 && (items[ix].unitPrice === undefined || items[ix].unitPrice === null || parseFloat(items[ix].unitPrice) === 0)) {
            items[ix].unitPrice = upNew;
          }
          newQty = items[ix].qty;
        } else {
          newQty = qty;
          items.push({ productId: productId, name: name, sku: sku, image: image, qty: newQty, moq: moq, unitPrice: upNew, lineNote: '' });
        }
        portalNotify();
        var row = items.find(function (i) { return pidEq(i.productId, productId); });
        portalPostLine(portalPayloadFromRow(row));
        return;
      }
      if (sellerCartPersistBlocked()) {
        toastSellerNeedsCustomer();
        return;
      }
      qty = qty || 1;
      moq = Math.max(1, moq || 1);
      var items = load();
      var ix = items.findIndex(function (i) { return pidEq(i.productId, productId); });
      if (ix >= 0) {
        items[ix].qty += qty;
        if (unitPrice != null && !isNaN(parseFloat(unitPrice))) {
          var upAdd = parseFloat(unitPrice);
          if (upAdd >= 0 && (items[ix].unitPrice === undefined || items[ix].unitPrice === null || parseFloat(items[ix].unitPrice) === 0)) {
            items[ix].unitPrice = upAdd;
          }
        }
      } else {
        var upNew = unitPrice != null && !isNaN(parseFloat(unitPrice)) ? parseFloat(unitPrice) : 0;
        if (upNew < 0) upNew = 0;
        items.push({ productId: productId, name: name, sku: sku, image: image, qty: qty, moq: moq, unitPrice: upNew });
      }
      save(items);
    },
    setQty: function (productId, qty) {
      productId = parseInt(productId, 10) || 0;
      if (productId <= 0) return;
      if (usePortalCart()) {
        var itemsPsq = portalCartCache;
        var ixPsq = itemsPsq.findIndex(function (i) { return pidEq(i.productId, productId); });
        if (qty < 1) {
          if (ixPsq >= 0) itemsPsq.splice(ixPsq, 1);
          portalNotify();
          portalDeleteRequest({ productId: productId });
        } else if (ixPsq >= 0) {
          itemsPsq[ixPsq].qty = qty;
          portalNotify();
          var rSq = itemsPsq[ixPsq];
          portalPostLine(portalPayloadFromRow(rSq));
        }
        return;
      }
      if (sellerCartPersistBlocked()) {
        toastSellerNeedsCustomer();
        return;
      }
      var items = load();
      var ix = items.findIndex(function (i) { return pidEq(i.productId, productId); });
      if (qty < 1) {
        if (ix >= 0) items.splice(ix, 1);
      } else if (ix >= 0) items[ix].qty = qty;
      save(items);
    },
    remove: function (productId) {
      productId = parseInt(productId, 10) || 0;
      if (productId <= 0) return;
      if (usePortalCart()) {
        portalCartCache = portalCartCache.filter(function (i) { return !pidEq(i.productId, productId); });
        portalNotify();
        portalDeleteRequest({ productId: productId });
        return;
      }
      if (sellerCartPersistBlocked()) {
        toastSellerNeedsCustomer();
        return;
      }
      save(load().filter(function (i) { return !pidEq(i.productId, productId); }));
    },
    clear: function () {
      if (usePortalCart()) {
        portalCartCache = [];
        portalNotify();
        portalDeleteRequest({ clearAll: true });
        return;
      }
      if (sellerCartPersistBlocked()) {
        toastSellerNeedsCustomer();
        return;
      }
      save([]);
    },
    /** Tras logout: vacía carrito portal en memoria, localStorage vendedor/invitado y contexto de cliente FV. */
    clearClientStorageOnLogout: function () {
      try {
        portalCartCache = [];
        clearAllSellerCartKeys();
        try {
          localStorage.removeItem(BASE_KEY);
        } catch (e0) {}
        var i;
        var k;
        for (i = localStorage.length - 1; i >= 0; i--) {
          k = localStorage.key(i);
          if (k && k.indexOf(CTX_LOCAL_PREFIX) === 0) {
            localStorage.removeItem(k);
          }
        }
        try {
          sessionStorage.removeItem(CTX_SESSION_LEGACY);
        } catch (e1) {}
        window.dispatchEvent(new CustomEvent('hv-cart-change'));
      } catch (e) {}
    },
    stepQty: function (productId, name, sku, image, moq, delta, unitPrice) {
      productId = parseInt(productId, 10) || 0;
      if (productId <= 0) return 0;
      if (usePortalCart()) {
        moq = Math.max(1, moq || 1);
        var items = portalCartCache;
        var cur = items.find(function (i) { return pidEq(i.productId, productId); });
        var q = cur ? cur.qty : 0;
        var nq;
        if (delta > 0) nq = q === 0 ? moq : q + moq;
        else nq = Math.max(0, q - moq);
        if (nq === 0) {
          portalCartCache = items.filter(function (i) { return !pidEq(i.productId, productId); });
          portalNotify();
          portalDeleteRequest({ productId: productId });
          return 0;
        }
        var upS = unitPrice != null && !isNaN(parseFloat(unitPrice)) ? parseFloat(unitPrice) : 0;
        if (cur) {
          cur.qty = nq;
          cur.moq = moq;
          if (upS >= 0 && (cur.unitPrice === undefined || cur.unitPrice === null || parseFloat(cur.unitPrice) === 0)) {
            cur.unitPrice = upS;
          }
        } else {
          items.push({ productId: productId, name: name, sku: sku, image: image, qty: nq, moq: moq, unitPrice: upS < 0 ? 0 : upS, lineNote: '' });
        }
        var row = portalCartCache.find(function (i) { return pidEq(i.productId, productId); });
        portalNotify();
        portalPostLine(portalPayloadFromRow(row));
        return nq;
      }
      if (sellerCartPersistBlocked()) {
        toastSellerNeedsCustomer();
        return currentQtyForProduct(productId);
      }
      moq = Math.max(1, moq || 1);
      var items = load();
      var cur = items.find(function (i) { return pidEq(i.productId, productId); });
      var q = cur ? cur.qty : 0;
      var nq;
      if (delta > 0) nq = q === 0 ? moq : q + moq;
      else nq = Math.max(0, q - moq);
      if (nq === 0) this.remove(productId);
      else if (cur) {
        cur.qty = nq;
        cur.moq = moq;
        if (unitPrice != null && !isNaN(parseFloat(unitPrice))) {
          var upS = parseFloat(unitPrice);
          if (upS >= 0 && (cur.unitPrice === undefined || cur.unitPrice === null || parseFloat(cur.unitPrice) === 0)) {
            cur.unitPrice = upS;
          }
        }
        save(items);
      } else {
        this.add(productId, name, sku, image, nq, moq, unitPrice);
      }
      return nq;
    },
    setItemUnitPrice: function (productId, price) {
      productId = parseInt(productId, 10) || 0;
      if (productId <= 0) return;
      if (usePortalCart()) {
        var itemsPu = portalCartCache;
        var ixPu = itemsPu.findIndex(function (i) { return pidEq(i.productId, productId); });
        if (ixPu < 0) return;
        var p = parseFloat(String(price).replace(',', '.'));
        if (isNaN(p) || p < 0) p = 0;
        itemsPu[ixPu].unitPrice = p;
        portalNotify();
        var r = itemsPu[ixPu];
        portalPostLine(portalPayloadFromRow(r));
        return;
      }
      if (sellerCartPersistBlocked()) {
        toastSellerNeedsCustomer();
        return;
      }
      var items = load();
      var ix = items.findIndex(function (i) { return pidEq(i.productId, productId); });
      if (ix < 0) return;
      var p = parseFloat(String(price).replace(',', '.'));
      if (isNaN(p) || p < 0) p = 0;
      items[ix].unitPrice = p;
      save(items);
    },
    setItemQty: function (productId, qty) {
      if (usePortalCart()) {
        var itemsPq = portalCartCache;
        var ixPq = itemsPq.findIndex(function (i) { return i.productId === productId; });
        if (ixPq < 0) return;
        var it = itemsPq[ixPq];
        var moq = Math.max(1, parseInt(it.moq, 10) || 1);
        var q = parseInt(String(qty), 10) || 0;
        if (q <= 0) {
          itemsPq.splice(ixPq, 1);
          portalNotify();
          portalDeleteRequest({ productId: productId });
        } else {
          var snapped = Math.ceil(q / moq) * moq;
          it.qty = snapped;
          portalNotify();
          portalPostLine(portalPayloadFromRow(it));
        }
        return;
      }
      var items = load();
      var ix = items.findIndex(function (i) { return i.productId === productId; });
      if (ix < 0) return;
      var it = items[ix];
      var moq = Math.max(1, parseInt(it.moq, 10) || 1);
      var q = parseInt(String(qty), 10) || 0;
      if (q <= 0) {
        items.splice(ix, 1);
      } else {
        var snapped = Math.ceil(q / moq) * moq;
        it.qty = snapped;
      }
      save(items);
    },
    setItemLineNote: function (productId, text) {
      productId = parseInt(productId, 10) || 0;
      if (productId <= 0) return;
      if (usePortalCart()) {
        var itemsPl = portalCartCache;
        var ixPl = itemsPl.findIndex(function (i) { return pidEq(i.productId, productId); });
        if (ixPl < 0) return;
        itemsPl[ixPl].lineNote = String(text == null ? '' : text).slice(0, 2000);
        portalNotify();
        portalPostLine(portalPayloadFromRow(itemsPl[ixPl]));
        return;
      }
      if (sellerCartPersistBlocked()) {
        toastSellerNeedsCustomer();
        return;
      }
      var items = load();
      var ix = items.findIndex(function (i) { return pidEq(i.productId, productId); });
      if (ix < 0) return;
      items[ix].lineNote = String(text == null ? '' : text).slice(0, 2000);
      save(items);
    },
    imgForProduct: imgForProduct,
    setSellerCustomerFvId: function (fvId) {
      try {
        if (bodyRol() !== 2) return;
        var key = ctxStorageKey();
        var t = fvId && String(fvId).trim() ? String(fvId).trim() : '';
        if (t) localStorage.setItem(key, t);
        else localStorage.removeItem(key);
        try {
          sessionStorage.removeItem(CTX_SESSION_LEGACY);
        } catch (e2) {}
      } catch (e) {}
      window.dispatchEvent(new CustomEvent('hv-cart-change'));
      window.dispatchEvent(new CustomEvent('hv-seller-customer-change'));
    },
    getSellerCustomerFvId: function () {
      return ctxFvId();
    },
    sellerNeedsCustomer: function () {
      return bodyRol() === 2;
    },
    /** Todos los carritos vendedor en localStorage (clave = customer FV). */
    exportSellerCartsSnapshot: function () {
      var out = {};
      if (bodyRol() !== 2) {
        return out;
      }
      try {
        var prefix = sellerCartPrefix();
        var i;
        for (i = 0; i < localStorage.length; i++) {
          var k = localStorage.key(i);
          if (!k || k.indexOf(prefix) !== 0) {
            continue;
          }
          var fv = k.slice(prefix.length);
          var raw = localStorage.getItem(k);
          if (!raw) {
            continue;
          }
          try {
            var arr = JSON.parse(raw);
            if (Array.isArray(arr) && arr.length) {
              out[fv] = arr.map(function (it) {
                var u = parseFloat(it.unitPrice);
                return {
                  productId: it.productId,
                  qty: it.qty,
                  moq: Math.max(1, parseInt(it.moq, 10) || 1),
                  name: it.name || '',
                  sku: it.sku || '',
                  image: it.image || '',
                  unitPrice: isNaN(u) || u < 0 ? 0 : u,
                  lineNote: String(it.lineNote || it.note || '').slice(0, 2000)
                };
              });
            }
          } catch (e2) {}
        }
      } catch (e) {}
      return out;
    },
    /**
     * Aplica estado devuelto por GET /api/seller-catalog-state (sobrescribe local).
     * @param {{ selectedCustomerFv?: number|null, carts?: Record<string, unknown[]> }} payload
     */
    applySellerCatalogStateFromServer: function (payload) {
      if (bodyRol() !== 2 || !payload) {
        return;
      }
      try {
        clearAllSellerCartKeys();
        var prefix = sellerCartPrefix();
        var carts = payload.carts;
        if (carts && typeof carts === 'object') {
          var fv;
          for (fv in carts) {
            if (!Object.prototype.hasOwnProperty.call(carts, fv)) {
              continue;
            }
            var items = carts[fv];
            if (!Array.isArray(items) || !items.length) {
              continue;
            }
            localStorage.setItem(prefix + fv, JSON.stringify(normalizeSellerCartItems(items)));
          }
        }
        if (typeof payload.orderNotes === 'string') {
          var ta = document.getElementById('hv-seller-order-notes');
          if (ta) ta.value = payload.orderNotes;
        }
        var key = ctxStorageKey();
        var rawSel = payload.selectedCustomerFv;
        var sel = rawSel != null && String(rawSel).trim() !== '' ? String(rawSel).trim() : '';
        if (sel) {
          localStorage.setItem(key, sel);
        } else {
          localStorage.removeItem(key);
        }
        try {
          sessionStorage.removeItem(CTX_SESSION_LEGACY);
        } catch (e3) {}
      } catch (e) {}
      window.dispatchEvent(new CustomEvent('hv-cart-change'));
      window.dispatchEvent(new CustomEvent('hv-seller-customer-change'));
    }
  };

  document.addEventListener('DOMContentLoaded', function () {
    if (document.body && document.body.getAttribute('data-hv-portal-cart') === '1') {
      portalInit();
    }
  });
})();

