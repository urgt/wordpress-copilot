/* ─── WordPress Copilot — chat.js v3 ───────────────────────────── */
(function ($) {
  'use strict';

  const cfg = window.dqaAssistant || {};
  const CHAT_LIMIT = 20;
  const MSG_LIMIT = 80;

  let recognition = null;
  let isListening = false;
  let isBusy = false;
  let currentXhr = null;
  let currentSSE = null;
  let chats = [];
  let activeChatId = null;
  let savedQueries = [];
  let currentSlide = 0;
  const TOTAL_SLIDES = 5;

  const $trigger = $('#dqa-trigger');
  const $panel = $('#dqa-panel');
  const $messages = $('#dqa-messages');
  const $input = $('#dqa-input');
  const $send = $('#dqa-send');
  const $voice = $('#dqa-voice');
  const $close      = $('#dqa-close');
  const $clear      = $('#dqa-clear');
  const $fullscreen = $('#dqa-fullscreen');
  const $badge = $('#dqa-provider-badge');
  const $modelBtn = $('#dqa-model-btn');
  const $modelLabel = $('#dqa-model-label');
  const $modelDropdown = $('#dqa-model-dropdown');
  const $chatList = $('#dqa-chat-list');
  const $newChat = $('#dqa-new-chat');
  const $headerTitle = $('#dqa-header-title');
  const $savedList = $('#dqa-saved-list');
  const $paneChats = $('#dqa-pane-chats');
  const $paneSaved = $('#dqa-pane-saved');
  const $sidebarTabs = $('.dqa-sidebar-tab');
  const $pipelineToggle = $('#dqa-pipeline-toggle');
  // Save modal elements (assigned after DOM ready)
  let $saveModal, $saveTitle, $saveAsTemplate, $saveModalConfirm;
  let _saveModalCallback = null;

  let currentModel = cfg.model || '';
  let pipelineMode = 'simple'; // 'agentic' | 'simple'

  function init() {
    $('.dqa-sidebar-title').text(cfg.i18n.chats || 'Chats');
    $newChat.text('+ ' + (cfg.i18n.newChat || 'New chat'));
    renderModelOptions();
    updateBadge();
    bindEvents();
    updatePipelineToggle();
    if (cfg.enableVoice) initVoice();
    initChats();
    initSavedQueries();

    initSidebarTabs();
    // Cache modal elements after DOM is ready
    $saveModal = $('#dqa-save-modal');
    $saveTitle = $('#dqa-save-title');
    $saveAsTemplate = $('#dqa-save-as-template');
    $saveModalConfirm = $('#dqa-save-modal-confirm');
    bindModalEvents();
    bindConfirmModal();
  }

  function bindEvents() {
    $trigger.on('click', togglePanel);
    $close.on('click', closePanel);
    $clear.on('click', clearCurrentChat);
    $fullscreen.on('click', toggleFullscreen);
    $send.on('click', submitQuery);
    $voice.on('click', toggleVoice);
    $newChat.on('click', createNewChatAndSelect);

    $pipelineToggle.on('click', function () {
      pipelineMode = (pipelineMode === 'agentic') ? 'simple' : 'agentic';
      updatePipelineToggle();
    });

    $modelBtn.on('click', function (e) {
      e.stopPropagation();
      const isOpen = $modelDropdown.hasClass('is-open');
      closeModelDropdown();
      if (!isOpen) openModelDropdown();
    });

    $(document).on('click', function () { closeModelDropdown(); });
    $modelDropdown.on('click', function (e) { e.stopPropagation(); });

    $input.on('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        submitQuery();
      }
    });

    $(document).on('keydown', function (e) {
      if (e.key === 'Escape' && $panel.hasClass('is-open')) closePanel();
    });

    // +N more tags toggle (delegated)
    $messages.on('click', '.dqa-cell-tag-more', function () {
      const $extra = $(this).next('.dqa-cell-tag-extra');
      $extra.toggle();
      $(this).toggle();
    });

    $chatList.on('click', '.dqa-chat-item', function () {
      const id = String($(this).data('id') || '');
      if (!id || id === activeChatId) return;
      activeChatId = id;
      persistChats();
      renderChatList();
      renderActiveChat();
    });

    $chatList.on('click', '.dqa-chat-delete', function (e) {
      e.stopPropagation();
      const id = String($(this).closest('.dqa-chat-item').data('id') || '');
      if (!id) return;
      openConfirmModal(
        cfg.i18n.deleteChatTitle || 'Delete chat',
        cfg.i18n.confirmDeleteChat || 'Delete this chat? All messages will be lost.',
        function () { deleteChat(id); }
      );
    });

    // Onboarding events
    $('#dqa-ob-next').on('click', function () {
      if (currentSlide < TOTAL_SLIDES - 1) { currentSlide++; renderOnboarding(); }
    });
    $('#dqa-ob-prev').on('click', function () {
      if (currentSlide > 0) { currentSlide--; renderOnboarding(); }
    });
    $('#dqa-ob-skip').on('click', finishOnboarding);
    $('#dqa-ob-finish').on('click', finishOnboarding);
    $(document).on('click', '.dqa-ob-example', function () {
      var query = String($(this).data('query') || '');
      finishOnboarding();
      if (query) { $input.val(query).focus(); }
    });
  }

  function togglePanel() { $panel.hasClass('is-open') ? closePanel() : openPanel(); }
  function openPanel() {
    $panel.addClass('is-open');
    $trigger.addClass('active').attr('aria-expanded', 'true');
    $input.focus();
    maybeShowOnboarding();
  }
  function closePanel() {
    if ($panel.hasClass('dqa-is-fullscreen')) toggleFullscreen();
    $panel.removeClass('is-open');
    $trigger.removeClass('active').attr('aria-expanded', 'false');
  }
  function toggleFullscreen() {
    const on = $panel.toggleClass('dqa-is-fullscreen').hasClass('dqa-is-fullscreen');
    $fullscreen.find('.dqa-fs-expand').toggle(!on);
    $fullscreen.find('.dqa-fs-collapse').toggle(on);
    $fullscreen.attr('title', on ? 'Exit fullscreen' : 'Fullscreen');
    $('html, body').toggleClass('dqa-no-scroll', on);
    scrollBottom();
  }

  /* ── Onboarding ─────────────────────────────────────────────── */
  function maybeShowOnboarding() {
    if (localStorage.getItem('dqa_onboarded_v2')) return;
    $('#dqa-onboarding').css('display', 'flex').hide().fadeIn(200);
    currentSlide = 0;
    renderOnboarding();
  }

  function renderOnboarding() {
    $('.dqa-ob-slide').removeClass('active');
    $('.dqa-ob-slide[data-slide="' + currentSlide + '"]').addClass('active');
    $('.dqa-ob-dot').removeClass('active');
    $('.dqa-ob-dot[data-slide="' + currentSlide + '"]').addClass('active');
    $('#dqa-ob-prev').toggle(currentSlide > 0);
    $('#dqa-ob-next').toggle(currentSlide < TOTAL_SLIDES - 1);
    $('#dqa-ob-finish').toggle(currentSlide === TOTAL_SLIDES - 1);
  }

  function finishOnboarding() {
    localStorage.setItem('dqa_onboarded_v2', '1');
    $('#dqa-onboarding').fadeOut(200);
  }

  function initChats() {
    // Show chats from localStorage immediately (fast), then sync with DB
    chats = loadChatsFromStorage();
    if (!Array.isArray(chats) || !chats.length) {
      chats = [createEmptyChat()];
    }
    activeChatId = loadActiveChatId();
    if (!getActiveChat()) activeChatId = chats[0].id;
    renderChatList();
    renderActiveChat();

    // Load from DB and update UI if DB has more recent data
    dbLoadChats(function (dbChats) {
      if (!dbChats || !dbChats.length) {
        // DB is empty: persist local chats to DB
        chats.forEach(function (chat) {
          $.post(cfg.ajaxUrl, {
            action:   'dqa_chat_save',
            nonce:    cfg.nonce,
            provider: cfg.providerKey || '',
            chat:     JSON.stringify(chat)
          });
        });
        return;
      }
      // Merge: DB is source of truth, preserve active chat selection
      var prevActiveId = activeChatId;
      chats = dbChats;
      activeChatId = prevActiveId;
      if (!getActiveChat()) activeChatId = chats[0].id;
      // Update localStorage cache
      try {
        window.localStorage.setItem(getChatsStorageKey(), JSON.stringify(chats));
        window.localStorage.setItem(getActiveChatStorageKey(), String(activeChatId || ''));
      } catch (e) {}
      renderChatList();
      renderActiveChat();
    });
  }

  function initSavedQueries() {
    if (!(cfg.features || {}).savedQueries) return;
    dbLoadSavedQueries(function (items) {
      savedQueries = Array.isArray(items) ? items : [];
    });
  }

  function getCurrentQueryForSave() {
    const inputQuery = String($input.val() || '').trim();
    if (inputQuery) return inputQuery;
    const chat = getActiveChat();
    if (!chat || !Array.isArray(chat.messages)) return '';
    for (let i = chat.messages.length - 1; i >= 0; i--) {
      const msg = chat.messages[i];
      if (msg && msg.role === 'user' && msg.text) {
        return String(msg.text).trim();
      }
    }
    return '';
  }

  function saveCurrentQuery() {
    if (!(cfg.features || {}).savedQueries) {
      alert(cfg.i18n.featureLocked || 'This feature is available in Pro mode.');
      return;
    }

    const query = getCurrentQueryForSave();
    if (!query) return;
    saveQueryFromText(query);
  }

  function saveQueryFromText(queryText) {
    const query = String(queryText || '').trim();
    if (!query) return;
    openSaveModal(query);
  }

  function openSaveModal(query) {
    if (!$saveModal || !$saveModal.length) return;
    $saveTitle.val(makeTitle(query));
    $saveAsTemplate.prop('checked', false);
    $saveModal.data('query', query).fadeIn(150);
    $saveTitle.focus().select();
    _saveModalCallback = function (title, asTemplate) {
      const entry = {
        id: makeId('sq'),
        title: title,
        query: query,
        kind: asTemplate ? 'template' : 'saved'
      };
      dbSaveSavedQuery(entry, function (ok, message) {
        if (!ok) { if (message) alert(message); return; }
        const idx = savedQueries.findIndex(function (item) { return item.id === entry.id; });
        if (idx >= 0) savedQueries[idx] = entry; else savedQueries.unshift(entry);
        renderSavedList();
      });
    };
  }

  function closeSaveModal() {
    if ($saveModal) $saveModal.fadeOut(120);
    _saveModalCallback = null;
  }

  function bindModalEvents() {
    $('#dqa-save-modal-confirm').on('click', function () {
      const title = String($saveTitle.val() || '').trim();
      if (!title) { $saveTitle.focus(); return; }
      const asTemplate = $saveAsTemplate.is(':checked');
      const cb = _saveModalCallback;
      closeSaveModal();
      if (cb) cb(title, asTemplate);
    });
    $('#dqa-save-modal-cancel, #dqa-save-modal-cancel2').on('click', closeSaveModal);
    $saveTitle.on('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); $('#dqa-save-modal-confirm').trigger('click'); }
      if (e.key === 'Escape') closeSaveModal();
    });
    // Close on backdrop click
    $('#dqa-save-modal').on('click', function (e) {
      if ($(e.target).is('#dqa-save-modal')) closeSaveModal();
    });
  }

  var _confirmCallback = null;

  function openConfirmModal(title, message, onConfirm) {
    $('#dqa-confirm-title').text(title);
    $('#dqa-confirm-msg').text(message);
    _confirmCallback = onConfirm;
    $('#dqa-confirm-modal').fadeIn(150);
  }

  function closeConfirmModal() {
    $('#dqa-confirm-modal').fadeOut(120);
    _confirmCallback = null;
  }

  function bindConfirmModal() {
    $('#dqa-confirm-ok').on('click', function () {
      const cb = _confirmCallback;
      closeConfirmModal();
      if (cb) cb();
    });
    $('#dqa-confirm-cancel').on('click', closeConfirmModal);
    $('#dqa-confirm-modal').on('click', function (e) {
      if ($(e.target).is('#dqa-confirm-modal')) closeConfirmModal();
    });
    $(document).on('keydown.dqa-confirm', function (e) {
      if ($('#dqa-confirm-modal').is(':visible') && e.key === 'Escape') closeConfirmModal();
    });
  }

  function initSidebarTabs() {
    $sidebarTabs.on('click', function () {
      switchSidebarTab($(this).data('sidebar-tab'));
    });
  }

  function switchSidebarTab(tab) {
    $sidebarTabs.removeClass('active').filter('[data-sidebar-tab="' + tab + '"]').addClass('active');
    if (tab === 'saved') {
      $paneChats.addClass('dqa-hidden');
      $paneSaved.removeClass('dqa-hidden');
      renderSavedList();
    } else {
      $paneSaved.addClass('dqa-hidden');
      $paneChats.removeClass('dqa-hidden');
    }
  }

  function renderSavedList() {
    if (!$savedList.length) return;
    $savedList.empty();
    if (!savedQueries.length) {
      $savedList.append('<p class="dqa-saved-empty">' + (cfg.i18n.noSavedQueries || 'No saved queries yet.') + '</p>');
      return;
    }
    savedQueries.forEach(function (item) {
      const kindBadge = (item.kind === 'template')
        ? '<span class="dqa-sq-badge">' + (cfg.i18n.templates || 'Template') + '</span>'
        : '';
      const $item = $(
        '<div class="dqa-sq-item" data-id="' + $('<div>').text(item.id).html() + '">' +
          '<div class="dqa-sq-title">' + $('<div>').text(item.title || item.query).html() + kindBadge + '</div>' +
          '<div class="dqa-sq-actions">' +
            '<button type="button" class="dqa-sq-btn dqa-sq-apply" title="' + (cfg.i18n.applyQuery || 'Apply') + '">' +
              '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>' +
            '</button>' +
            '<button type="button" class="dqa-sq-btn dqa-sq-edit" title="' + (cfg.i18n.editTitle || 'Rename') + '">' +
              '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>' +
            '</button>' +
            '<button type="button" class="dqa-sq-btn dqa-sq-delete" title="' + (cfg.i18n.deleteChat || 'Delete') + '">' +
              '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>' +
            '</button>' +
          '</div>' +
        '</div>'
      );
      $item.find('.dqa-sq-apply').on('click', function () {
        $input.val(String(item.query || '')).focus();
        switchSidebarTab('chats');
      });
      $item.find('.dqa-sq-edit').on('click', function () {
        const newTitle = String(window.prompt(cfg.i18n.editTitle || 'New title:', item.title) || '').trim();
        if (!newTitle || newTitle === item.title) return;
        item.title = newTitle;
        dbSaveSavedQuery(item, function () { renderSavedList(); });
      });
      $item.find('.dqa-sq-delete').on('click', function () {
        if (!window.confirm(cfg.i18n.confirmDeleteSaved || 'Delete this saved query?')) return;
        deleteSavedQuery(item.id);
      });
      $savedList.append($item);
    });
  }

  function deleteSavedQuery(id) {
    savedQueries = savedQueries.filter(function (item) { return item.id !== id; });
    renderSavedList();
    $.post(cfg.ajaxUrl, { action: 'dqa_saved_query_delete', nonce: cfg.nonce, id: id });
  }

  function createEmptyChat() {
    return {
      id: makeId('chat'),
      title: cfg.i18n.newChat || 'New chat',
      createdAt: Date.now(),
      updatedAt: Date.now(),
      messages: []
    };
  }

  function createNewChatAndSelect() {
    // Prevent creating another empty chat if the active one is already empty
    const active = getActiveChat();
    if (active && active.messages.length === 0) {
      $input.focus();
      return;
    }
    if (isBusy) abortCurrent();
    const chat = createEmptyChat();
    chats.unshift(chat);
    if (chats.length > CHAT_LIMIT) chats = chats.slice(0, CHAT_LIMIT);
    activeChatId = chat.id;
    persistChats();
    renderChatList();
    renderActiveChat();
    $input.focus();
  }

  function deleteChat(chatId) {
    chats = chats.filter(function (chat) { return chat.id !== chatId; });
    if (!chats.length) chats = [createEmptyChat()];
    if (!getActiveChat()) activeChatId = chats[0].id;
    dbDeleteChat(chatId);
    persistChats();
    renderChatList();
    renderActiveChat();
  }

  function clearCurrentChat() {
    const chat = getActiveChat();
    if (!chat) return;
    openConfirmModal(
      cfg.i18n.clearChatTitle || 'Clear chat',
      cfg.i18n.confirmClearChat || 'Clear all messages in this chat? This cannot be undone.',
      function () {
        if (isBusy) abortCurrent();
        chat.messages = [];
        chat.title = cfg.i18n.newChat || 'New chat';
        chat.updatedAt = Date.now();
        persistChats();
        renderChatList();
        renderActiveChat();
      }
    );
  }

  function getActiveChat() {
    return chats.find(function (chat) { return chat.id === activeChatId; }) || null;
  }

  function renderChatList() {
    $chatList.empty();
    chats.forEach(function (chat) {
      const $item = $('<button type="button" class="dqa-chat-item">')
        .attr('data-id', chat.id)
        .toggleClass('is-active', chat.id === activeChatId);
      $item.append($('<span class="dqa-chat-item-title">').text(chat.title || (cfg.i18n.newChat || 'New chat')));
      $item.append($('<span class="dqa-chat-delete" title="' + escHtml(cfg.i18n.deleteChat || 'Delete chat') + '">').html('&times;'));
      $chatList.append($item);
    });
  }

  function renderActiveChat() {
    $messages.empty();
    const chat = getActiveChat();
    const title = (chat && chat.title) ? chat.title : (cfg.i18n.newChat || 'New chat');
    $headerTitle.text(title);
    if (!chat || !chat.messages.length) {
      renderWelcome();
      return;
    }

    chat.messages.forEach(function (msg) {
      if (msg.role === 'user') {
        appendMsg('user', escHtml(msg.text || ''));
        return;
      }

      const $bot = appendMsg('bot', '', false, true);
      $bot.data('query', msg.query || '');
      if (msg.error) {
        $bot.html('<span class="dqa-error">⚠ ' + escHtml(msg.error) + '</span>');
        if (msg.sql && cfg.showSql) appendSqlBlock($bot, msg.sql);
        appendActions($bot);
      } else if (msg.data) {
        renderResult($bot, msg.data);
      }
    });

    scrollBottom();
  }

  function renderWelcome() {
    const $welcome = $('<div class="dqa-welcome">');
    $welcome.append($('<p>').text(cfg.i18n.emptyChat || 'Start a new conversation to keep context.'));

    const groups = cfg.i18n.example_groups;
    if (groups && groups.length) {
      const $groups = $('<div id="dqa-examples">');
      groups.forEach(function (g) {
        const $group = $('<div class="dqa-examples-group">');
        $group.append($('<span class="dqa-examples-group-label">').text(g.group || ''));
        const $chips = $('<div class="dqa-examples-chips">');
        (g.items || []).forEach(function (ex) {
          const $btn = $('<button class="dqa-chip">').text(ex);
          $btn.on('click', function () {
            $input.val(ex);
            submitQuery();
          });
          $chips.append($btn);
        });
        $group.append($chips);
        $groups.append($group);
      });
      $welcome.append($groups);
    } else {
      // fallback: flat examples array
      const $examples = $('<div id="dqa-examples">');
      (cfg.i18n.examples || []).forEach(function (ex) {
        const $btn = $('<button class="dqa-chip">').text(ex);
        $btn.on('click', function () {
          const clean = ex.replace(/^[\u{1F000}-\u{1FFFF}\s]+/gu, '').trim();
          $input.val(clean);
          submitQuery();
        });
        $examples.append($btn);
      });
      $welcome.append($examples);
    }

    $messages.append($welcome);
  }

  function submitQuery() {
    if (isBusy) return;
    const query = String($input.val() || '').trim();
    if (!query) return;

    const chat = getActiveChat();
    if (!chat) return;

    const context = buildContext(chat);
    if (!chat.messages.length) chat.title = makeTitle(query);
    const userMsg = { id: makeId('m'), role: 'user', text: query, ts: Date.now() };
    chat.messages.push(userMsg);
    trimChat(chat);
    chat.updatedAt = Date.now();
    persistChats();
    renderChatList();
    $headerTitle.text(chat.title || (cfg.i18n.newChat || 'New chat'));

    $messages.find('.dqa-welcome').remove();
    appendMsg('user', escHtml(query));
    $input.val('');

    cfg.streaming ? sendStream(query, context) : sendAjax(query, context);
  }

  function sendStream(query, context) {
    setBusy(true);
    const $bot = appendMsg('bot', '', false, true);
    $bot.data('query', query);
    $bot.html('<span class="dqa-dots"><span></span><span></span><span></span></span>');

    const controller = new AbortController();
    currentSSE = { close: function () { controller.abort(); } };

    const body = new URLSearchParams({
      action: 'dqa_stream',
      nonce: cfg.nonce,
      query: query,
      model: getSelectedModel(),
      pipeline: pipelineMode,
      context: context
    });

    fetch(cfg.ajaxUrl, {
      method: 'POST',
      body: body,
      signal: controller.signal,
      headers: { 'X-WP-Nonce': cfg.nonce }
    })
      .then(function (res) {
        if (!res.body) throw new Error('No response body');
        const reader = res.body.getReader();
        const decoder = new TextDecoder();
        let sseBuffer = '';

        function read() {
          reader.read().then(function (chunk) {
            if (chunk.done) {
              setBusy(false);
              currentSSE = null;
              return;
            }

            sseBuffer += decoder.decode(chunk.value, { stream: true });
            const lines = sseBuffer.split('\n');
            sseBuffer = lines.pop();

            lines.forEach(function (line) {
              line = line.trim();
              if (!line || line.indexOf('data:') !== 0) return;
              let event;
              try {
                event = JSON.parse(line.slice(5).trim());
              } catch (e) {
                return;
              }
              handleSSEEvent(event, $bot, query);
            });

            read();
          }).catch(function (err) {
            if (err.name !== 'AbortError') {
              handleBotError($bot, cfg.i18n.error || 'Something went wrong. Please try again.', query);
            }
            setBusy(false);
            currentSSE = null;
          });
        }

        read();
      })
      .catch(function (err) {
        if (err.name !== 'AbortError') {
          handleBotError($bot, cfg.i18n.error || 'Something went wrong. Please try again.', query);
        }
        setBusy(false);
        currentSSE = null;
      });
  }

  function handleSSEEvent(event, $bot, query) {
    if (event.type === 'status') {
      let $thinking = $bot.find('.dqa-thinking');
      if (!$thinking.length) {
        $bot.find('.dqa-dots').remove();
        $thinking = $(
          '<details class="dqa-thinking" open>' +
            '<summary class="dqa-thinking-summary">' +
              '<span class="dqa-thinking-spinner"></span>' +
              '<span class="dqa-thinking-label"></span>' +
              '<span class="dqa-thinking-chevron">▾</span>' +
            '</summary>' +
            '<div class="dqa-thinking-body"><pre class="dqa-thinking-pre"></pre></div>' +
          '</details>'
        );
        $bot.append($thinking);
      }
      $thinking.find('.dqa-thinking-label').text(String(event.data || ''));
      scrollBottom();
      return;
    }

    if (event.type === 'token') {
      let $thinking = $bot.find('.dqa-thinking');
      if (!$thinking.length) {
        $bot.find('.dqa-dots').remove();
        $thinking = $(
          '<details class="dqa-thinking" open>' +
            '<summary class="dqa-thinking-summary">' +
              '<span class="dqa-thinking-spinner"></span>' +
              '<span class="dqa-thinking-label">Thinking…</span>' +
              '<span class="dqa-thinking-chevron">▾</span>' +
            '</summary>' +
            '<div class="dqa-thinking-body"><pre class="dqa-thinking-pre"></pre></div>' +
          '</details>'
        );
        $bot.append($thinking);
      }
      const $pre = $thinking.find('.dqa-thinking-pre');
      const next = ($pre.data('raw') || '') + String(event.data || '');
      $pre.data('raw', next);
      $pre.text(next);
      const body = $thinking.find('.dqa-thinking-body')[0];
      if (body) body.scrollTop = body.scrollHeight;
      scrollBottom();
      return;
    }

    if (event.type === 'end') {
      let result;
      try {
        result = JSON.parse(event.data);
      } catch (e) {
        handleBotError($bot, 'Parse error', query);
        setBusy(false);
        currentSSE = null;
        return;
      }

      // Collapse and mark thinking block as done, keep it above result
      const $thinking = $bot.find('.dqa-thinking');
      if ($thinking.length) {
        $thinking.addClass('dqa-thinking--done');
        $thinking.find('.dqa-thinking-label').text(cfg.i18n.done || 'Done');
        $thinking[0].removeAttribute('open');
        $thinking.detach();
      }

      if (result.new_nonce) cfg.nonce = result.new_nonce;
      $bot.empty();
      if ($thinking && $thinking.length) $bot.append($thinking);
      renderResult($bot, result);
      saveBotSuccess(query, result);
      setBusy(false);
      currentSSE = null;
      return;
    }

    if (event.type === 'error') {
      $bot.find('.dqa-thinking, .dqa-dots').remove();
      if ('no_api_key' === (event.code || '')) {
        showNoApiKeyNotice($bot, query);
      } else if (event.sql && cfg.showSql) {
        handleBotError($bot, String(event.data || ''), query, event.sql);
      } else {
        handleBotError($bot, String(event.data || ''), query);
      }
      setBusy(false);
      currentSSE = null;
    }
  }

  function sendAjax(query, context) {
    setBusy(true);
    const $bot = appendMsg('bot', '', false, true);
    $bot.data('query', query);
    $bot.html('<span class="dqa-dots"><span></span><span></span><span></span></span>');

    currentXhr = $.ajax({
      url: cfg.ajaxUrl,
      method: 'POST',
      timeout: 90000,
      data: {
        action: 'dqa_query',
        nonce: cfg.nonce,
        query: query,
        model: getSelectedModel(),
        pipeline: pipelineMode,
        context: context
      }
    })
      .done(function (res) {
        $bot.empty();
        if (res.success) {
          if (res.data.new_nonce) cfg.nonce = res.data.new_nonce;
          renderResult($bot, res.data);
          saveBotSuccess(query, res.data);
        } else {
          const message = ((res.data || {}).message) || (cfg.i18n.error || 'Something went wrong. Please try again.');
          const code    = (res.data || {}).code || '';
          if ('no_api_key' === code) {
            showNoApiKeyNotice($bot, query);
          } else {
            handleBotError($bot, message, query, res.data && res.data.sql ? res.data.sql : '');
          }
        }
      })
      .fail(function (xhr) {
        if (xhr.statusText !== 'abort') {
          handleBotError($bot, cfg.i18n.error || 'Something went wrong. Please try again.', query);
        }
      })
      .always(function () {
        setBusy(false);
        currentXhr = null;
        $input.focus();
      });
  }

  function saveBotSuccess(query, data) {
    const chat = getActiveChat();
    if (!chat) return;
    chat.messages.push({
      id: makeId('m'),
      role: 'bot',
      query: query,
      data: sanitizeResultForStorage(data),
      ts: Date.now()
    });
    trimChat(chat);
    chat.updatedAt = Date.now();
    persistChats();
  }

  function handleBotError($bot, message, query, sql) {
    $bot.html('<span class="dqa-error">⚠ ' + escHtml(message) + '</span>');
    if (sql && cfg.showSql) appendSqlBlock($bot, sql);
    appendActions($bot);
    saveBotError(query, message, sql || '');
  }

  function showNoApiKeyNotice($bot, query) {
    const settingsUrl = cfg.settingsUrl || '#';
    const title   = cfg.i18n.noApiKey    || 'API key not configured';
    const msg     = cfg.i18n.noApiKeyMsg || 'Add your AI provider API key in plugin settings to start using WordPress Copilot.';
    const btnText = cfg.i18n.goToSettings || 'Open Settings';
    $bot.html(
      '<div class="dqa-no-api-key">' +
        '<div class="dqa-no-api-key__icon">🔑</div>' +
        '<h3 class="dqa-no-api-key__title">' + escHtml(title) + '</h3>' +
        '<p class="dqa-no-api-key__msg">' + escHtml(msg) + '</p>' +
        '<a href="' + escHtml(settingsUrl) + '" class="dqa-no-api-key__btn">' + escHtml(btnText) + ' →</a>' +
      '</div>'
    );
    saveBotError(query, title, '');
  }

  function saveBotError(query, message, sql) {
    const chat = getActiveChat();
    if (!chat) return;
    chat.messages.push({
      id: makeId('m'),
      role: 'bot',
      query: query,
      error: String(message || ''),
      sql: String(sql || ''),
      ts: Date.now()
    });
    trimChat(chat);
    chat.updatedAt = Date.now();
    persistChats();
  }

  /* ── Minimal markdown → HTML renderer ───────────────────────── */
  function mdToHtml(text) {
    if (!text) return '';
    // Escape HTML first
    let s = text
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

    // Fenced code blocks
    s = s.replace(/```[\w]*\n?([\s\S]*?)```/g, '<pre class="dqa-md-pre"><code>$1</code></pre>');

    // Markdown table: lines with | col | col |
    s = s.replace(/((?:\|[^\n]+\|\n?)+)/g, function(block) {
      const rows = block.trim().split('\n').filter(r => r.trim());
      if (rows.length < 2) return block;
      let html = '<div class="dqa-table-wrap"><table class="dqa-table dqa-md-table">';
      rows.forEach(function(row, i) {
        if (/^\|[-| :]+\|$/.test(row.trim())) return; // separator row
        const cells = row.split('|').filter((_, ci) => ci > 0 && ci < row.split('|').length - 1);
        const tag = i === 0 ? 'th' : 'td';
        html += '<tr>' + cells.map(c => `<${tag}>${c.trim()}</${tag}>`).join('') + '</tr>';
      });
      html += '</table></div>';
      return html;
    });

    // Headers
    s = s.replace(/^### (.+)$/gm, '<h4 class="dqa-md-h">$1</h4>');
    s = s.replace(/^## (.+)$/gm,  '<h3 class="dqa-md-h">$1</h3>');
    s = s.replace(/^# (.+)$/gm,   '<h2 class="dqa-md-h">$1</h2>');

    // Unordered lists
    s = s.replace(/((?:^[*\-] .+\n?)+)/gm, function(block) {
      const items = block.trim().split('\n').map(l => '<li>' + l.replace(/^[*\-] /, '') + '</li>');
      return '<ul class="dqa-md-ul">' + items.join('') + '</ul>';
    });
    // Ordered lists
    s = s.replace(/((?:^\d+\. .+\n?)+)/gm, function(block) {
      const items = block.trim().split('\n').map(l => '<li>' + l.replace(/^\d+\. /, '') + '</li>');
      return '<ol class="dqa-md-ol">' + items.join('') + '</ol>';
    });

    // Bold, italic, inline code
    s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    s = s.replace(/\*(.+?)\*/g,     '<em>$1</em>');
    s = s.replace(/`([^`]+)`/g,     '<code class="dqa-md-code">$1</code>');

    // Paragraphs: double newlines
    s = s.split(/\n{2,}/).map(function(p) {
      p = p.trim();
      if (!p) return '';
      if (/^<(h[2-4]|ul|ol|pre|div|table)/.test(p)) return p;
      return '<p class="dqa-md-p">' + p.replace(/\n/g, '<br>') + '</p>';
    }).join('\n');

    return s;
  }

  function renderResult($bot, data) {
    if (data.explanation) {
      $bot.append($('<div class="dqa-explanation">').html(mdToHtml(data.explanation)));
    }
    if (data.html) {
      $bot.append($(data.html));
    }
    if (data.sql) {
      appendSqlBlock($bot, data.sql);
    }

    const meta = [];
    if (data.model) meta.push(data.model);
    if (data.pipeline) meta.push(data.pipeline === 'agentic' ? (cfg.i18n.pipelineAgentic || 'Deep') : (cfg.i18n.pipelineSimple || 'Fast'));
    if (data.count > 1) meta.push(data.count + ' rows');
    if (data.tokens) meta.push('↑' + (data.tokens.in || 0) + ' ↓' + (data.tokens.out || 0) + ' tok');
    if (data.exec_ms) meta.push((data.exec_ms / 1000).toFixed(1) + 's');
    if (meta.length) {
      $bot.append($('<div class="dqa-meta">').text(meta.join(' · ')));
    }

    appendActions($bot);
    scrollBottom();
  }

  function appendSqlBlock($container, sql) {
    const $toggle = $('<button class="dqa-sql-toggle" type="button">').text('Show SQL ▾');
    const $code = $('<pre class="dqa-sql-code">').text(sql).hide();
    $toggle.on('click', function () {
      $code.toggle();
      $toggle.text($code.is(':visible') ? 'Hide SQL ▴' : 'Show SQL ▾');
    });
    $container.append($toggle).append($code);
  }

  function appendActions($container) {
    const query = String($container.data('query') || '');
    if (!query) return;
    $container.find('.dqa-actions').remove();

    const $actions = $('<div class="dqa-actions">');
    if ((cfg.features || {}).savedQueries) {
      const $save = $('<button type="button" class="dqa-retry-btn">');
      $save.html('<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-1px;margin-right:4px"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>' + (cfg.i18n.saveQuery || 'Save query'));
      $save.on('click', function () { saveQueryFromText(query); });
      $actions.append($save);
    }
    if ((cfg.features || {}).csvExport && ($container.find('.dqa-table').length || $container.find('.dqa-scalar').length)) {
      const $export = $('<button type="button" class="dqa-retry-btn">');
      $export.html('<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-1px;margin-right:4px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>' + (cfg.i18n.exportCsv || 'Export CSV'));
      $export.on('click', function () { exportResultCsv($container, query); });
      $actions.append($export);
    }
    const $retry = $('<button type="button" class="dqa-retry-btn">');
    $retry.html('<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-1px;margin-right:4px"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.95"/></svg>' + (cfg.i18n.retry || 'Try again'));
    $retry.on('click', function () {
      if (isBusy) return;
      $input.val(query);
      submitQuery();
    });
    $actions.append($retry);
    $container.append($actions);
  }

  function appendMsg(role, html, isError) {
    const $m = $('<div>').addClass('dqa-msg dqa-msg--' + role);
    if (isError) $m.addClass('dqa-msg--error');
    if (html) $m.html(html);
    $messages.append($m);
    scrollBottom();
    return $m;
  }

  function scrollBottom() {
    const el = $messages[0];
    if (el) el.scrollTop = el.scrollHeight;
  }

  function abortCurrent() {
    if (currentXhr) { currentXhr.abort(); currentXhr = null; }
    if (currentSSE) { currentSSE.close(); currentSSE = null; }
    setBusy(false);
  }

  function setBusy(busy) {
    isBusy = busy;
    $send.prop('disabled', busy);
    $send.toggleClass('dqa-send-btn--busy', busy);
  }

  function buildContext(chat) {
    if (!chat || !Array.isArray(chat.messages) || !chat.messages.length) return '';
    const start = Math.max(0, chat.messages.length - 10);
    const items = chat.messages.slice(start, chat.messages.length - 1);
    let out = '';

    items.forEach(function (msg) {
      if (msg.role === 'user' && msg.text) {
        out += 'User: ' + String(msg.text).trim() + '\n';
      } else if (msg.role === 'bot') {
        const botText = getBotContextText(msg);
        if (botText) out += 'Assistant: ' + botText + '\n';
      }
    });

    return out.slice(0, 2600);
  }

  function getBotContextText(msg) {
    if (msg.error) return 'Error: ' + String(msg.error).trim();
    if (msg.data && msg.data.explanation) return String(msg.data.explanation).trim();
    if (msg.data && msg.data.summary) return String(msg.data.summary).trim();
    if (msg.data && msg.data.html) return stripTags(String(msg.data.html)).slice(0, 220).trim();
    return '';
  }

  function stripTags(html) {
    const div = document.createElement('div');
    div.innerHTML = html;
    return div.textContent || div.innerText || '';
  }

  function sanitizeResultForStorage(data) {
    return {
      explanation: String(data.explanation || ''),
      summary: String(data.summary || ''),
      html: String(data.html || ''),
      sql: String(data.sql || ''),
      count: Number(data.count || 0),
      exec_ms: Number(data.exec_ms || 0),
      tokens: data.tokens || null,
      health: data.health || null
    };
  }

  function trimChat(chat) {
    if (!chat || !Array.isArray(chat.messages)) return;
    if (chat.messages.length > MSG_LIMIT) {
      chat.messages = chat.messages.slice(chat.messages.length - MSG_LIMIT);
    }
  }

  function makeTitle(query) {
    const clean = String(query || '').replace(/\s+/g, ' ').trim();
    if (!clean) return cfg.i18n.newChat || 'New chat';
    return clean.length > 42 ? clean.slice(0, 42) + '…' : clean;
  }

  function openModelDropdown() {
    $modelBtn.addClass('is-open');
    $modelDropdown.addClass('is-open');
  }

  function closeModelDropdown() {
    $modelBtn.removeClass('is-open');
    $modelDropdown.removeClass('is-open');
  }

  function selectModel(value, label) {
    currentModel = value;
    cfg.model = value;
    $modelLabel.text(label);
    $modelDropdown.find('.dqa-model-option').each(function () {
      $(this).toggleClass('is-selected', $(this).data('value') === value);
    });
    closeModelDropdown();
    try { window.localStorage.setItem(getModelStorageKey(), value); } catch (e) {}
  }

  function renderModelOptions() {
    const options = cfg.modelOptions || {};
    const entries = Object.entries(options);
    if (!$modelBtn.length) return;

    $modelDropdown.empty();
    entries.forEach(function (entry) {
      const $li = $('<li class="dqa-model-option">').attr('data-value', entry[0]);
      $li.append($('<span>').text(entry[1]));
      $li.append($('<svg class="dqa-model-check" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>'));
      $li.on('click', function () { selectModel(entry[0], entry[1]); });
      $modelDropdown.append($li);
    });

    const saved = loadSavedModel(entries);
    const fallback = cfg.model || (entries[0] ? entries[0][0] : '');
    const selected = saved || fallback;
    if (selected) {
      const label = options[selected] || selected;
      currentModel = selected;
      cfg.model = selected;
      $modelLabel.text(label);
      $modelDropdown.find('.dqa-model-option').each(function () {
        $(this).toggleClass('is-selected', $(this).data('value') === selected);
      });
    }
  }

  function loadSavedModel(entries) {
    try {
      const saved = window.localStorage.getItem(getModelStorageKey());
      if (!saved) return '';
      return entries.some(function (entry) { return entry[0] === saved; }) ? saved : '';
    } catch (e) {
      return '';
    }
  }

  function getModelStorageKey() {
    return 'dqa_model_override_' + String(cfg.providerKey || 'default');
  }

  function getChatsStorageKey() {
    return 'dqa_chats_' + String(cfg.providerKey || 'default');
  }

  function getActiveChatStorageKey() {
    return 'dqa_active_chat_' + String(cfg.providerKey || 'default');
  }

  function getSelectedModel() {
    return currentModel || cfg.model || '';
  }

  function updateBadge() {
    const providerLabel = cfg.providerLabel || cfg.provider || '';
    $badge.text(providerLabel);
  }

  function updatePipelineToggle() {
    const isAgentic = (pipelineMode === 'agentic');
    $pipelineToggle
      .toggleClass('is-agentic', isAgentic)
      .toggleClass('is-simple', !isAgentic)
      .attr('title', isAgentic ? (cfg.i18n.pipelineAgenticTitle || 'Deep mode') : (cfg.i18n.pipelineSimpleTitle || 'Fast mode'));
  }

  /* ── DB storage ─────────────────────────────────────────── */

  function loadChatsFromStorage() {
    try {
      const raw = window.localStorage.getItem(getChatsStorageKey());
      const parsed = JSON.parse(raw || '[]');
      if (!Array.isArray(parsed)) return [];
      return parsed.filter(function (chat) { return chat && chat.id && Array.isArray(chat.messages); });
    } catch (e) {
      return [];
    }
  }

  function loadActiveChatId() {
    try {
      return String(window.localStorage.getItem(getActiveChatStorageKey()) || '');
    } catch (e) {
      return '';
    }
  }

  // Write-through: update localStorage immediately, then sync to DB (debounced)
  function persistChats() {
    try {
      window.localStorage.setItem(getChatsStorageKey(), JSON.stringify(chats));
      window.localStorage.setItem(getActiveChatStorageKey(), String(activeChatId || ''));
    } catch (e) {}
    dbSaveActiveChat();
  }

  var _dbSaveTimer = null;
  function dbSaveActiveChat() {
    clearTimeout(_dbSaveTimer);
    _dbSaveTimer = setTimeout(function () {
      var chat = getActiveChat();
      if (!chat) return;
      $.post(cfg.ajaxUrl, {
        action:   'dqa_chat_save',
        nonce:    cfg.nonce,
        provider: cfg.providerKey || '',
        chat:     JSON.stringify(chat)
      });
    }, 600);
  }

  function dbLoadChats(callback) {
    $.post(cfg.ajaxUrl, {
      action:   'dqa_chats_load',
      nonce:    cfg.nonce,
      provider: cfg.providerKey || ''
    }, function (resp) {
      if (resp && resp.success && Array.isArray(resp.data)) {
        callback(resp.data);
      } else {
        callback(null);
      }
    }).fail(function () { callback(null); });
  }

  function dbDeleteChat(chatId) {
    $.post(cfg.ajaxUrl, {
      action:  'dqa_chat_delete',
      nonce:   cfg.nonce,
      chat_id: chatId
    });
  }

  function dbLoadSavedQueries(callback) {
    $.post(cfg.ajaxUrl, {
      action:   'dqa_saved_queries_load',
      nonce:    cfg.nonce,
      provider: cfg.providerKey || ''
    }, function (resp) {
      if (resp && resp.success && Array.isArray(resp.data)) {
        callback(resp.data);
        return;
      }
      callback([]);
    }).fail(function () { callback([]); });
  }

  function dbSaveSavedQuery(entry, callback) {
    $.post(cfg.ajaxUrl, {
      action:   'dqa_saved_query_save',
      nonce:    cfg.nonce,
      provider: cfg.providerKey || '',
      entry:    JSON.stringify(entry || {})
    }, function (resp) {
      if (resp && resp.success) {
        callback(true, '');
        return;
      }
      const message = ((resp || {}).data || {}).message || (cfg.i18n.error || 'Something went wrong. Please try again.');
      callback(false, String(message));
    }).fail(function () {
      callback(false, cfg.i18n.error || 'Something went wrong. Please try again.');
    });
  }

  function exportResultCsv($container, query) {
    const rows = [];
    const $table = $container.find('.dqa-table').first();
    if ($table.length) {
      const header = [];
      $table.find('thead th').each(function () { header.push(normalizeCsvCell($(this).text())); });
      if (header.length) rows.push(header);
      $table.find('tbody tr').each(function () {
        const row = [];
        $(this).find('td').each(function () {
          row.push(normalizeCsvCell($(this).text()));
        });
        if (row.length) rows.push(row);
      });
    } else {
      const scalarLabel = normalizeCsvCell($container.find('.dqa-scalar-label').first().text());
      const scalarValue = normalizeCsvCell($container.find('.dqa-scalar-value').first().text());
      if (scalarLabel || scalarValue) {
        rows.push(['metric', 'value']);
        rows.push([scalarLabel || 'value', scalarValue || '']);
      }
    }

    if (!rows.length) return;
    const csvText = rows.map(function (row) {
      return row.map(csvEscape).join(',');
    }).join('\r\n');

    const slug = slugifyFilename(query || 'result');
    downloadBlob(
      new Blob([csvText], { type: 'text/csv;charset=utf-8;' }),
      'dqa-result-' + slug + '.csv'
    );
  }

  function downloadBlob(blob, filename) {
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.URL.revokeObjectURL(url);
  }

  function slugifyFilename(text) {
    return String(text || 'query')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 48) || 'query';
  }

  function normalizeCsvCell(text) {
    return String(text || '').replace(/\s+/g, ' ').trim();
  }

  function csvEscape(value) {
    const cell = String(value || '');
    if (/["\n,]/.test(cell)) {
      return '"' + cell.replace(/"/g, '""') + '"';
    }
    return cell;
  }

  function makeId(prefix) {
    return prefix + '_' + Math.random().toString(36).slice(2, 10) + '_' + Date.now().toString(36);
  }

  function initVoice() {
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRecognition) return;

    $voice.show();
    recognition = new SpeechRecognition();
    recognition.continuous = false;
    recognition.interimResults = true;
    recognition.lang = document.documentElement.lang || 'en-US';

    recognition.onstart = function () {
      isListening = true;
      $voice.addClass('active');
      $input.attr('placeholder', cfg.i18n.voiceStart);
    };
    recognition.onend = function () {
      isListening = false;
      $voice.removeClass('active');
      $input.attr('placeholder', cfg.i18n.placeholder);
      if ($input.val().trim()) submitQuery();
    };
    recognition.onresult = function (e) {
      let t = '';
      for (let i = e.resultIndex; i < e.results.length; i++) t += e.results[i][0].transcript;
      $input.val(t);
    };
    recognition.onerror = function () {
      isListening = false;
      $voice.removeClass('active');
    };
  }

  function toggleVoice() {
    if (!recognition) {
      alert(cfg.i18n.noVoice);
      return;
    }
    isListening ? recognition.stop() : recognition.start();
  }

  function escHtml(str) {
    return String(str || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  $(document).ready(init);
}(jQuery));
