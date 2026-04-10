/**
 * Panel global de chats: lista, contactos y hilo dentro del mismo sidebar (sin redirigir).
 */
(function () {
  var base = (window.HV_BASE || '').replace(/\/?$/, '');
  var lang = window.HV_MSG_DRAWER_LANG || 'en';
  var dict = window.HV_MSG_DRAWER_DICT || {};
  var d = function (k) {
    var o = dict || {};
    return (o[k] != null && String(o[k]).trim() !== '') ? String(o[k]) : k;
  };

  var root = document.getElementById('hv-global-msg-root');
  var backdrop = document.getElementById('hv-global-msg-backdrop');
  var panel = document.getElementById('hv-global-msg-panel');
  var elList = document.getElementById('hv-global-msg-list');
  var elClose = document.getElementById('hv-global-msg-close');
  var elBack = document.getElementById('hv-global-msg-back');
  var elNew = document.getElementById('hv-global-msg-new');
  var elNewRow = document.getElementById('hv-global-msg-new-row');
  var elTitle = document.getElementById('hv-global-msg-title');
  var elTotalUnread = document.getElementById('hv-global-msg-total-unread');
  var elContactsList = document.getElementById('hv-global-msg-contacts-list');
  var elThreadWrap = document.getElementById('hv-global-msg-thread-wrap');
  var elThreadScroll = document.getElementById('hv-global-msg-thread-scroll');
  var elThreadMsgs = document.getElementById('hv-global-msg-thread-msgs');
  var elInput = document.getElementById('hv-global-msg-input');
  var elSend = document.getElementById('hv-global-msg-send');
  var elAttach = document.getElementById('hv-global-msg-attach');
  var elFile = document.getElementById('hv-global-msg-file');
  var elFooterRow = document.getElementById('hv-global-msg-footer-row');
  var elHeaderList = document.getElementById('hv-global-msg-header-list');
  var elHeaderThread = document.getElementById('hv-global-msg-header-thread');
  var elThreadAvatar = document.getElementById('hv-global-msg-thread-avatar');
  var elThreadPeerName = document.getElementById('hv-global-msg-thread-peer-name');
  var elThreadPeerSub = document.getElementById('hv-global-msg-thread-peer-sub');

  var messagesUrl = base + '/' + lang + '/account/messages';
  var chatsTitle = d('chats');

  if (!root || !panel || !elList || !elContactsList || !elThreadWrap || !elThreadMsgs) {
    window.HV_openMessagesDrawer = function () {
      window.location.href = messagesUrl;
    };
    window.HV_closeMessagesDrawer = function () {};
    return;
  }

  var conversations = [];
  var open = false;
  var contactsOpen = false;
  var threadOpen = false;

  var activeConvId = 0;
  var otherUserId = 0;
  var otherName = '';
  var otherRolId = 0;
  var otherUsername = '';
  var peerLastReadMessageId = 0;
  var messages = [];
  var maxMsgId = 0;
  var poll = null;

  var DOUBLE_CHECK_SVG_PAIR =
    '<svg class="hv-drawer-msg-checks-svg1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>' +
    '<svg class="hv-drawer-msg-checks-svg2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>';

  function fetchNL(url, init) {
    var o = init ? Object.assign({}, init) : {};
    o.credentials = o.credentials || 'same-origin';
    o.hvNoLoader = true;
    return fetch(url, o);
  }

  function applyMessagesPayload(data) {
    if (!data || typeof data !== 'object') return;
    var p = data.peerLastReadMessageId;
    if (p != null && p !== '') peerLastReadMessageId = parseInt(p, 10) || 0;
  }

  function syncHeaderThreadMode() {
    if (elHeaderList && elHeaderThread) {
      elHeaderList.classList.add('hidden');
      elHeaderThread.classList.remove('hidden');
      elHeaderThread.setAttribute('aria-hidden', 'false');
      updateThreadPeerHeader();
    } else if (elTitle) {
      elTitle.textContent = otherName || chatsTitle;
    }
  }

  function updateThreadPeerHeader() {
    if (elThreadAvatar) {
      elThreadAvatar.textContent = initialsFromName(otherName);
      var r = parseInt(otherRolId, 10) || 0;
      var mod = r === 2 ? 'hv-msg-drawer-avatar--seller' : r === 3 ? 'hv-msg-drawer-avatar--customer' : 'hv-msg-drawer-avatar--other';
      elThreadAvatar.className = 'hv-msg-drawer-avatar hv-msg-drawer-avatar--compact shrink-0 ' + mod;
    }
    if (elThreadPeerName) elThreadPeerName.textContent = otherName || '';
    if (elThreadPeerSub) {
      var u = String(otherUsername || '').trim();
      elThreadPeerSub.textContent = u;
      elThreadPeerSub.classList.toggle('hidden', !u);
    }
  }

  function syncHeaderListMode() {
    if (elHeaderList && elHeaderThread) {
      elHeaderList.classList.remove('hidden');
      elHeaderThread.classList.add('hidden');
      elHeaderThread.setAttribute('aria-hidden', 'true');
    }
  }

  function initialsFromName(name) {
    var s = String(name == null ? '' : name).trim();
    if (!s) return '?';
    var parts = s.split(/\s+/).filter(Boolean);
    if (parts.length >= 2) {
      var a = parts[0].charAt(0);
      var b = parts[parts.length - 1].charAt(0);
      return (a + b).toUpperCase();
    }
    return s.slice(0, 2).toUpperCase();
  }

  function avatarClassName(otherRolId) {
    var r = parseInt(otherRolId, 10) || 0;
    if (r === 2) return 'hv-msg-drawer-avatar hv-msg-drawer-avatar--seller';
    if (r === 3) return 'hv-msg-drawer-avatar hv-msg-drawer-avatar--customer';
    return 'hv-msg-drawer-avatar hv-msg-drawer-avatar--other';
  }

  function apiUrl(qs) {
    return base + '/api/messages' + (qs ? '?' + qs : '');
  }

  function parseJson(r) {
    return r.text().then(function (t) {
      var j = {};
      try {
        j = t && String(t).trim() ? JSON.parse(t) : {};
      } catch (e) {
        throw new Error('json');
      }
      if (!r.ok) throw new Error((j && j.error) ? j.error : 'http');
      return j;
    });
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/"/g, '&quot;');
  }

  function totalUnread() {
    return conversations.reduce(function (s, c) {
      return s + (parseInt(c.unread, 10) || 0);
    }, 0);
  }

  function updateTotalUnreadBadge() {
    if (!elTotalUnread) return;
    if (contactsOpen || threadOpen) {
      elTotalUnread.classList.remove('hv-global-msg-header-badge--visible');
      return;
    }
    var n = totalUnread();
    if (n > 0) {
      elTotalUnread.textContent = n > 99 ? '99+' : String(n);
      elTotalUnread.classList.add('hv-global-msg-header-badge--visible');
    } else {
      elTotalUnread.textContent = '';
      elTotalUnread.classList.remove('hv-global-msg-header-badge--visible');
    }
  }

  function stopPoll() {
    if (poll) {
      clearInterval(poll);
      poll = null;
    }
  }

  function startPoll() {
    stopPoll();
    poll = setInterval(function () {
      if (!activeConvId) return;
      fetchMessages(activeConvId, maxMsgId).then(function (data) {
        var prevPeer = peerLastReadMessageId;
        applyMessagesPayload(data);
        var incoming = data.messages || [];
        var peerChanged = peerLastReadMessageId !== prevPeer;
        if (incoming.length) {
          incoming.forEach(function (m) {
            messages.push(m);
            if (m.id > maxMsgId) maxMsgId = m.id;
          });
          postRead(activeConvId, maxMsgId);
        }
        if (incoming.length || peerChanged) renderThreadMessages();
      }).catch(function () {});
      fetchNL(apiUrl('lang=' + encodeURIComponent(lang)))
        .then(parseJson)
        .then(function (data) {
          conversations = data.conversations || [];
          if (!threadOpen && !contactsOpen) renderList();
          else updateTotalUnreadBadge();
        })
        .catch(function () {});
    }, 4000);
  }

  function fetchMessages(convId, after) {
    var q = 'conversation=' + encodeURIComponent(String(convId)) + '&lang=' + encodeURIComponent(lang);
    if (after > 0) q += '&after=' + encodeURIComponent(String(after));
    return fetchNL(apiUrl(q)).then(parseJson);
  }

  function postRead(convId, messageId) {
    return fetchNL(base + '/api/messages/read', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ conversationId: convId, messageId: messageId })
    }).then(parseJson).catch(function () {});
  }

  function postSend(body) {
    return fetchNL(base + '/api/messages', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ lang: lang }, body))
    }).then(parseJson);
  }

  function scrollThreadBottom() {
    if (!elThreadScroll) return;
    elThreadScroll.scrollTop = elThreadScroll.scrollHeight;
  }

  function fmtTime(iso) {
    if (!iso) return '';
    var dt = new Date(iso.replace(' ', 'T'));
    if (isNaN(dt.getTime())) return '';
    var now = new Date();
    var sameDay = dt.toDateString() === now.toDateString();
    if (sameDay) {
      return dt.toLocaleTimeString(lang === 'es' ? 'es' : 'en-US', { hour: '2-digit', minute: '2-digit' });
    }
    return dt.toLocaleDateString(lang === 'es' ? 'es' : 'en-US', { month: 'short', day: 'numeric' }) + ' ' +
      dt.toLocaleTimeString(lang === 'es' ? 'es' : 'en-US', { hour: '2-digit', minute: '2-digit' });
  }

  function renderThreadMessages() {
    elThreadMsgs.innerHTML = '';
    messages.forEach(function (m) {
      var row = document.createElement('div');
      row.className = 'hv-drawer-msg-row' + (m.mine ? ' hv-drawer-msg-row--mine' : '');
      var bubble = document.createElement('div');
      bubble.className = 'hv-drawer-msg-bubble ' + (m.mine ? 'hv-drawer-msg-bubble--mine' : 'hv-drawer-msg-bubble--them');

      if (m.type === 'image' && m.fileUrl) {
        var img = document.createElement('img');
        img.src = m.fileUrl;
        img.alt = d('image');
        img.loading = 'lazy';
        bubble.appendChild(img);
      } else if (m.type === 'video' && m.fileUrl) {
        var vid = document.createElement('video');
        vid.src = m.fileUrl;
        vid.controls = true;
        bubble.appendChild(vid);
      } else if (m.type === 'audio' && m.fileUrl) {
        var aud = document.createElement('audio');
        aud.src = m.fileUrl;
        aud.controls = true;
        bubble.appendChild(aud);
      } else if (m.type === 'file' && m.fileUrl) {
        var lk = document.createElement('a');
        lk.href = m.fileUrl;
        lk.target = '_blank';
        lk.rel = 'noopener';
        lk.className = 'text-emerald-800 font-medium underline break-all';
        lk.textContent = m.fileName || d('download');
        bubble.appendChild(lk);
      }

      if (m.body) {
        var p = document.createElement('p');
        p.className = (m.type !== 'text' && m.body) ? 'mt-2 whitespace-pre-wrap break-words' : 'whitespace-pre-wrap break-words';
        p.textContent = m.body;
        bubble.appendChild(p);
      }

      var meta = document.createElement('div');
      meta.className = 'hv-drawer-msg-meta' + (m.mine ? '' : ' hv-drawer-msg-meta--them');
      var timeSpan = document.createElement('span');
      timeSpan.className = 'hv-drawer-msg-time-inline';
      timeSpan.textContent = fmtTime(m.createdAt);
      meta.appendChild(timeSpan);
      if (m.mine) {
        var read = activeConvId > 0 && peerLastReadMessageId > 0 && m.id <= peerLastReadMessageId;
        var chk = document.createElement('span');
        chk.className = 'hv-drawer-msg-checks ' + (read ? 'hv-drawer-msg-checks--read' : 'hv-drawer-msg-checks--sent');
        chk.setAttribute('aria-hidden', 'true');
        chk.innerHTML = DOUBLE_CHECK_SVG_PAIR;
        meta.appendChild(chk);
      }
      bubble.appendChild(meta);

      row.appendChild(bubble);
      elThreadMsgs.appendChild(row);
    });
    scrollThreadBottom();
  }

  function showThreadChrome() {
    threadOpen = true;
    contactsOpen = false;
    elList.classList.add('hidden');
    elList.setAttribute('aria-hidden', 'true');
    elContactsList.classList.add('hidden');
    elContactsList.setAttribute('aria-hidden', 'true');
    elContactsList.innerHTML = '';
    if (elBack) elBack.classList.remove('hidden');
    syncHeaderThreadMode();
    if (elNewRow) elNewRow.classList.add('hidden');
    if (elFooterRow) elFooterRow.classList.add('hidden');
    elThreadWrap.classList.add('hv-global-msg-thread-wrap--open');
    elThreadWrap.setAttribute('aria-hidden', 'false');
    updateTotalUnreadBadge();
  }

  function hideThreadView() {
    stopPoll();
    threadOpen = false;
    activeConvId = 0;
    otherUserId = 0;
    otherName = '';
    otherRolId = 0;
    otherUsername = '';
    peerLastReadMessageId = 0;
    messages = [];
    maxMsgId = 0;
    elThreadMsgs.innerHTML = '';
    if (elInput) elInput.value = '';
    elThreadWrap.classList.remove('hv-global-msg-thread-wrap--open');
    elThreadWrap.setAttribute('aria-hidden', 'true');
    syncHeaderListMode();
    if (!contactsOpen) {
      elList.classList.remove('hidden');
      elList.setAttribute('aria-hidden', 'false');
      if (elTitle) elTitle.textContent = chatsTitle;
      if (elBack) elBack.classList.add('hidden');
      if (elNewRow) elNewRow.classList.remove('hidden');
      if (elFooterRow) elFooterRow.classList.remove('hidden');
    }
    updateTotalUnreadBadge();
  }

  function openThread(convId, otherId, name, rolId, username) {
    stopPoll();
    activeConvId = convId;
    otherUserId = otherId || 0;
    otherName = name || '';
    otherRolId = rolId != null ? parseInt(rolId, 10) || 0 : 0;
    otherUsername = username != null ? String(username) : '';
    peerLastReadMessageId = 0;
    messages = [];
    maxMsgId = 0;
    showThreadChrome();
    renderThreadMessages();

    if (convId > 0) {
      fetchMessages(convId, 0).then(function (data) {
        applyMessagesPayload(data);
        messages = data.messages || [];
        messages.forEach(function (m) {
          if (m.id > maxMsgId) maxMsgId = m.id;
        });
        renderThreadMessages();
        if (maxMsgId > 0) postRead(convId, maxMsgId);
        return fetchNL(apiUrl('lang=' + encodeURIComponent(lang))).then(parseJson);
      }).then(function (data) {
        conversations = data.conversations || [];
        renderList();
        startPoll();
      }).catch(function () {
        if (window.HV && HV.toast) HV.toast(dict._err || 'Error', { icon: 'error' });
      });
    } else {
      fetchNL(apiUrl('lang=' + encodeURIComponent(lang)))
        .then(parseJson)
        .then(function (data) {
          conversations = data.conversations || [];
          renderList();
        })
        .catch(function () {});
    }
  }

  function renderList() {
    elList.innerHTML = '';
    if (!conversations.length) {
      elList.innerHTML = '<p class="text-sm text-gray-500 px-3 py-6 text-center">' + esc(d('no_chats')) + '</p>';
      updateTotalUnreadBadge();
      return;
    }
    conversations.forEach(function (c) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'hv-msg-drawer-row w-full text-left px-3 py-3.5 border-b border-gray-100 flex gap-3 items-center min-w-0';
      var unread = parseInt(c.unread, 10) || 0;
      var initials = initialsFromName(c.otherName);
      var av = avatarClassName(c.otherRolId);
      var unreadLine = unread > 0
        ? '<span class="text-xs text-red-700 font-semibold">' + esc(String(unread)) + ' ' + esc(d('unread')) + '</span>'
        : '';
      var lastAt = c.lastMessageAt ? fmtTime(c.lastMessageAt) : '';
      var lastAtHtml = lastAt
        ? '<span class="hv-msg-drawer-last-at" title="' + esc(lastAt) + '">' + esc(lastAt) + '</span>'
        : '';
      btn.innerHTML =
        '<span class="' + av + '">' + esc(initials) + '</span>' +
        '<span class="min-w-0 flex-1">' +
        '<span class="hv-msg-drawer-list-topline">' +
        '<span class="hv-msg-drawer-name hv-msg-drawer-name--row">' + esc(c.otherName || '') + '</span>' +
        lastAtHtml +
        '</span>' +
        '<span class="hv-msg-drawer-subtitle">' + esc(c.lastPreview || '') + '</span>' +
        (unreadLine ? '<span class="block mt-1">' + unreadLine + '</span>' : '') +
        '</span>' +
        (unread > 0
          ? '<span class="flex-shrink-0 min-w-[26px] h-[26px] rounded-full bg-red-600 text-white text-xs font-bold flex items-center justify-center px-1 shadow-sm">' +
            esc(String(unread > 99 ? '99+' : unread)) + '</span>'
          : '');
      btn.addEventListener('click', function () {
        openThread(c.conversationId, c.otherUserId, c.otherName, c.otherRolId, c.otherUsername);
      });
      elList.appendChild(btn);
    });
    updateTotalUnreadBadge();
  }

  function loadConversations() {
    return fetchNL(apiUrl('lang=' + encodeURIComponent(lang)))
      .then(parseJson)
      .then(function (data) {
        conversations = data.conversations || [];
        renderList();
      })
      .catch(function () {
        elList.innerHTML = '<p class="text-sm text-red-600 px-3 py-4 text-center">' + esc(d('_err')) + '</p>';
      });
  }

  function hideContactsView() {
    contactsOpen = false;
    if (elTitle && !threadOpen) elTitle.textContent = chatsTitle;
    if (elNewRow && !threadOpen) elNewRow.classList.remove('hidden');
    elContactsList.classList.add('hidden');
    elContactsList.setAttribute('aria-hidden', 'true');
    elContactsList.innerHTML = '';
    elList.classList.remove('hidden');
    elList.setAttribute('aria-hidden', 'false');
    if (elBack && !threadOpen) elBack.classList.add('hidden');
    updateTotalUnreadBadge();
  }

  function showContactsView() {
    contactsOpen = true;
    hideThreadView();
    if (elBack) elBack.classList.remove('hidden');
    if (elTitle) elTitle.textContent = d('pick_contact');
    if (elTotalUnread) {
      elTotalUnread.classList.remove('hv-global-msg-header-badge--visible');
    }
    if (elNewRow) elNewRow.classList.add('hidden');
    elList.classList.add('hidden');
    elList.setAttribute('aria-hidden', 'true');
    elContactsList.classList.remove('hidden');
    elContactsList.setAttribute('aria-hidden', 'false');
    elContactsList.innerHTML = '<p class="text-sm text-gray-500 py-6 text-center px-3">' + esc(d('loading')) + '</p>';
  }

  function fetchContacts() {
    return fetchNL(apiUrl('contacts=1&lang=' + encodeURIComponent(lang))).then(parseJson);
  }

  function renderContactRows(contacts) {
    elContactsList.innerHTML = '';
    if (!contacts.length) {
      elContactsList.innerHTML = '<p class="text-sm text-gray-500 py-6 text-center px-3">' + esc(d('pick_contact')) + '</p>';
      return;
    }
    contacts.forEach(function (c) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'hv-msg-drawer-row w-full text-left px-3 py-3.5 border-b border-gray-100 flex gap-3 items-center min-w-0';
      var initials = initialsFromName(c.display);
      var av = avatarClassName(c.rolId);
      var sub = String(c.username || '').trim();
      b.innerHTML =
        '<span class="' + av + '">' + esc(initials) + '</span>' +
        '<span class="min-w-0 flex-1">' +
        '<span class="hv-msg-drawer-name">' + esc(c.display || '') + '</span>' +
        (sub ? '<span class="hv-msg-drawer-subtitle">' + esc(sub) + '</span>' : '') +
        '</span>';
      b.addEventListener('click', function () {
        hideContactsView();
        openThread(0, c.userId, c.display, c.rolId, c.username);
      });
      elContactsList.appendChild(b);
    });
  }

  function openContactsInSidebar() {
    showContactsView();
    fetchContacts()
      .then(function (data) {
        renderContactRows(data.contacts || []);
      })
      .catch(function () {
        elContactsList.innerHTML = '<p class="text-sm text-red-600 px-3 py-4 text-center">' + esc(d('_err')) + '</p>';
      });
  }

  function sendText() {
    var txt = (elInput && elInput.value) ? elInput.value.trim() : '';
    if (!txt) return;
    var payload = { body: txt };
    if (activeConvId > 0) payload.conversationId = activeConvId;
    else if (otherUserId > 0) payload.otherUserId = otherUserId;
    else return;
    postSend(payload).then(function (res) {
      if (elInput) elInput.value = '';
      var msg = res.message;
      if (msg) {
        if (!activeConvId && msg.conversationId) {
          activeConvId = msg.conversationId;
          startPoll();
        }
        messages.push(msg);
        if (msg.id > maxMsgId) maxMsgId = msg.id;
        renderThreadMessages();
        postRead(activeConvId, maxMsgId);
      }
      return fetchNL(apiUrl('lang=' + encodeURIComponent(lang))).then(parseJson);
    }).then(function (data) {
      conversations = data.conversations || [];
      if (!threadOpen && !contactsOpen) renderList();
      else updateTotalUnreadBadge();
    }).catch(function (e) {
      if (window.HV && HV.toast) HV.toast(String(e.message || dict._err || 'Error'), { icon: 'error' });
    });
  }

  function uploadFile(file) {
    if (!file || (!activeConvId && !otherUserId)) {
      if (window.HV && HV.toast) HV.toast(d('pick_contact'), { icon: 'warning' });
      return;
    }
    var fd = new FormData();
    fd.append('file', file);
    fd.append('lang', lang);
    if (activeConvId > 0) fd.append('conversationId', String(activeConvId));
    if (otherUserId > 0) fd.append('otherUserId', String(otherUserId));
    fetchNL(base + '/api/messages/upload', { method: 'POST', body: fd })
      .then(parseJson)
      .then(function (res) {
        var msg = res.message;
        if (msg) {
          if (!activeConvId && msg.conversationId) {
            activeConvId = msg.conversationId;
            startPoll();
          }
          messages.push(msg);
          if (msg.id > maxMsgId) maxMsgId = msg.id;
          renderThreadMessages();
          postRead(activeConvId, maxMsgId);
        }
        return fetchNL(apiUrl('lang=' + encodeURIComponent(lang))).then(parseJson);
      })
      .then(function (data) {
        conversations = data.conversations || [];
        if (!threadOpen && !contactsOpen) renderList();
        else updateTotalUnreadBadge();
      })
      .catch(function (e) {
        if (window.HV && HV.toast) HV.toast(String(e.message || dict._err || 'Error'), { icon: 'error' });
      });
  }

  function openDrawer() {
    if (open) return;
    open = true;
    hideThreadView();
    hideContactsView();
    root.classList.add('hv-global-msg-open');
    root.setAttribute('aria-hidden', 'false');
    panel.setAttribute('aria-hidden', 'false');
    backdrop.setAttribute('aria-hidden', 'false');
    document.body.classList.add('overflow-hidden');
    loadConversations();
  }

  function closeDrawer() {
    if (!open) return;
    hideThreadView();
    hideContactsView();
    open = false;
    root.classList.remove('hv-global-msg-open');
    root.setAttribute('aria-hidden', 'true');
    panel.setAttribute('aria-hidden', 'true');
    backdrop.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('overflow-hidden');
  }

  window.HV_openMessagesDrawer = function () {
    if (open) {
      closeDrawer();
      return;
    }
    openDrawer();
  };
  window.HV_closeMessagesDrawer = closeDrawer;

  if (elClose) elClose.addEventListener('click', closeDrawer);
  if (backdrop) backdrop.addEventListener('click', closeDrawer);
  if (elNew) elNew.addEventListener('click', openContactsInSidebar);
  if (elBack) {
    elBack.addEventListener('click', function () {
      if (threadOpen) hideThreadView();
      else hideContactsView();
    });
  }
  if (elSend) elSend.addEventListener('click', sendText);
  if (elInput) {
    elInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendText();
      }
    });
  }
  if (elAttach && elFile) {
    elAttach.addEventListener('click', function () { elFile.click(); });
    elFile.addEventListener('change', function () {
      if (elFile.files && elFile.files[0]) uploadFile(elFile.files[0]);
      elFile.value = '';
    });
  }

  document.addEventListener('keydown', function (e) {
    if (!open) return;
    if (e.key === 'Escape') {
      if (threadOpen) hideThreadView();
      else if (contactsOpen) hideContactsView();
      else closeDrawer();
    }
  });

  document.addEventListener('hv-refresh-messages-drawer', function () {
    if (open && !contactsOpen && !threadOpen) loadConversations();
  });
})();
