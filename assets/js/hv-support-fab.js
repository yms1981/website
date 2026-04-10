(function () {
  'use strict';
  var D = window.HV_SUPPORT_ASSISTANT || {};
  var U = window.HV_SUPPORT_USER || {};
  var base = (window.HV_BASE || '').replace(/\/?$/, '');
  var lang = typeof window.HV_SUPPORT_LANG === 'string' && window.HV_SUPPORT_LANG ? window.HV_SUPPORT_LANG : 'en';

  function esc(s) {
    var t = document.createElement('div');
    t.textContent = s == null ? '' : String(s);
    return t.innerHTML;
  }

  function simpleMarkdownBold(s) {
    return esc(s).replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>').replace(/\*([^*]+)\*/g, '<em>$1</em>');
  }

  function fold(s) {
    var t = String(s || '').toLowerCase();
    return t
      .replace(/á/g, 'a')
      .replace(/é/g, 'e')
      .replace(/í/g, 'i')
      .replace(/ó/g, 'o')
      .replace(/ú/g, 'u')
      .replace(/ü/g, 'u')
      .replace(/ñ/g, 'n');
  }

  function tpl(str, map) {
    var out = String(str || '');
    if (!map) return out;
    Object.keys(map).forEach(function (k) {
      out = out.split('{' + k + '}').join(map[k] == null ? '' : String(map[k]));
    });
    return out;
  }

  function buildGreeting() {
    var name = U && U.name ? String(U.name).trim() : '';
    var raw = name ? D.greeting_named || D.greeting || '' : D.greeting || '';
    return raw.replace(/\{name\}/g, name);
  }

  function appendBubble(root, text, who) {
    var wrap = document.createElement('div');
    wrap.className = 'hv-support-msg hv-support-msg--' + (who === 'user' ? 'user' : 'bot');
    var inner = document.createElement('div');
    inner.className = 'hv-support-msg__bubble';
    if (who === 'bot' && (/\*\*[^*]+\*\*/.test(text) || /\*[^*]+\*/.test(text))) {
      inner.innerHTML = simpleMarkdownBold(text);
    } else {
      inner.textContent = text;
    }
    wrap.appendChild(inner);
    root.appendChild(wrap);
    root.scrollTop = root.scrollHeight;
  }

  var ctxCache = null;
  var productsCache = null;
  var productsPromise = null;

  function fetchContext() {
    if (ctxCache) return Promise.resolve(ctxCache);
    var url = base + '/api/support/context?lang=' + encodeURIComponent(lang);
    return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) {
      return r.json().then(function (j) {
        if (!r.ok || !j || !j.context) throw new Error((j && j.error) || 'ctx');
        ctxCache = j.context;
        return ctxCache;
      });
    });
  }

  function fetchProducts() {
    if (productsCache !== null) return Promise.resolve(productsCache);
    if (productsPromise) return productsPromise;
    productsPromise = fetch(base + '/api/products?lang=' + encodeURIComponent(lang), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    })
      .then(function (r) {
        return r.json().then(function (j) {
          if (!r.ok) throw new Error('products');
          productsCache = Array.isArray(j.products) ? j.products : [];
          return productsCache;
        });
      })
      .catch(function () {
        productsCache = [];
        return productsCache;
      });
    return productsPromise;
  }

  function wantsProductData(qf) {
    return (
      qf.indexOf('stock') !== -1 ||
      qf.indexOf('inventario') !== -1 ||
      qf.indexOf('agotado') !== -1 ||
      qf.indexOf('disponible') !== -1 ||
      qf.indexOf('existencia') !== -1 ||
      qf.indexOf('categor') !== -1 ||
      qf.indexOf('rubro') !== -1 ||
      qf.indexOf('producto') !== -1 ||
      qf.indexOf('productos') !== -1 ||
      qf.indexOf('catalog') !== -1 ||
      qf.indexOf('catalogo') !== -1 ||
      qf.indexOf('cuantos') !== -1 ||
      qf.indexOf('how many') !== -1 ||
      qf.indexOf('price') !== -1 ||
      qf.indexOf('precio') !== -1
    );
  }

  function findCategoryByQuery(qf, categories) {
    var best = null;
    var bestLen = 0;
    (categories || []).forEach(function (c) {
      var nm = fold(c.name || '');
      if (nm.length < 2) return;
      if (qf.indexOf(nm) !== -1 && nm.length > bestLen) {
        best = c;
        bestLen = nm.length;
      }
    });
    return best;
  }

  function productInCategory(p, catId) {
    var raw = String((p && p.category_id) || '');
    var parts = raw.split(',');
    for (var i = 0; i < parts.length; i++) {
      if (String(parts[i]).trim() === String(catId)) return true;
    }
    return false;
  }

  function stockNumber(p) {
    var a = parseFloat(p && p.available_stock != null ? p.available_stock : NaN);
    if (!isNaN(a)) return a;
    var s = parseFloat(p && p.stock != null ? p.stock : NaN);
    return isNaN(s) ? 0 : s;
  }

  function scoreDocSections(qf, sections) {
    var toks = qf.split(/[^a-z0-9]+/).filter(function (t) {
      return t.length > 2;
    });
    if (toks.length === 0) return [];
    var scored = [];
    (sections || []).forEach(function (sec, idx) {
      var blob = fold((sec.title || '') + ' ' + (sec.body || ''));
      var score = 0;
      toks.forEach(function (t) {
        if (blob.indexOf(t) !== -1) score += 2;
      });
      if (score > 0) scored.push({ idx: idx, score: score, sec: sec });
    });
    scored.sort(function (a, b) {
      return b.score - a.score;
    });
    return scored;
  }

  function buildAnswer(userText, ctx, products) {
    var dyn = D.dyn || {};
    var R = (ctx && ctx.routes) || {};
    var qf = fold(userText);
    var chunks = [];

    var orderKw =
      qf.indexOf('pedido') !== -1 ||
      qf.indexOf('orden') !== -1 ||
      qf.indexOf('order') !== -1 ||
      qf.indexOf('compra') !== -1;
    var invKw = qf.indexOf('factura') !== -1 || qf.indexOf('invoice') !== -1;
    var balKw =
      qf.indexOf('saldo') !== -1 ||
      qf.indexOf('deuda') !== -1 ||
      qf.indexOf('balance') !== -1 ||
      qf.indexOf('adeudo') !== -1 ||
      qf.indexOf('credito') !== -1 ||
      qf.indexOf('crédito') !== -1 ||
      qf.indexOf('cuenta corriente') !== -1;
    var stockKw =
      qf.indexOf('stock') !== -1 ||
      qf.indexOf('inventario') !== -1 ||
      qf.indexOf('agotado') !== -1 ||
      qf.indexOf('disponible') !== -1 ||
      qf.indexOf('existencia') !== -1;
    var cartKw = qf.indexOf('carrito') !== -1 || qf.indexOf('cart') !== -1;
    var msgKw = qf.indexOf('mensaje') !== -1 || qf.indexOf('chat') !== -1;
    var catHintKw =
      qf.indexOf('categor') !== -1 || qf.indexOf('rubro') !== -1 || qf.indexOf('category') !== -1;

    var matchedCat = findCategoryByQuery(qf, ctx.categories || []);
    var needProducts = !!(matchedCat || (stockKw && catHintKw) || (matchedCat && stockKw));
    if (!needProducts && stockKw && products && products.length) needProducts = true;
    if (!needProducts && wantsProductData(qf)) needProducts = true;

    if (orderKw) {
      if (ctx.orders_from_db) {
        chunks.push(tpl(dyn.orders_intro_n, { n: String(ctx.orders_count || 0) }));
        var prev = ctx.orders_preview || [];
        if (prev.length) {
          chunks.push(dyn.orders_lines_header || '');
          prev.forEach(function (o) {
            chunks.push(
              tpl(dyn.order_line, {
                num: o.order_number || '#' + (o.order_id || ''),
                date: o.order_date || '—',
                status: o.status_label || '—',
                total: o.total || '—'
              })
            );
          });
        }
        chunks.push(tpl(dyn.orders_more, { url: R.orders || '' }));
      } else {
        chunks.push(tpl(dyn.orders_no_db, { url: R.orders || '' }));
      }
    }

    if (invKw) {
      chunks.push(
        lang === 'es'
          ? 'Las **facturas** están en **Cuenta → Facturas**: ' + (R.invoices || '')
          : '**Invoices** live under **Account → Invoices**: ' + (R.invoices || '')
      );
    }

    if (balKw) {
      var snap = ctx.customer_snapshot;
      if (snap && typeof snap.balance === 'number') {
        var amt = '$' + Number(snap.balance).toFixed(2);
        chunks.push(
          tpl(dyn.balance_line, {
            amount: amt,
            term: snap.term_name || '—',
            group: snap.group_name || '—'
          })
        );
        if (snap.discount != null && snap.discount > 0) {
          chunks.push(tpl(dyn.balance_discount, { disc: String(snap.discount) }));
        }
      } else {
        chunks.push(dyn.balance_unknown || '');
      }
    }

    if (stockKw || (ctx.show_inventory !== undefined && catHintKw)) {
      chunks.push(ctx.show_inventory ? dyn.stock_on || '' : dyn.stock_off || '');
    }

    if (cartKw) {
      chunks.push(
        lang === 'es'
          ? 'El **carrito** está en **Cuenta → Carrito** (vista principal del sitio): ' + (R.cart_db || '')
          : 'Your **cart** is under **Account → Cart** (main cart page): ' + (R.cart_db || '')
      );
    }

    if (msgKw) {
      chunks.push(
        lang === 'es'
          ? 'Los **mensajes** con tu equipo: **Cuenta → Mensajes**: ' + (R.messages || '')
          : '**Messages** with your team: **Account → Messages**: ' + (R.messages || '')
      );
    }

    if (matchedCat && Array.isArray(products)) {
      var tot = 0;
      var ins = 0;
      var outs = 0;
      products.forEach(function (p) {
        if (!productInCategory(p, matchedCat.id)) return;
        tot++;
        var n = stockNumber(p);
        if (n > 0) ins++;
        else outs++;
      });
      chunks.push(
        tpl(dyn.category_counts, {
          name: matchedCat.name,
          total: String(tot),
          inStock: String(ins),
          outStock: String(outs)
        })
      );
      chunks.push(lang === 'es' ? '**Catálogo:** ' + (R.catalog || '') : '**Catalog:** ' + (R.catalog || ''));
    } else if (catHintKw && !matchedCat) {
      var sample = (ctx.categories || [])
        .slice(0, 8)
        .map(function (c) {
          return c.name;
        })
        .join(', ');
      chunks.push(tpl(dyn.category_pick, { sample: sample || '—', url: R.catalog || '' }));
    }

    if (!orderKw && !invKw && !balKw && (qf.indexOf('catalog') !== -1 || qf.indexOf('catalogo') !== -1 || qf.indexOf('precio') !== -1 || qf.indexOf('price') !== -1)) {
      chunks.push(
        lang === 'es'
          ? 'El **catálogo** con tus precios: ' + (R.catalog || '')
          : 'The **catalog** with your pricing: ' + (R.catalog || '')
      );
    }

    var hasSpecific =
      orderKw ||
      invKw ||
      balKw ||
      cartKw ||
      msgKw ||
      !!matchedCat ||
      catHintKw ||
      qf.indexOf('catalog') !== -1 ||
      qf.indexOf('catalogo') !== -1 ||
      qf.indexOf('precio') !== -1 ||
      qf.indexOf('price') !== -1;
    var ranked = scoreDocSections(qf, D.doc_sections || []);
    if (ranked.length && (!hasSpecific || ranked[0].score >= 4)) {
      var top = ranked[0].sec;
      chunks.push((dyn.rag_title || '') + '\n**' + (top.title || '') + '**\n' + (top.body || ''));
    } else if (!chunks.length) {
      var fallback = (D.doc_sections || [])[0];
      if (fallback) {
        chunks.push((dyn.rag_title || '') + '\n**' + (fallback.title || '') + '**\n' + (fallback.body || ''));
      }
    }

    chunks.push(dyn.closing || '');

    return chunks
      .filter(function (c) {
        return String(c).trim() !== '';
      })
      .join('\n\n');
  }

  function runAskPipeline(text) {
    return fetchContext()
      .then(function (ctx) {
        var qf = fold(text);
        var matchedCat = findCategoryByQuery(qf, ctx.categories || []);
        var stockKw =
          qf.indexOf('stock') !== -1 ||
          qf.indexOf('inventario') !== -1 ||
          qf.indexOf('agotado') !== -1 ||
          qf.indexOf('disponible') !== -1;
        var catHintKw =
          qf.indexOf('categor') !== -1 || qf.indexOf('rubro') !== -1 || qf.indexOf('category') !== -1;
        var loadP = !!matchedCat || wantsProductData(qf);
        if (loadP) {
          return fetchProducts().then(function (products) {
            return buildAnswer(text, ctx, products);
          });
        }
        return buildAnswer(text, ctx, null);
      })
      .catch(function () {
        return null;
      });
  }

  var fab = document.getElementById('hv-support-fab-btn');
  var panel = document.getElementById('hv-support-panel');
  var closeBtn = document.getElementById('hv-support-close');
  var msgs = document.getElementById('hv-support-messages');
  var chips = document.getElementById('hv-support-chips');
  var formHuman = document.getElementById('hv-support-human-form');
  var ta = document.getElementById('hv-support-human-text');
  var sendTeam = document.getElementById('hv-support-send-team');
  var askInput = document.getElementById('hv-support-ask-input');
  var askSend = document.getElementById('hv-support-ask-send');
  var opened = false;
  var humanMode = false;

  function openPanel() {
    if (!panel || !msgs) return;
    panel.classList.remove('hidden');
    panel.setAttribute('aria-hidden', 'false');
    if (fab) fab.setAttribute('aria-expanded', 'true');
    if (!opened) {
      opened = true;
      appendBubble(msgs, buildGreeting(), 'bot');
      appendBubble(msgs, D.intro || '', 'bot');
      fetchContext().catch(function () {});
    }
  }

  function closePanel() {
    if (!panel) return;
    panel.classList.add('hidden');
    panel.setAttribute('aria-hidden', 'true');
    if (fab) fab.setAttribute('aria-expanded', 'false');
  }

  function showChips(show) {
    if (!chips) return;
    chips.style.display = show ? 'flex' : 'none';
  }

  function enterHumanMode() {
    humanMode = true;
    showChips(false);
    if (formHuman) formHuman.classList.remove('hidden');
    appendBubble(msgs, D.reply_human_prompt || '', 'bot');
    if (ta) ta.focus();
  }

  function onChip(key) {
    if (!msgs) return;
    if (key === 'orders') {
      appendBubble(msgs, D.chip_orders || '', 'user');
      appendBubble(msgs, D.reply_orders || '', 'bot');
    } else if (key === 'catalog') {
      appendBubble(msgs, D.chip_catalog || '', 'user');
      appendBubble(msgs, D.reply_catalog || '', 'bot');
    } else if (key === 'human') {
      appendBubble(msgs, D.chip_human || '', 'user');
      enterHumanMode();
    }
  }

  function postEscalate(message) {
    var url = base + '/api/support/escalate';
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        message: message,
        pageUrl: typeof location !== 'undefined' ? location.href : '',
        lang: lang
      })
    }).then(function (r) {
      return r.json().then(function (j) {
        if (!r.ok) throw new Error(j && j.error ? String(j.error) : 'http');
        return j;
      });
    });
  }

  function submitAsk() {
    if (!msgs || !askInput) return;
    var text = (askInput.value || '').trim();
    if (!text) {
      if (window.HV && typeof HV.toast === 'function') HV.toast(D.empty_ask || D.empty_message || '…', { icon: 'warning' });
      return;
    }
    appendBubble(msgs, text, 'user');
    askInput.value = '';
    if (askSend) askSend.disabled = true;
    runAskPipeline(text).then(function (reply) {
      if (!reply) {
        if (window.HV && typeof HV.toast === 'function') HV.toast(D.toast_context_error || D.toast_error || 'Error', { icon: 'error' });
        appendBubble(
          msgs,
          lang === 'es'
            ? 'No pude obtener datos ahora. Prueba otra vez o usa **Hablar con soporte**.'
            : 'Could not load data right now. Try again or use **Speak with support**.',
          'bot'
        );
      } else {
        appendBubble(msgs, reply, 'bot');
      }
      if (askSend) askSend.disabled = false;
    });
  }

  if (fab) {
    fab.addEventListener('click', function () {
      if (panel && !panel.classList.contains('hidden')) {
        closePanel();
      } else {
        openPanel();
      }
    });
  }
  if (closeBtn) closeBtn.addEventListener('click', closePanel);
  if (chips) {
    chips.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('button[data-hv-support-chip]');
      if (!b) return;
      onChip(b.getAttribute('data-hv-support-chip') || '');
    });
  }
  if (sendTeam && ta) {
    sendTeam.addEventListener('click', function () {
      var msg = (ta.value || '').trim();
      if (!msg) {
        if (window.HV && typeof HV.toast === 'function') HV.toast(D.empty_message || '…', { icon: 'warning' });
        return;
      }
      appendBubble(msgs, msg, 'user');
      ta.value = '';
      sendTeam.disabled = true;
      postEscalate(msg)
        .then(function () {
          if (window.HV && typeof HV.toast === 'function') HV.toast(D.toast_sent || 'OK', { icon: 'success' });
          closePanel();
        })
        .catch(function () {
          if (window.HV && typeof HV.toast === 'function') HV.toast(D.toast_error || 'Error', { icon: 'error' });
        })
        .finally(function () {
          sendTeam.disabled = false;
        });
    });
  }

  if (askSend && askInput) {
    askSend.addEventListener('click', submitAsk);
    askInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        submitAsk();
      }
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && panel && !panel.classList.contains('hidden')) {
      closePanel();
    }
  });
})();
