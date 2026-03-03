/* ─── WordPress Copilot — chat.js v3 ───────────────────────────── */
(function ($) {
  'use strict';

  const cfg = window.wpCopilot || {};
  const CHAT_LIMIT = 20;
  const MSG_LIMIT = 80;

  let recognition = null;
  let isListening = false;
  let isBusy = false;
  let currentXhr = null;
  let currentSSE = null;
  let chats = [];
  let activeChatId = null;
  let currentSlide = 0;
  const TOTAL_SLIDES = 4;

  const $trigger = $('#wpc-trigger');
  const $panel = $('#wpc-panel');
  const $messages = $('#wpc-messages');
  const $input = $('#wpc-input');
  const $send = $('#wpc-send');
  const $voice = $('#wpc-voice');
  const $close      = $('#wpc-close');
  const $clear      = $('#wpc-clear');
  const $fullscreen = $('#wpc-fullscreen');
  const $badge = $('#wpc-provider-badge');
  const $modelBtn = $('#wpc-model-btn');
  const $modelLabel = $('#wpc-model-label');
  const $modelDropdown = $('#wpc-model-dropdown');
  const $chatList = $('#wpc-chat-list');
  const $newChat = $('#wpc-new-chat');
  const $headerTitle = $('#wpc-header-title');

  let currentModel = cfg.model || '';

  function init() {
    $('.wpc-sidebar-title').text(cfg.i18n.chats || 'Chats');
    $newChat.text('+ ' + (cfg.i18n.newChat || 'New chat'));
    renderModelOptions();
    updateBadge();
    bindEvents();
    if (cfg.enableVoice) initVoice();
    initChats();
  }

  function bindEvents() {
    $trigger.on('click', togglePanel);
    $close.on('click', closePanel);
    $clear.on('click', clearCurrentChat);
    $fullscreen.on('click', toggleFullscreen);
    $send.on('click', submitQuery);
    $voice.on('click', toggleVoice);
    $newChat.on('click', createNewChatAndSelect);

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
      if (e.key === 'Escape' && $panel.is(':visible')) closePanel();
    });

    // +N more tags toggle (delegated)
    $messages.on('click', '.wpc-cell-tag-more', function () {
      const $extra = $(this).next('.wpc-cell-tag-extra');
      $extra.toggle();
      $(this).toggle();
    });

    $chatList.on('click', '.wpc-chat-item', function () {
      const id = String($(this).data('id') || '');
      if (!id || id === activeChatId) return;
      activeChatId = id;
      persistChats();
      renderChatList();
      renderActiveChat();
    });

    $chatList.on('click', '.wpc-chat-delete', function (e) {
      e.stopPropagation();
      const id = String($(this).closest('.wpc-chat-item').data('id') || '');
      if (!id) return;
      if (!window.confirm(cfg.i18n.confirmDeleteChat || 'Delete this chat?')) return;
      deleteChat(id);
    });

    // Onboarding events
    $('#wpc-ob-next').on('click', function () {
      if (currentSlide < TOTAL_SLIDES - 1) { currentSlide++; renderOnboarding(); }
    });
    $('#wpc-ob-prev').on('click', function () {
      if (currentSlide > 0) { currentSlide--; renderOnboarding(); }
    });
    $('#wpc-ob-skip').on('click', finishOnboarding);
    $('#wpc-ob-finish').on('click', finishOnboarding);
    $(document).on('click', '.wpc-ob-example', function () {
      var query = String($(this).data('query') || '');
      finishOnboarding();
      if (query) { $input.val(query).focus(); }
    });
  }

  function togglePanel() { $panel.is(':visible') ? closePanel() : openPanel(); }
  function openPanel() {
    $panel.stop(true).slideDown(180);
    $trigger.addClass('active').attr('aria-expanded', 'true');
    $input.focus();
    maybeShowOnboarding();
  }
  function closePanel() {
    if ($panel.hasClass('wpc-is-fullscreen')) toggleFullscreen();
    $panel.stop(true).slideUp(180);
    $trigger.removeClass('active').attr('aria-expanded', 'false');
  }
  function toggleFullscreen() {
    const on = $panel.toggleClass('wpc-is-fullscreen').hasClass('wpc-is-fullscreen');
    $fullscreen.find('.wpc-fs-expand').toggle(!on);
    $fullscreen.find('.wpc-fs-collapse').toggle(on);
    $fullscreen.attr('title', on ? 'Exit fullscreen' : 'Fullscreen');
    scrollBottom();
  }

  /* ── Onboarding ─────────────────────────────────────────────── */
  function maybeShowOnboarding() {
    if (localStorage.getItem('wpc_onboarded_v1')) return;
    $('#wpc-onboarding').fadeIn(200);
    currentSlide = 0;
    renderOnboarding();
  }

  function renderOnboarding() {
    $('.wpc-ob-slide').removeClass('active');
    $('.wpc-ob-slide[data-slide="' + currentSlide + '"]').addClass('active');
    $('.wpc-ob-dot').removeClass('active');
    $('.wpc-ob-dot[data-slide="' + currentSlide + '"]').addClass('active');
    $('#wpc-ob-prev').toggle(currentSlide > 0);
    $('#wpc-ob-next').toggle(currentSlide < TOTAL_SLIDES - 1);
    $('#wpc-ob-finish').toggle(currentSlide === TOTAL_SLIDES - 1);
  }

  function finishOnboarding() {
    localStorage.setItem('wpc_onboarded_v1', '1');
    $('#wpc-onboarding').fadeOut(200);
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
            action:   'wpc_chat_save',
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
    if (isBusy) abortCurrent();
    chat.messages = [];
    chat.title = cfg.i18n.newChat || 'New chat';
    chat.updatedAt = Date.now();
    persistChats();
    renderChatList();
    renderActiveChat();
  }

  function getActiveChat() {
    return chats.find(function (chat) { return chat.id === activeChatId; }) || null;
  }

  function renderChatList() {
    $chatList.empty();
    chats.forEach(function (chat) {
      const $item = $('<button type="button" class="wpc-chat-item">')
        .attr('data-id', chat.id)
        .toggleClass('is-active', chat.id === activeChatId);
      $item.append($('<span class="wpc-chat-item-title">').text(chat.title || (cfg.i18n.newChat || 'New chat')));
      $item.append($('<span class="wpc-chat-delete" title="' + escHtml(cfg.i18n.deleteChat || 'Delete chat') + '">').html('&times;'));
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
        $bot.html('<span class="wpc-error">⚠ ' + escHtml(msg.error) + '</span>');
        if (msg.sql && cfg.showSql) appendSqlBlock($bot, msg.sql);
        appendActions($bot);
      } else if (msg.data) {
        renderResult($bot, msg.data);
      }
    });

    scrollBottom();
  }

  function renderWelcome() {
    const $welcome = $('<div class="wpc-welcome">');
    $welcome.append($('<p>').text(cfg.i18n.emptyChat || 'Start a new conversation to keep context.'));
    const $examples = $('<div id="wpc-examples">');
    (cfg.i18n.examples || []).forEach(function (ex) {
      const $btn = $('<button class="wpc-chip">').text(ex);
      $btn.on('click', function () {
        const clean = ex.replace(/^[\u{1F000}-\u{1FFFF}\s]+/gu, '').trim();
        $input.val(clean);
        submitQuery();
      });
      $examples.append($btn);
    });
    $welcome.append($examples);
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

    $messages.find('.wpc-welcome').remove();
    appendMsg('user', escHtml(query));
    $input.val('');

    cfg.streaming ? sendStream(query, context) : sendAjax(query, context);
  }

  function sendStream(query, context) {
    setBusy(true);
    const $bot = appendMsg('bot', '', false, true);
    $bot.data('query', query);

    const controller = new AbortController();
    currentSSE = { close: function () { controller.abort(); } };

    const body = new URLSearchParams({
      action: 'wpc_stream',
      nonce: cfg.nonce,
      query: query,
      model: getSelectedModel(),
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
      $bot.find('.wpc-status').remove();
      $bot.append('<div class="wpc-status">' + escHtml(event.data) + '</div>');
      scrollBottom();
      return;
    }

    if (event.type === 'token') {
      let preview = $bot.find('.wpc-stream-preview');
      const next = (preview.data('raw') || '') + String(event.data || '');
      if (!preview.length) {
        preview = $('<div class="wpc-stream-preview"><span class="wpc-sp-badge">SQL</span><code class="wpc-sp-code"></code></div>');
        $bot.append(preview);
      }
      preview.data('raw', next);
      // Extract just the SQL value from the partial JSON stream
      const m = next.match(/"sql"\s*:\s*"((?:[^"\\]|\\.)*)/);
      const sqlText = m ? m[1].replace(/\\n/g, ' ').replace(/\\"/g, '"') : next;
      const display = sqlText.slice(-160); // show last 160 chars as it grows
      preview.find('.wpc-sp-code').text(display);
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

      if (result.new_nonce) cfg.nonce = result.new_nonce;
      $bot.empty();
      renderResult($bot, result);
      saveBotSuccess(query, result);
      setBusy(false);
      currentSSE = null;
      return;
    }

    if (event.type === 'error') {
      $bot.find('.wpc-status, .wpc-stream-preview').remove();
      if (event.sql && cfg.showSql) {
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
    $bot.html('<span class="wpc-dots"><span></span><span></span><span></span></span>');

    currentXhr = $.ajax({
      url: cfg.ajaxUrl,
      method: 'POST',
      timeout: 90000,
      data: {
        action: 'wpc_query',
        nonce: cfg.nonce,
        query: query,
        model: getSelectedModel(),
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
          handleBotError($bot, message, query, res.data && res.data.sql ? res.data.sql : '');
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
    $bot.html('<span class="wpc-error">⚠ ' + escHtml(message) + '</span>');
    if (sql && cfg.showSql) appendSqlBlock($bot, sql);
    appendActions($bot);
    saveBotError(query, message, sql || '');
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
    s = s.replace(/```[\w]*\n?([\s\S]*?)```/g, '<pre class="wpc-md-pre"><code>$1</code></pre>');

    // Markdown table: lines with | col | col |
    s = s.replace(/((?:\|[^\n]+\|\n?)+)/g, function(block) {
      const rows = block.trim().split('\n').filter(r => r.trim());
      if (rows.length < 2) return block;
      let html = '<div class="wpc-table-wrap"><table class="wpc-table wpc-md-table">';
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
    s = s.replace(/^### (.+)$/gm, '<h4 class="wpc-md-h">$1</h4>');
    s = s.replace(/^## (.+)$/gm,  '<h3 class="wpc-md-h">$1</h3>');
    s = s.replace(/^# (.+)$/gm,   '<h2 class="wpc-md-h">$1</h2>');

    // Unordered lists
    s = s.replace(/((?:^[*\-] .+\n?)+)/gm, function(block) {
      const items = block.trim().split('\n').map(l => '<li>' + l.replace(/^[*\-] /, '') + '</li>');
      return '<ul class="wpc-md-ul">' + items.join('') + '</ul>';
    });
    // Ordered lists
    s = s.replace(/((?:^\d+\. .+\n?)+)/gm, function(block) {
      const items = block.trim().split('\n').map(l => '<li>' + l.replace(/^\d+\. /, '') + '</li>');
      return '<ol class="wpc-md-ol">' + items.join('') + '</ol>';
    });

    // Bold, italic, inline code
    s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    s = s.replace(/\*(.+?)\*/g,     '<em>$1</em>');
    s = s.replace(/`([^`]+)`/g,     '<code class="wpc-md-code">$1</code>');

    // Paragraphs: double newlines
    s = s.split(/\n{2,}/).map(function(p) {
      p = p.trim();
      if (!p) return '';
      if (/^<(h[2-4]|ul|ol|pre|div|table)/.test(p)) return p;
      return '<p class="wpc-md-p">' + p.replace(/\n/g, '<br>') + '</p>';
    }).join('\n');

    return s;
  }

  function renderResult($bot, data) {
    if (data.explanation) {
      $bot.append($('<div class="wpc-explanation">').html(mdToHtml(data.explanation)));
    }
    if (data.html) {
      $bot.append($(data.html));
    }
    if (data.sql) {
      appendSqlBlock($bot, data.sql);
    }

    const meta = [];
    if (data.count > 1) meta.push(data.count + ' rows');
    if (data.tokens) meta.push('↑' + (data.tokens.in || 0) + ' ↓' + (data.tokens.out || 0) + ' tok');
    if (data.exec_ms) meta.push(data.exec_ms + 'ms');
    if (meta.length) {
      $bot.append($('<div class="wpc-meta">').text(meta.join(' · ')));
    }

    appendActions($bot);
    scrollBottom();
  }

  function appendSqlBlock($container, sql) {
    const $toggle = $('<button class="wpc-sql-toggle" type="button">').text('Show SQL ▾');
    const $code = $('<pre class="wpc-sql-code">').text(sql).hide();
    $toggle.on('click', function () {
      $code.toggle();
      $toggle.text($code.is(':visible') ? 'Hide SQL ▴' : 'Show SQL ▾');
    });
    $container.append($toggle).append($code);
  }

  function appendActions($container) {
    const query = String($container.data('query') || '');
    if (!query) return;
    $container.find('.wpc-actions').remove();

    const $actions = $('<div class="wpc-actions">');
    const $retry = $('<button type="button" class="wpc-retry-btn">').text(cfg.i18n.retry || 'Try again');
    $retry.on('click', function () {
      if (isBusy) return;
      $input.val(query);
      submitQuery();
    });
    $actions.append($retry);
    $container.append($actions);
  }

  function appendMsg(role, html, isError) {
    const $m = $('<div>').addClass('wpc-msg wpc-msg--' + role);
    if (isError) $m.addClass('wpc-msg--error');
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
    $send.toggleClass('wpc-send-btn--busy', busy);
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
      tokens: data.tokens || null
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
    $modelDropdown.find('.wpc-model-option').each(function () {
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
      const $li = $('<li class="wpc-model-option">').attr('data-value', entry[0]);
      $li.append($('<span>').text(entry[1]));
      $li.append($('<svg class="wpc-model-check" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>'));
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
      $modelDropdown.find('.wpc-model-option').each(function () {
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
    return 'wpc_model_override_' + String(cfg.providerKey || 'default');
  }

  function getChatsStorageKey() {
    return 'wpc_chats_' + String(cfg.providerKey || 'default');
  }

  function getActiveChatStorageKey() {
    return 'wpc_active_chat_' + String(cfg.providerKey || 'default');
  }

  function getSelectedModel() {
    return currentModel || cfg.model || '';
  }

  function updateBadge() {
    const providerLabel = cfg.providerLabel || cfg.provider || '';
    $badge.text(providerLabel);
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
        action:   'wpc_chat_save',
        nonce:    cfg.nonce,
        provider: cfg.providerKey || '',
        chat:     JSON.stringify(chat)
      });
    }, 600);
  }

  function dbLoadChats(callback) {
    $.post(cfg.ajaxUrl, {
      action:   'wpc_chats_load',
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
      action:  'wpc_chat_delete',
      nonce:   cfg.nonce,
      chat_id: chatId
    });
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
