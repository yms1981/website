/**
 * Mensajería: panel derecho con lista de conversaciones (drawer) + hilo.
 * Se abre al cargar la página y al pulsar el icono de mensajes si ya estás aquí.
 */
(function () {
  var base = (window.HV_BASE || '').replace(/\/?$/, '');
  var lang = window.HV_MSG_LANG || 'en';
  var dict = window.HV_MSG_DICT || {};
  var d = function (k) {
    var o = dict || {};
    return (o[k] != null && String(o[k]).trim() !== '') ? String(o[k]) : k;
  };

  var elList = document.getElementById('hv-msg-conv-list');
  var elThread = document.getElementById('hv-msg-thread');
  var elThreadScroll = document.getElementById('hv-msg-thread-scroll');
  var elPlaceholder = document.getElementById('hv-msg-thread-placeholder');
  var elTitle = document.getElementById('hv-msg-thread-title');
  var elSub = document.getElementById('hv-msg-thread-sub');
  var elInput = document.getElementById('hv-msg-input');
  var elSend = document.getElementById('hv-msg-send');
  var elAttach = document.getElementById('hv-msg-attach');
  var elFile = document.getElementById('hv-msg-file');
  var elNewBtn = document.getElementById('hv-msg-new');
  var elContacts = document.getElementById('hv-msg-contacts');
  var elContactsPanel = document.getElementById('hv-msg-contacts-panel');
  var elShell = document.getElementById('hv-msg-shell');
  var elRecord = document.getElementById('hv-msg-record');
  var elBackdrop = document.getElementById('hv-msg-sidebar-backdrop');
  var elToggle = document.getElementById('hv-msg-toggle-sidebar');
  var elToggleLabel = document.getElementById('hv-msg-toggle-label');
  var elToggleUnread = document.getElementById('hv-msg-toggle-unread');
  var elListUnread = document.getElementById('hv-msg-list-unread');
  var elSidebarClose = document.getElementById('hv-msg-sidebar-close');
  var elSidebar = document.getElementById('hv-msg-sidebar');

  var threadIdleTitle = d('thread_idle') || '';

  var state = {
    conversations: [],
    contacts: [],
    activeConvId: 0,
    otherUserId: 0,
    otherName: '',
    messages: [],
    poll: null,
    maxMsgId: 0,
    peerLastReadMessageId: 0,
    mediaRecorder: null,
    recordChunks: []
  };

  var DOUBLE_CHECK_SVG_PAIR =
    '<svg class="hv-drawer-msg-checks-svg1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>' +
    '<svg class="hv-drawer-msg-checks-svg2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>';

  function apiUrl(qs) {
    return base + '/api/messages' + (qs ? '?' + qs : '');
  }

  function fetchNL(url, init) {
    var o = init ? Object.assign({}, init) : {};
    o.credentials = o.credentials || 'same-origin';
    o.hvNoLoader = true;
    return fetch(url, o);
  }

  function applyMessagesPayload(data) {
    if (!data || typeof data !== 'object') return;
    var p = data.peerLastReadMessageId;
    if (p != null && p !== '') state.peerLastReadMessageId = parseInt(p, 10) || 0;
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

  function fetchConversations() {
    return fetchNL(apiUrl('lang=' + encodeURIComponent(lang))).then(parseJson);
  }

  function fetchMessages(convId, after) {
    var q = 'conversation=' + encodeURIComponent(String(convId)) + '&lang=' + encodeURIComponent(lang);
    if (after > 0) q += '&after=' + encodeURIComponent(String(after));
    return fetchNL(apiUrl(q)).then(parseJson);
  }

  function fetchContacts() {
    return fetchNL(apiUrl('contacts=1&lang=' + encodeURIComponent(lang))).then(parseJson);
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

  function sidebarIsOpen() {
    return elShell && elShell.classList.contains('hv-msg-sidebar-open');
  }

  function setSidebarOpen(open) {
    if (!elShell) return;
    elShell.classList.toggle('hv-msg-sidebar-open', !!open);
    if (elToggle) elToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (elToggleLabel) elToggleLabel.textContent = d('show_chats');
    if (elSidebar) elSidebar.setAttribute('aria-hidden', open ? 'false' : 'true');
    if (elBackdrop) elBackdrop.setAttribute('aria-hidden', open ? 'false' : 'true');
  }

  function toggleSidebar() {
    setSidebarOpen(!sidebarIsOpen());
  }

  function closeSidebar() {
    setSidebarOpen(false);
  }

  function totalUnread() {
    return state.conversations.reduce(function (s, c) {
      return s + (parseInt(c.unread, 10) || 0);
    }, 0);
  }

  function applyUnreadBadge(el) {
    if (!el) return;
    var n = totalUnread();
    if (n > 0) {
      el.textContent = n > 99 ? '99+' : String(n);
      el.classList.remove('hidden');
      el.classList.add('inline-flex');
    } else {
      el.textContent = '';
      el.classList.add('hidden');
      el.classList.remove('inline-flex');
    }
  }

  function updateUnreadBadges() {
    applyUnreadBadge(elToggleUnread);
    applyUnreadBadge(elListUnread);
  }

  function updatePlaceholder() {
    var idle = state.activeConvId <= 0 && state.otherUserId <= 0;
    if (elPlaceholder) elPlaceholder.classList.toggle('hidden', !idle);
    if (idle && elThread) elThread.innerHTML = '';
    if (idle && elTitle) elTitle.textContent = threadIdleTitle;
    if (idle && elSub) elSub.textContent = '';
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

  function renderConversations() {
    if (!elList) return;
    elList.innerHTML = '';
    if (!state.conversations.length) {
      elList.innerHTML = '<p class="text-sm text-gray-500 px-3 py-6 text-center">' + esc(d('no_chats')) + '</p>';
      updateUnreadBadges();
      return;
    }
    state.conversations.forEach(function (c) {
      var a = document.createElement('button');
      a.type = 'button';
      a.className = 'w-full text-left px-3 py-3 border-b border-gray-100 hover:bg-gray-50 flex gap-3 items-center min-w-0 ' +
        (state.activeConvId === c.conversationId ? 'bg-emerald-50/90' : '');
      var unread = parseInt(c.unread, 10) || 0;
      var initials = (c.otherName || '?').trim().slice(0, 2).toUpperCase();
      var unreadLine = unread > 0
        ? '<span class="text-xs text-red-700 font-semibold">' + esc(String(unread)) + ' ' + esc(d('unread')) + '</span>'
        : '';
      var lastAt = c.lastMessageAt ? fmtTime(c.lastMessageAt) : '';
      var lastAtHtml = lastAt
        ? '<span class="flex-shrink-0 text-xs text-gray-400 whitespace-nowrap ml-2" title="' + esc(lastAt) + '">' + esc(lastAt) + '</span>'
        : '';
      a.innerHTML =
        '<span class="flex-shrink-0 w-11 h-11 rounded-full bg-emerald-600 text-white text-xs font-bold flex items-center justify-center">' + esc(initials) + '</span>' +
        '<span class="min-w-0 flex-1">' +
        '<span class="flex items-baseline justify-between gap-2 min-w-0">' +
        '<span class="font-semibold text-gray-900 truncate min-w-0">' + esc(c.otherName || '') + '</span>' +
        lastAtHtml +
        '</span>' +
        '<span class="text-xs text-gray-500 truncate block mt-0.5">' + esc(c.lastPreview || '') + '</span>' +
        (unreadLine ? '<span class="block mt-1">' + unreadLine + '</span>' : '') +
        '</span>' +
        (unread > 0
          ? '<span class="flex-shrink-0 min-w-[26px] h-[26px] rounded-full bg-red-600 text-white text-xs font-bold flex items-center justify-center px-1 shadow-sm">' +
            esc(String(unread > 99 ? '99+' : unread)) + '</span>'
          : '');
      a.addEventListener('click', function () {
        openThread(c.conversationId, c.otherUserId, c.otherName);
      });
      elList.appendChild(a);
    });
    updateUnreadBadges();
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/"/g, '&quot;');
  }

  function renderMessages() {
    if (!elThread) return;
    elThread.innerHTML = '';
    state.messages.forEach(function (m) {
      var wrap = document.createElement('div');
      wrap.className = 'flex ' + (m.mine ? 'justify-end' : 'justify-start') + ' mb-2';
      var bubble = document.createElement('div');
      bubble.className = 'max-w-[85%] rounded-2xl px-3 py-2 shadow-sm ' +
        (m.mine ? 'bg-emerald-100 text-gray-900 rounded-br-md' : 'bg-white border border-gray-200 text-gray-900 rounded-bl-md');

      if (m.type === 'image' && m.fileUrl) {
        var img = document.createElement('img');
        img.src = m.fileUrl;
        img.alt = d('image');
        img.className = 'max-w-full rounded-lg max-h-64 object-contain cursor-pointer';
        img.loading = 'lazy';
        bubble.appendChild(img);
      } else if (m.type === 'video' && m.fileUrl) {
        var vid = document.createElement('video');
        vid.src = m.fileUrl;
        vid.controls = true;
        vid.className = 'max-w-full rounded-lg max-h-64';
        bubble.appendChild(vid);
      } else if (m.type === 'audio' && m.fileUrl) {
        var aud = document.createElement('audio');
        aud.src = m.fileUrl;
        aud.controls = true;
        aud.className = 'w-full max-w-xs';
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
        p.className = 'text-sm whitespace-pre-wrap break-words ' + ((m.type !== 'text' && m.body) ? 'mt-2' : '');
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
        var read = state.activeConvId > 0 && state.peerLastReadMessageId > 0 && m.id <= state.peerLastReadMessageId;
        var chk = document.createElement('span');
        chk.className = 'hv-drawer-msg-checks ' + (read ? 'hv-drawer-msg-checks--read' : 'hv-drawer-msg-checks--sent');
        chk.setAttribute('aria-hidden', 'true');
        chk.innerHTML = DOUBLE_CHECK_SVG_PAIR;
        meta.appendChild(chk);
      }
      bubble.appendChild(meta);

      wrap.appendChild(bubble);
      elThread.appendChild(wrap);
    });
    scrollThreadBottom();
  }

  function openThread(convId, otherId, otherName) {
    state.activeConvId = convId;
    state.otherUserId = otherId;
    state.otherName = otherName || '';
    state.messages = [];
    state.maxMsgId = 0;
    state.peerLastReadMessageId = 0;
    updatePlaceholder();
    if (elTitle) elTitle.textContent = state.otherName || threadIdleTitle;
    if (elSub) elSub.textContent = '';
    closeSidebar();
    stopPoll();
    fetchMessages(convId, 0).then(function (data) {
      applyMessagesPayload(data);
      state.messages = data.messages || [];
      state.messages.forEach(function (m) {
        if (m.id > state.maxMsgId) state.maxMsgId = m.id;
      });
      renderMessages();
      if (state.maxMsgId > 0) postRead(convId, state.maxMsgId);
      renderConversations();
      startPoll();
    }).catch(function () {
      if (window.HV && HV.toast) HV.toast(dict._err || 'Error', { icon: 'error' });
    });
    if (elContactsPanel) elContactsPanel.classList.add('hidden');
  }

  function startPoll() {
    stopPoll();
    state.poll = setInterval(function () {
      if (!state.activeConvId) return;
      fetchMessages(state.activeConvId, state.maxMsgId).then(function (data) {
        var prevPeer = state.peerLastReadMessageId;
        applyMessagesPayload(data);
        var incoming = data.messages || [];
        var peerChanged = state.peerLastReadMessageId !== prevPeer;
        if (incoming.length) {
          incoming.forEach(function (m) {
            state.messages.push(m);
            if (m.id > state.maxMsgId) state.maxMsgId = m.id;
          });
          postRead(state.activeConvId, state.maxMsgId);
        }
        if (incoming.length || peerChanged) renderMessages();
      }).catch(function () {});
      fetchConversations().then(function (data) {
        state.conversations = data.conversations || [];
        renderConversations();
      }).catch(function () {});
    }, 4000);
  }

  function stopPoll() {
    if (state.poll) {
      clearInterval(state.poll);
      state.poll = null;
    }
  }

  function sendText() {
    var txt = (elInput && elInput.value) ? elInput.value.trim() : '';
    if (!txt) return;
    var payload = { body: txt };
    if (state.activeConvId > 0) payload.conversationId = state.activeConvId;
    else if (state.otherUserId > 0) payload.otherUserId = state.otherUserId;
    else return;
    postSend(payload).then(function (res) {
      if (elInput) elInput.value = '';
      var msg = res.message;
      if (msg) {
        if (!state.activeConvId && msg.conversationId) {
          state.activeConvId = msg.conversationId;
          startPoll();
        }
        state.messages.push(msg);
        if (msg.id > state.maxMsgId) state.maxMsgId = msg.id;
        renderMessages();
        postRead(state.activeConvId, state.maxMsgId);
      }
      return fetchConversations();
    }).then(function (data) {
      state.conversations = data.conversations || [];
      renderConversations();
    }).catch(function (e) {
      if (window.HV && HV.toast) HV.toast(String(e.message || dict._err || 'Error'), { icon: 'error' });
    });
  }

  function uploadFile(file) {
    if (!file || !state.activeConvId && !state.otherUserId) {
      if (window.HV && HV.toast) HV.toast(d('pick_contact'), { icon: 'warning' });
      return;
    }
    var fd = new FormData();
    fd.append('file', file);
    fd.append('lang', lang);
    if (state.activeConvId > 0) fd.append('conversationId', String(state.activeConvId));
    if (state.otherUserId > 0) fd.append('otherUserId', String(state.otherUserId));
    fetchNL(base + '/api/messages/upload', { method: 'POST', body: fd })
      .then(parseJson)
      .then(function (res) {
        var msg = res.message;
        if (msg) {
          if (!state.activeConvId && msg.conversationId) {
            state.activeConvId = msg.conversationId;
            startPoll();
          }
          state.messages.push(msg);
          if (msg.id > state.maxMsgId) state.maxMsgId = msg.id;
          renderMessages();
          postRead(state.activeConvId, state.maxMsgId);
        }
        return fetchConversations();
      })
      .then(function (data) {
        state.conversations = data.conversations || [];
        renderConversations();
      })
      .catch(function (e) {
        if (window.HV && HV.toast) HV.toast(String(e.message || dict._err || 'Error'), { icon: 'error' });
      });
  }

  function showContacts() {
    if (!elContactsPanel) return;
    elContactsPanel.classList.remove('hidden');
    fetchContacts().then(function (data) {
      state.contacts = data.contacts || [];
      elContacts.innerHTML = '';
      if (!state.contacts.length) {
        elContacts.innerHTML = '<p class="text-sm text-gray-500 py-4 text-center">' + esc(d('pick_contact')) + '</p>';
        return;
      }
      state.contacts.forEach(function (c) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'w-full text-left px-3 py-3 border-b border-gray-100 hover:bg-gray-50 flex gap-3 items-center';
        var initials = (c.display || '?').trim().slice(0, 2).toUpperCase();
        b.innerHTML =
          '<span class="flex-shrink-0 w-10 h-10 rounded-full bg-gray-700 text-white text-xs font-bold flex items-center justify-center">' + esc(initials) + '</span>' +
          '<span><span class="font-medium text-gray-900">' + esc(c.display) + '</span>' +
          '<span class="block text-xs text-gray-500">' + esc(c.username) + '</span></span>';
        b.addEventListener('click', function () {
          state.otherUserId = c.userId;
          state.otherName = c.display;
          state.activeConvId = 0;
          state.messages = [];
          state.maxMsgId = 0;
          if (elTitle) elTitle.textContent = state.otherName;
          if (elSub) elSub.textContent = d('type_message');
          updatePlaceholder();
          renderMessages();
          elContactsPanel.classList.add('hidden');
          closeSidebar();
          stopPoll();
          startPoll();
        });
        elContacts.appendChild(b);
      });
    }).catch(function () {});
  }

  document.addEventListener('hv-messages-toggle-sidebar', function () {
    toggleSidebar();
  });

  if (elToggle) elToggle.addEventListener('click', toggleSidebar);
  if (elBackdrop) elBackdrop.addEventListener('click', closeSidebar);
  if (elSidebarClose) elSidebarClose.addEventListener('click', closeSidebar);

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && sidebarIsOpen()) closeSidebar();
  });

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
  if (elNewBtn) elNewBtn.addEventListener('click', showContacts);
  var elContactsClose = document.getElementById('hv-msg-contacts-close');
  if (elContactsClose && elContactsPanel) {
    elContactsClose.addEventListener('click', function () {
      elContactsPanel.classList.add('hidden');
    });
    elContactsPanel.addEventListener('click', function (e) {
      if (e.target === elContactsPanel) elContactsPanel.classList.add('hidden');
    });
  }

  function startRecorder() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return;
    state.recordChunks = [];
    navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
      var MR = window.MediaRecorder;
      if (!MR) {
        stream.getTracks().forEach(function (t) { t.stop(); });
        return;
      }
      state.mediaRecorder = new MR(stream, { mimeType: MR.isTypeSupported('audio/webm') ? 'audio/webm' : 'audio/mp4' });
      state.mediaRecorder.ondataavailable = function (e) {
        if (e.data && e.data.size > 0) state.recordChunks.push(e.data);
      };
      state.mediaRecorder.onstop = function () {
        stream.getTracks().forEach(function (t) { t.stop(); });
        var blob = new Blob(state.recordChunks, { type: state.mediaRecorder.mimeType || 'audio/webm' });
        if (blob.size > 0) {
          var f = new File([blob], 'voice-' + Date.now() + '.webm', { type: blob.type });
          uploadFile(f);
        }
        state.mediaRecorder = null;
        state.recordChunks = [];
        if (elRecord) elRecord.classList.remove('bg-red-600', 'text-white');
      };
      state.mediaRecorder.start();
      if (elRecord) elRecord.classList.add('bg-red-600', 'text-white');
    }).catch(function () {
      if (window.HV && HV.toast) HV.toast(dict._mic || 'Microphone denied', { icon: 'warning' });
    });
  }

  function stopRecorder() {
    if (state.mediaRecorder && state.mediaRecorder.state !== 'inactive') {
      state.mediaRecorder.stop();
    }
  }

  if (elRecord) {
    elRecord.addEventListener('mousedown', startRecorder);
    elRecord.addEventListener('mouseup', stopRecorder);
    elRecord.addEventListener('mouseleave', stopRecorder);
    elRecord.addEventListener('touchstart', function (e) { e.preventDefault(); startRecorder(); }, { passive: false });
    elRecord.addEventListener('touchend', function (e) { e.preventDefault(); stopRecorder(); });
  }

  setSidebarOpen(true);
  updatePlaceholder();

  function applyUrlParams() {
    var params = new URLSearchParams(window.location.search);
    var convQ = parseInt(params.get('conversation') || params.get('c') || '0', 10);
    var userQ = parseInt(params.get('user') || params.get('u') || '0', 10);
    if (convQ <= 0 && userQ <= 0) return;
    if (convQ > 0) {
      var f = null;
      for (var i = 0; i < state.conversations.length; i++) {
        if (state.conversations[i].conversationId === convQ) {
          f = state.conversations[i];
          break;
        }
      }
      if (f) {
        openThread(f.conversationId, f.otherUserId, f.otherName);
      } else {
        openThread(convQ, 0, '');
      }
      window.history.replaceState({}, '', window.location.pathname);
      return;
    }
    if (userQ > 0) {
      fetchContacts().then(function (data) {
        var contacts = data.contacts || [];
        var u = null;
        for (var j = 0; j < contacts.length; j++) {
          if (contacts[j].userId === userQ) {
            u = contacts[j];
            break;
          }
        }
        state.otherUserId = userQ;
        state.otherName = u ? u.display : '';
        state.activeConvId = 0;
        state.messages = [];
        state.maxMsgId = 0;
        state.peerLastReadMessageId = 0;
        if (elTitle) elTitle.textContent = state.otherName || threadIdleTitle;
        if (elSub) elSub.textContent = d('type_message');
        updatePlaceholder();
        renderMessages();
        closeSidebar();
        stopPoll();
        startPoll();
        window.history.replaceState({}, '', window.location.pathname);
      }).catch(function () {});
    }
  }

  fetchNL(apiUrl('lang=' + encodeURIComponent(lang)))
    .then(parseJson)
    .then(function (data) {
      state.conversations = data.conversations || [];
      renderConversations();
      applyUrlParams();
    })
    .catch(function () {});

  dict._err = dict._err || 'Error';
  dict._mic = dict._mic || 'Microphone denied';
})();
