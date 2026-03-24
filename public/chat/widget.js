/**
 * Malinco Chat Widget v4
 * Self-contained — CSS cu variabile CSS, config dinamic de pe server.
 *
 * Embed pe WooCommerce (footer):
 *   <script>window.MalincoChat = { apiUrl: 'https://erp.malinco.ro' };</script>
 *   <script src="https://erp.malinco.ro/chat/widget.js?v=4" defer></script>
 */
(function () {
  'use strict';

  var cfg      = window.MalincoChat || {};
  var API_URL  = (cfg.apiUrl || 'https://erp.malinco.ro').replace(/\/$/, '');
  // WooCommerce cart integration (opțional — activ doar dacă wcNonce e setat în embed)
  var WC_URL   = (cfg.wcUrl || window.location.origin).replace(/\/$/, '');
  var WC_NONCE = cfg.wcNonce || '';
  var cartCount = 0;

  // Valori inițiale (fallback dacă /chat/config nu răspunde)
  var PRIMARY  = cfg.primaryColor || '#e65100';
  var TITLE    = cfg.title        || 'Malinco';
  var SUBTITLE = cfg.subtitle     || 'Asistent virtual';
  var WELCOME  = cfg.welcomeMsg   || 'Bună ziua! Cu ce vă pot ajuta? Dacă doriți, puteți căuta un produs, verifica o comandă sau afla detalii despre livrare.';

  // ── Config cache în localStorage (elimină flash de culoare) ─────────────
  var CONFIG_KEY     = 'malinco_chat_config';
  var CONFIG_TTL_MS  = 5 * 60 * 1000; // 5 minute
  try {
    var _cfgRaw = localStorage.getItem(CONFIG_KEY);
    if (_cfgRaw) {
      var _cfgParsed = JSON.parse(_cfgRaw);
      if (_cfgParsed && Date.now() - (_cfgParsed.ts || 0) < CONFIG_TTL_MS) {
        if (_cfgParsed.primary_color) PRIMARY  = _cfgParsed.primary_color;
        if (_cfgParsed.bot_name)      TITLE    = _cfgParsed.bot_name;
        if (_cfgParsed.subtitle)      SUBTITLE = _cfgParsed.subtitle;
        if (_cfgParsed.welcome_msg)   WELCOME  = _cfgParsed.welcome_msg;
      }
    }
  } catch (e) {}

  var CACHE_TTL_MS = 15 * 60 * 1000;
  var SESSION_KEY  = 'malinco_chat_sid';
  var HISTORY_KEY  = 'malinco_chat_history';
  var WIN_STATE_KEY = 'malinco_chat_open'; // '1' = deschis, '0' = închis explicit
  var sessionId    = '';
  var msgHistory   = [];

  // ── Session + history din localStorage ──────────────────────────────────
  try {
    sessionId = localStorage.getItem(SESSION_KEY) || '';
    if (!sessionId) {
      sessionId = uuid();
      localStorage.setItem(SESSION_KEY, sessionId);
    }
    var raw = localStorage.getItem(HISTORY_KEY);
    if (raw) {
      var parsed = JSON.parse(raw);
      if (parsed && parsed.sid === sessionId && Array.isArray(parsed.msgs)) {
        var lastTs = parsed.msgs.length ? parsed.msgs[parsed.msgs.length - 1].ts : 0;
        if (Date.now() - lastTs < CACHE_TTL_MS) {
          msgHistory = parsed.msgs;
        } else {
          localStorage.removeItem(HISTORY_KEY);
          localStorage.removeItem(SESSION_KEY);
          sessionId = uuid();
          localStorage.setItem(SESSION_KEY, sessionId);
        }
      }
    }
  } catch (e) { sessionId = 'sess-' + Date.now(); }

  function uuid() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = Math.random() * 16 | 0;
      return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
  }

  function saveHistory() {
    try { localStorage.setItem(HISTORY_KEY, JSON.stringify({ sid: sessionId, msgs: msgHistory })); } catch (e) {}
  }

  // ── CSS inline cu variabile CSS ─────────────────────────────────────────
  var CSS = '\
#malinco-chat-wrapper{--mc-primary:#e65100}\
#malinco-chat-wrapper*{box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;margin:0;padding:0}\
#malinco-chat-btn{position:fixed;bottom:80px;right:20px;width:56px;height:56px;border-radius:50%;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 16px rgba(0,0,0,.25);z-index:9998;transition:transform .2s,box-shadow .2s;outline:none;background:var(--mc-primary)}\
#malinco-chat-btn:hover{transform:scale(1.08);box-shadow:0 6px 20px rgba(0,0,0,.3)}\
#malinco-chat-btn svg{pointer-events:none}\
#malinco-chat-window{position:fixed;bottom:148px;right:20px;width:360px;max-height:640px;display:flex;flex-direction:column;border-radius:16px;box-shadow:0 8px 32px rgba(0,0,0,.18);overflow:hidden;z-index:9999;background:#fff;transform:translateY(20px);opacity:0;pointer-events:none;transition:transform .25s,opacity .25s}\
#malinco-chat-window.mc-open{transform:translateY(0);opacity:1;pointer-events:all}\
#malinco-chat-header{display:flex;align-items:center;padding:14px 16px;color:#fff;flex-shrink:0;background:var(--mc-primary)}\
.mc-avatar{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;margin-right:10px;flex-shrink:0}\
.mc-title{flex:1}.mc-title strong{display:block;font-size:14px;font-weight:600;line-height:1.2}\
.mc-title span{font-size:11px;opacity:.85}\
#malinco-chat-close{background:none;border:none;color:#fff;cursor:pointer;padding:4px;border-radius:50%;display:flex;align-items:center;justify-content:center;opacity:.8;transition:opacity .15s}\
#malinco-chat-close:hover{opacity:1}\
#malinco-chat-messages{flex:1;overflow-y:auto;padding:14px 12px;display:flex;flex-direction:column;gap:8px;background:#f4f4f4;min-height:200px;max-height:490px}\
#malinco-chat-messages::-webkit-scrollbar{width:4px}\
#malinco-chat-messages::-webkit-scrollbar-thumb{background:#ccc;border-radius:4px}\
.mc-msg-wrap{display:flex;flex-direction:column;max-width:100%}\
.mc-msg-wrap-user{align-items:flex-end}\
.mc-msg-wrap-bot{align-items:flex-start}\
.mc-msg{max-width:85%;padding:9px 13px;border-radius:14px;font-size:13.5px;line-height:1.55;word-break:break-word;white-space:pre-wrap}\
.mc-msg-bot{background:#fff;color:#222;border-bottom-left-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,.08)}\
.mc-msg-user{color:#fff;border-bottom-right-radius:4px;background:var(--mc-primary)}\
.mc-typing{display:flex;align-items:center;gap:8px;padding:9px 13px;background:#fff;border-radius:14px;border-bottom-left-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,.08);align-self:flex-start;width:fit-content}\
.mc-typing-avatar{width:24px;height:24px;border-radius:50%;background:var(--mc-primary);display:flex;align-items:center;justify-content:center;flex-shrink:0;animation:mc-pulse 1.5s ease-in-out infinite}\
.mc-typing-avatar svg{fill:#fff}\
.mc-typing-dots{display:flex;align-items:center;gap:4px}\
.mc-typing-dots span{width:7px;height:7px;border-radius:50%;background:#bbb;animation:mc-bounce 1.4s ease-in-out infinite}\
.mc-typing-dots span:nth-child(2){animation-delay:.2s}.mc-typing-dots span:nth-child(3){animation-delay:.4s}\
@keyframes mc-bounce{0%,80%,100%{transform:scale(0.6);opacity:.5}40%{transform:scale(1);opacity:1}}\
@keyframes mc-pulse{0%,100%{opacity:1}50%{opacity:.7}}\
.mc-products{display:flex;flex-direction:column;gap:7px;margin-top:7px;width:100%;max-width:310px}\
.mc-product-card{display:flex;align-items:stretch;background:#fff;border-radius:10px;overflow:hidden;text-decoration:none;box-shadow:0 1px 5px rgba(0,0,0,.1);border:1px solid #eee;transition:box-shadow .15s,border-color .15s}\
.mc-product-card:hover{box-shadow:0 3px 12px rgba(0,0,0,.15);border-color:var(--mc-primary)}\
.mc-card-img{width:72px;min-height:72px;flex-shrink:0;overflow:hidden;background:#f0f0f0;display:flex;align-items:center;justify-content:center}\
.mc-card-img img{width:72px;height:72px;object-fit:cover;display:block}\
.mc-card-no-img{width:72px;height:72px;display:flex;align-items:center;justify-content:center;font-size:24px;color:#ccc;background:linear-gradient(135deg,#f5f5f5,#e8e8e8)}\
.mc-card-info{padding:7px 10px;flex:1;overflow:hidden;display:flex;flex-direction:column;justify-content:center;gap:2px}\
.mc-card-name{font-size:12px;color:#222;font-weight:500;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;line-height:1.3}\
.mc-card-price{font-size:12.5px;font-weight:600;display:block;color:var(--mc-primary)}\
.mc-card-link{font-size:11px;color:#888;display:flex;align-items:center;gap:3px;margin-top:1px}\
.mc-stock{font-size:10px;padding:1px 5px;border-radius:4px;display:inline-block;margin-top:2px;font-weight:500}\
.mc-stock-in{background:#e8f5e9;color:#2e7d32}.mc-stock-out{background:#fff3e0;color:#e65100}\
.mc-add-cart{margin-top:5px;padding:4px 8px!important;border-radius:6px!important;border:none!important;cursor:pointer!important;font-size:11px!important;font-weight:600!important;color:#fff!important;background:var(--mc-primary)!important;display:inline-flex!important;align-items:center!important;gap:4px!important;transition:opacity .15s;align-self:flex-start!important;width:auto!important;min-width:0!important;max-width:fit-content!important;line-height:1.4!important;box-shadow:none!important;text-transform:none!important;letter-spacing:normal!important}\
.mc-add-cart:hover{opacity:.85!important}.mc-add-cart:disabled{opacity:.5!important;cursor:default!important}\
.mc-cart-badge{position:absolute;top:-5px;right:-5px;min-width:18px;height:18px;border-radius:9px;background:#e53935;color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;padding:0 4px;pointer-events:none}\
.mc-msg a{color:var(--mc-primary);text-decoration:underline}\
.mc-msg a:hover{opacity:.8}\
.mc-contact-form{background:#fff;border:1px solid #e0e0e0;border-radius:12px;padding:14px 14px 12px;margin-top:7px;width:100%;max-width:310px;box-shadow:0 1px 5px rgba(0,0,0,.08)}\
.mc-contact-form-title{font-size:12.5px;font-weight:600;color:#333;margin-bottom:10px;display:flex;align-items:center;gap:6px}\
.mc-cf-field{display:flex;align-items:center;gap:8px;border:1px solid #ddd;border-radius:8px;padding:7px 10px;margin-bottom:7px;background:#fafafa;transition:border-color .15s}\
.mc-cf-field:focus-within{border-color:var(--mc-primary);background:#fff}\
.mc-cf-field svg{flex-shrink:0;color:#999;fill:#999}\
.mc-cf-field input{flex:1;border:none;background:none;outline:none;font-size:12.5px;color:#333;min-width:0}\
.mc-cf-field input::placeholder{color:#bbb}\
.mc-cf-check{display:flex;align-items:flex-start;gap:8px;margin-bottom:10px;cursor:pointer}\
.mc-cf-check input[type=checkbox]{flex-shrink:0;width:15px;height:15px;margin-top:1px;accent-color:var(--mc-primary);cursor:pointer}\
.mc-cf-check span{font-size:11.5px;color:#555;line-height:1.4}\
.mc-cf-submit{width:100%!important;padding:9px!important;border-radius:8px!important;border:none!important;background:var(--mc-primary)!important;color:#fff!important;font-size:13px!important;font-weight:600!important;cursor:pointer!important;transition:opacity .15s;display:flex!important;align-items:center!important;justify-content:center!important;gap:6px!important}\
.mc-cf-submit:hover{opacity:.88!important}.mc-cf-submit:disabled{opacity:.5!important;cursor:default!important}\
.mc-cf-success{display:flex;align-items:center;gap:8px;padding:10px 12px;background:#e8f5e9;border-radius:8px;margin-top:4px}\
.mc-cf-success svg{color:#2e7d32;fill:#2e7d32;flex-shrink:0}\
.mc-cf-success span{font-size:12.5px;color:#2e7d32;font-weight:500}\
#malinco-chat-footer{padding:10px 12px;background:#fff;border-top:1px solid #eee;display:flex;align-items:center;gap:6px;flex-shrink:0}\
.mc-new-conv-bar{display:flex;align-items:center;justify-content:center;gap:8px;padding:10px 12px;background:#f8f4ff;border-bottom:1px solid #e8e0f8;cursor:pointer;transition:background .15s}\
.mc-new-conv-bar:hover{background:#efe8ff}\
.mc-new-conv-bar span{font-size:12.5px;color:#6d4cad;font-weight:500}\
.mc-new-conv-bar svg{color:#6d4cad;fill:#6d4cad}\
#malinco-chat-input{flex:1;border:1px solid #ddd;border-radius:20px;padding:8px 16px;font-size:13.5px;outline:none;color:#333;line-height:1.4;transition:border-color .15s;background:#fff}\
#malinco-chat-input:focus{border-color:var(--mc-primary)}\
#malinco-chat-send{background:none;border:none;cursor:pointer;padding:4px 6px;display:flex;align-items:center;justify-content:center;flex-shrink:0;outline:none;opacity:.7;transition:opacity .15s}\
#malinco-chat-send:hover{opacity:1}\
#malinco-chat-send svg{fill:var(--mc-primary);pointer-events:none}\
@media(max-width:480px){#malinco-chat-window{right:8px;left:8px;width:auto;bottom:148px;max-height:75vh}}\
';

  var styleEl = document.createElement('style');
  styleEl.textContent = CSS;
  document.head.appendChild(styleEl);

  // ── SVG icons ────────────────────────────────────────────────────────────
  var IC_CHAT  = '<svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="white"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>';
  var IC_CLOSE = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="white"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>';
  var IC_SEND  = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="white"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>';
  var IC_BOT   = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="white"><path d="M20 9V7c0-1.1-.9-2-2-2h-3V3h-2v2H9V3H7v2H4c-1.1 0-2 .9-2 2v2H0v2h2v4H0v2h2v2c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2v-2h2v-2h-2v-4h2V9h-2zm-4 9H8v-2h8v2zm0-4H8v-2h8v2zm0-4H8V8h8v2z"/></svg>';
  var IC_EXT   = '<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><path d="M19 19H5V5h7V3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7h-2v7zM14 3v2h3.59l-9.83 9.83 1.41 1.41L19 6.41V10h2V3h-7z"/></svg>';
  var IC_CART  = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><path d="M7 18c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm10 0c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm-9.8-3h11.45c.75 0 1.41-.41 1.75-1.03L22 6H6L4.27 2H1v2h2l3.6 7.59L5.25 14c-.16.28-.25.61-.25.96C5 16.1 5.9 17 7 17h13v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12z"/></svg>';
  var IC_CHECK = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>';
  var IC_EMAIL = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>';
  var IC_PHONE = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg>';
  var IC_OK    = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z" style="display:none"/><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>';

  // ── DOM ───────────────────────────────────────────────────────────────────
  var wrapper = document.createElement('div');
  wrapper.id  = 'malinco-chat-wrapper';

  var btn     = document.createElement('button');
  btn.id      = 'malinco-chat-btn';
  btn.setAttribute('aria-label', 'Deschide chat');
  btn.innerHTML = IC_CHAT;

  var chatWin = document.createElement('div');
  chatWin.id  = 'malinco-chat-window';

  var header  = document.createElement('div');
  header.id   = 'malinco-chat-header';

  // Avatar + titlu ca elemente separate (pentru update ulterior)
  var avatarEl = document.createElement('div');
  avatarEl.className = 'mc-avatar';
  avatarEl.innerHTML = IC_BOT;

  var titleWrap = document.createElement('div');
  titleWrap.className = 'mc-title';

  var titleEl = document.createElement('strong');
  titleEl.textContent = TITLE;

  var subtitleEl = document.createElement('span');
  subtitleEl.textContent = SUBTITLE;

  titleWrap.appendChild(titleEl);
  titleWrap.appendChild(subtitleEl);

  var closeBtn = document.createElement('button');
  closeBtn.id  = 'malinco-chat-close';
  closeBtn.setAttribute('aria-label', 'Închide');
  closeBtn.innerHTML = IC_CLOSE;

  header.appendChild(avatarEl);
  header.appendChild(titleWrap);
  header.appendChild(closeBtn);

  var msgList = document.createElement('div');
  msgList.id  = 'malinco-chat-messages';

  // Banner "Conversație nouă" — apare deasupra mesajelor (ascuns inițial)
  var newConvBar = document.createElement('div');
  newConvBar.className = 'mc-new-conv-bar';
  newConvBar.style.display = 'none';
  newConvBar.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"><path d="M17.65 6.35A7.96 7.96 0 0 0 12 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 7.99 8c3.73 0 6.84-2.55 7.73-6h-2.08A5.99 5.99 0 0 1 12 18c-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z"/></svg><span>Începe o conversație nouă</span>';

  var footer  = document.createElement('div');
  footer.id   = 'malinco-chat-footer';

  var input   = document.createElement('input');
  input.type  = 'text';
  input.id    = 'malinco-chat-input';
  input.placeholder = 'Scrieți un mesaj...';
  input.maxLength = 500;
  input.setAttribute('enterkeyhint', 'send');
  input.setAttribute('autocomplete', 'off');

  var sendBtn = document.createElement('button');
  sendBtn.id  = 'malinco-chat-send';
  sendBtn.setAttribute('aria-label', 'Trimite');
  sendBtn.innerHTML = IC_SEND;

  footer.appendChild(input);
  footer.appendChild(sendBtn);
  chatWin.appendChild(header);
  chatWin.appendChild(newConvBar);
  chatWin.appendChild(msgList);
  chatWin.appendChild(footer);
  wrapper.appendChild(chatWin);
  wrapper.appendChild(btn);
  document.body.appendChild(wrapper);

  // ── Aplică config (culori + texte) + salvează în localStorage ───────────
  function applyConfig(config) {
    var changed = false;
    if (config.primary_color && config.primary_color !== PRIMARY) {
      PRIMARY = config.primary_color;
      wrapper.style.setProperty('--mc-primary', PRIMARY);
      changed = true;
    }
    if (config.bot_name   && config.bot_name   !== TITLE)    { TITLE    = config.bot_name;   titleEl.textContent    = TITLE;    changed = true; }
    if (config.subtitle   && config.subtitle   !== SUBTITLE) { SUBTITLE = config.subtitle;   subtitleEl.textContent = SUBTITLE; changed = true; }
    if (config.welcome_msg && config.welcome_msg !== WELCOME) { WELCOME = config.welcome_msg; changed = true; }

    if (changed || !localStorage.getItem(CONFIG_KEY)) {
      try {
        localStorage.setItem(CONFIG_KEY, JSON.stringify({
          primary_color: config.primary_color,
          bot_name:      config.bot_name,
          subtitle:      config.subtitle,
          welcome_msg:   config.welcome_msg,
          ts:            Date.now(),
        }));
      } catch (e) {}
    }
  }

  // Aplică imediat culoarea corectă (din cache deja setată în PRIMARY)
  wrapper.style.setProperty('--mc-primary', PRIMARY);

  // Fetch config de pe server în fundal (actualizează cache-ul)
  try {
    fetch(API_URL + '/chat/config', { method: 'GET', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) { if (data) applyConfig(data); })
      .catch(function () {});
  } catch (e) {}

  // ── Cart ──────────────────────────────────────────────────────────────────
  var cartBadge = null; // creat lazy la prima actualizare

  // Nonce proaspăt: WooCommerce Blocks îl expune global → mereu valid
  function getWcNonce() {
    return (window.wcSettings && window.wcSettings.storeApiNonce) || WC_NONCE || '';
  }

  // Cart activ dacă avem nonce (embed sau wcSettings)
  function cartEnabled() {
    return !!getWcNonce();
  }

  function updateCartBadge(count) {
    cartCount = count || 0;
    if (!cartEnabled()) return;
    if (!cartBadge) {
      cartBadge = document.createElement('span');
      cartBadge.className = 'mc-cart-badge';
      btn.appendChild(cartBadge);
    }
    cartBadge.textContent = cartCount;
    cartBadge.style.display = cartCount > 0 ? 'flex' : 'none';
  }

  function fetchCartCount() {
    var nonce = getWcNonce();
    if (!nonce) return;
    fetch(WC_URL + '/wp-json/wc/store/v1/cart', {
      credentials: 'include',
      headers: { 'Nonce': nonce }
    }).then(function(r) { return r.ok ? r.json() : null; })
      .then(function(d) { if (d) updateCartBadge(d.items_count || 0); })
      .catch(function() {});
  }

  function addToCart(wooId, name, btnEl) {
    var nonce = getWcNonce();
    if (!nonce) return;
    btnEl.disabled = true;
    btnEl.innerHTML = IC_CART + ' Se adaugă...';
    fetch(WC_URL + '/wp-json/wc/store/v1/cart/add-item', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'Nonce': nonce },
      body: JSON.stringify({ id: wooId, quantity: 1 })
    }).then(function(r) { return r.ok ? r.json() : Promise.reject(r.status); })
      .then(function(d) {
        btnEl.innerHTML = IC_CHECK + ' Adăugat!';
        updateCartBadge(d.items_count || cartCount + 1);
        // Actualizează mini-cart-ul WooCommerce din header fără refresh
        try {
          if (window.jQuery) {
            window.jQuery(document.body).trigger('wc_fragment_refresh');
          }
          document.body.dispatchEvent(new CustomEvent('wc-blocks_added_to_cart', { bubbles: true }));
        } catch (e) {}
        setTimeout(function() {
          btnEl.innerHTML = IC_CART + ' Adaugă în coș';
          btnEl.disabled = false;
        }, 2500);
      })
      .catch(function() {
        btnEl.innerHTML = IC_CART + ' Eroare — reîncearcă';
        setTimeout(function() {
          btnEl.innerHTML = IC_CART + ' Adaugă în coș';
          btnEl.disabled = false;
        }, 2000);
      });
  }

  function showCartContents() {
    if (!WC_NONCE) {
      renderMessage('bot', 'Pentru a vedea coșul, accesați pagina coș de pe site.', []);
      return;
    }
    fetch(WC_URL + '/wp-json/wc/store/v1/cart', {
      credentials: 'include',
      headers: { 'Nonce': WC_NONCE }
    }).then(function(r) { return r.ok ? r.json() : null; })
      .then(function(d) {
        if (!d || !d.items || d.items.length === 0) {
          renderMessage('bot', 'Coșul dvs. este gol.', []);
          return;
        }
        var lines = d.items.map(function(it) {
          return it.name + ' × ' + it.quantity + (it.prices ? ' — ' + (it.prices.price / 100).toFixed(2) + ' RON' : '');
        });
        var total = d.totals ? (parseInt(d.totals.total_price || 0) / 100).toFixed(2) + ' RON' : '';
        renderMessage('bot', 'Coșul dvs.:\n' + lines.join('\n') + (total ? '\n\nTotal: ' + total : ''), []);
      })
      .catch(function() { renderMessage('bot', 'Nu am putut accesa coșul. Reîncercați.', []); });
  }

  // ── Linkify telefoane și emailuri în textul botului ───────────────────────
  function linkifyText(text) {
    var html = text
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    // Telefoane RO: 07xx xxx xxx sau 0xxx xxx xxx sau 0xxx-xxx-xxx
    html = html.replace(/(\b0[0-9]{2,3}[\s\-]?[0-9]{3}[\s\-]?[0-9]{3,4}\b)/g,
      '<a href="tel:$1">$1</a>');
    // Emailuri
    html = html.replace(/([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/g,
      '<a href="mailto:$1">$1</a>');
    return html;
  }

  // ── State ──────────────────────────────────────────────────────────────────
  var isOpen = false, isLoading = false, welcomed = false;
  var contactFormShown = false; // afișat o singură dată per sesiune

  // ── Render mesaj ───────────────────────────────────────────────────────────
  function renderMessage(role, content, products, persist) {
    var wrap = document.createElement('div');
    wrap.className = 'mc-msg-wrap mc-msg-wrap-' + role;

    // Balon text
    var msg = document.createElement('div');
    msg.className = 'mc-msg mc-msg-' + role;
    if (role === 'bot') {
      msg.innerHTML = linkifyText(content); // activează tel:/mailto: links
    } else {
      msg.textContent = content;
    }
    wrap.appendChild(msg);

    // Carduri produse (sub mesajul botului)
    if (role === 'bot' && products && products.length > 0) {
      var cardsWrap = document.createElement('div');
      cardsWrap.className = 'mc-products';

      products.forEach(function (p) {
        var card = document.createElement('a');
        card.className = 'mc-product-card';
        card.href      = p.url || '#';

        // Imagine via DOM (fără onerror inline — evită CSP issues)
        var imgWrap = document.createElement('div');
        imgWrap.className = 'mc-card-img';

        if (p.image_url) {
          var imgEl = document.createElement('img');
          imgEl.alt     = p.name;
          imgEl.loading = 'lazy';
          imgEl.addEventListener('error', function () {
            imgWrap.innerHTML = '<div class="mc-card-no-img">🏗</div>';
          });
          imgEl.src = p.image_url;
          imgWrap.appendChild(imgEl);
        } else {
          imgWrap.innerHTML = '<div class="mc-card-no-img">🏗</div>';
        }

        // Info
        var info = document.createElement('div');
        info.className = 'mc-card-info';

        var nameEl = document.createElement('span');
        nameEl.className = 'mc-card-name';
        nameEl.textContent = p.name;
        info.appendChild(nameEl);

        if (p.price) {
          var priceEl = document.createElement('span');
          priceEl.className = 'mc-card-price';
          priceEl.textContent = p.price + (p.unit ? ' / ' + p.unit : '');
          info.appendChild(priceEl);
        }

        var stockEl = document.createElement('span');
        stockEl.className = 'mc-stock ' + (p.stock_available ? 'mc-stock-in' : 'mc-stock-out');
        stockEl.textContent = p.stock_available ? 'În stoc' : 'Stoc limitat';
        info.appendChild(stockEl);

        // Buton "Adaugă în coș" — vizibil dacă cart-ul e activ și produsul e în stoc
        if (cartEnabled() && p.woo_id && p.stock_available) {
          var cartBtn = document.createElement('button');
          cartBtn.className = 'mc-add-cart';
          cartBtn.innerHTML = IC_CART + ' Adaugă în coș';
          (function(wId, n, b) {
            b.addEventListener('click', function(e) {
              e.preventDefault(); e.stopPropagation();
              addToCart(wId, n, b);
            });
          })(p.woo_id, p.name, cartBtn);
          info.appendChild(cartBtn);
        }

        card.appendChild(imgWrap);
        card.appendChild(info);
        cardsWrap.appendChild(card);
      });

      wrap.appendChild(cardsWrap);
    }

    msgList.appendChild(wrap);

    // Scroll la începutul mesajului nou (nu la capăt — altfel textul dispare sub carduri)
    var isNearBottom = msgList.scrollHeight - msgList.scrollTop - msgList.clientHeight < 120;
    if (isNearBottom) {
      // Era aproape de capăt → scroll la începutul noului mesaj
      requestAnimationFrame(function () {
        wrap.scrollIntoView({ block: 'start', behavior: 'smooth' });
      });
    }

    if (persist !== false) {
      msgHistory.push({ role: role, content: content, products: products || [], ts: Date.now() });
      saveHistory();
    }
  }

  function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
  function scrollBottom() { msgList.scrollTop = msgList.scrollHeight; }

  // ── Formular grafic de contact ─────────────────────────────────────────────
  function renderContactForm(message) {
    if (contactFormShown) return;
    contactFormShown = true;

    var wrap = document.createElement('div');
    wrap.className = 'mc-msg-wrap mc-msg-wrap-bot';

    var card = document.createElement('div');
    card.className = 'mc-contact-form';

    // Titlu
    var title = document.createElement('div');
    title.className = 'mc-contact-form-title';
    title.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" style="color:var(--mc-primary)"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>';
    title.appendChild(document.createTextNode(message || 'Lăsați datele dvs. de contact'));
    card.appendChild(title);

    // Câmp email
    var emailWrap = document.createElement('div');
    emailWrap.className = 'mc-cf-field';
    emailWrap.innerHTML = IC_EMAIL;
    var emailInput = document.createElement('input');
    emailInput.type = 'email';
    emailInput.placeholder = 'Email (opțional)';
    emailInput.autocomplete = 'email';
    emailWrap.appendChild(emailInput);
    card.appendChild(emailWrap);

    // Câmp telefon
    var phoneWrap = document.createElement('div');
    phoneWrap.className = 'mc-cf-field';
    phoneWrap.innerHTML = IC_PHONE;
    var phoneInput = document.createElement('input');
    phoneInput.type = 'tel';
    phoneInput.placeholder = 'Telefon (opțional)';
    phoneInput.autocomplete = 'tel';
    phoneWrap.appendChild(phoneInput);
    card.appendChild(phoneWrap);

    // Checkbox specialist
    var checkLabel = document.createElement('label');
    checkLabel.className = 'mc-cf-check';
    var checkInput = document.createElement('input');
    checkInput.type = 'checkbox';
    checkInput.checked = true;
    var checkSpan = document.createElement('span');
    checkSpan.textContent = 'Doresc să fiu contactat de un specialist Malinco';
    checkLabel.appendChild(checkInput);
    checkLabel.appendChild(checkSpan);
    card.appendChild(checkLabel);

    // Buton submit
    var submitBtn = document.createElement('button');
    submitBtn.className = 'mc-cf-submit';
    submitBtn.innerHTML = IC_CHECK + ' Trimite';

    submitBtn.addEventListener('click', function() {
      var email = emailInput.value.trim();
      var phone = phoneInput.value.trim();
      if (!email && !phone) {
        emailInput.style.borderColor = 'red';
        phoneInput.parentElement.style.borderColor = 'red';
        return;
      }
      submitBtn.disabled = true;
      submitBtn.innerHTML = '...';

      fetch(API_URL + '/chat/contact', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({
          session_id:       sessionId,
          email:            email || null,
          phone:            phone || null,
          wants_specialist: checkInput.checked,
        }),
      })
      .then(function(r) { return r.ok ? r.json() : Promise.reject(); })
      .then(function() {
        // Înlocuiește formularul cu mesaj de succes
        card.innerHTML = '';
        var success = document.createElement('div');
        success.className = 'mc-cf-success';
        success.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>';
        var txt = document.createElement('span');
        txt.textContent = 'Mulțumim! Vă vom contacta în cel mai scurt timp.';
        success.appendChild(txt);
        card.appendChild(success);
        requestAnimationFrame(function() { wrap.scrollIntoView({ block: 'start', behavior: 'smooth' }); });
      })
      .catch(function() {
        submitBtn.disabled = false;
        submitBtn.innerHTML = IC_CHECK + ' Reîncearcă';
      });
    });

    card.appendChild(submitBtn);
    wrap.appendChild(card);
    msgList.appendChild(wrap);
    requestAnimationFrame(function() { wrap.scrollIntoView({ block: 'start', behavior: 'smooth' }); });
  }

  function showTyping() {
    var t = document.createElement('div');
    t.className = 'mc-typing'; t.id = 'mc-typing';

    // Avatar animat
    var av = document.createElement('div');
    av.className = 'mc-typing-avatar';
    av.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24"><path d="M20 9V7c0-1.1-.9-2-2-2h-3V3h-2v2H9V3H7v2H4c-1.1 0-2 .9-2 2v2H0v2h2v4H0v2h2v2c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2v-2h2v-2h-2v-4h2V9h-2zm-4 9H8v-2h8v2zm0-4H8v-2h8v2zm0-4H8V8h8v2z"/></svg>';

    // Puncte animate
    var dots = document.createElement('div');
    dots.className = 'mc-typing-dots';
    for (var i = 0; i < 3; i++) dots.appendChild(document.createElement('span'));

    t.appendChild(av);
    t.appendChild(dots);
    msgList.appendChild(t);
    msgList.scrollTop = msgList.scrollHeight;
  }
  function hideTyping() { var t = document.getElementById('mc-typing'); if (t) t.remove(); }

  // ── Restabilire conversație din localStorage ────────────────────────────
  function restoreHistory() {
    msgHistory.forEach(function (m) { renderMessage(m.role, m.content, m.products, false); });
  }

  // ── Open / Close ──────────────────────────────────────────────────────────
  function openChat() {
    isOpen = true;
    chatWin.classList.add('mc-open');
    btn.innerHTML = IC_CLOSE;
    try { localStorage.setItem(WIN_STATE_KEY, '1'); } catch (e) {}

    if (!welcomed) {
      welcomed = true;
      if (msgHistory.length > 0) {
        restoreHistory();
        requestAnimationFrame(function () { msgList.scrollTop = msgList.scrollHeight; });
      } else {
        renderMessage('bot', WELCOME, [], false);
      }
    }
    scheduleNewConvBar();
    setTimeout(function () { input.focus(); }, 280);
  }

  function closeChat() {
    isOpen = false;
    chatWin.classList.remove('mc-open');
    btn.innerHTML = IC_CHAT;
    try { localStorage.setItem(WIN_STATE_KEY, '0'); } catch (e) {}
  }

  // ── Trimite mesaj ──────────────────────────────────────────────────────────
  function sendMessage() {
    if (isLoading) return;
    var text = input.value.trim();
    if (!text) return;

    input.value = '';
    renderMessage('user', text, []);
    isLoading = true; showTyping();
    hideNewConvBar(); // ascunde bannerul cât timp utilizatorul e activ
    scheduleNewConvBar(); // repornește timer-ul de 3 minute

    var controller = new AbortController();
    var timeoutId = setTimeout(function () { controller.abort(); }, 15000); // 15s timeout

    fetch(API_URL + '/chat/message', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body:    JSON.stringify({
        message:    text,
        session_id: sessionId,
        page_url:   window.location.href,
        page_title: document.title || '',
      }),
      signal: controller.signal,
    })
    .then(function (r) {
      clearTimeout(timeoutId);
      if (r.status === 429) throw new Error('429');
      if (!r.ok) throw new Error(r.status);
      return r.json();
    })
    .then(function (d) {
      hideTyping(); isLoading = false;
      if (d.session_id && d.session_id !== sessionId) {
        sessionId = d.session_id;
        try { localStorage.setItem(SESSION_KEY, sessionId); } catch (e) {}
      }
      renderMessage('bot', d.reply || '...', d.products || []);
      if (d.contact_form) {
        renderContactForm(d.contact_form_message || '');
      }
    })
    .catch(function (e) {
      clearTimeout(timeoutId);
      hideTyping(); isLoading = false;
      if (e.name === 'AbortError') {
        renderMessage('bot', 'Răspunsul durează prea mult. Te rugăm să încerci din nou.', []);
      } else {
        renderMessage('bot', e.message === '429' ? 'Prea multe mesaje. Așteptați un moment.' : 'Eroare de conexiune. Reîncercați.', []);
      }
    });
  }

  // ── Events ─────────────────────────────────────────────────────────────────
  btn.addEventListener('click', function () { isOpen ? closeChat() : openChat(); });
  closeBtn.addEventListener('click', closeChat);
  sendBtn.addEventListener('click', sendMessage);
  input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); sendMessage(); } });
  document.addEventListener('click', function (e) { if (isOpen && !wrapper.contains(e.target)) closeChat(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && isOpen) closeChat(); });

  // ── Conversație nouă ───────────────────────────────────────────────────────
  var newConvTimer = null;

  function showNewConvBar() {
    newConvBar.style.display = 'flex';
  }

  function hideNewConvBar() {
    newConvBar.style.display = 'none';
    clearTimeout(newConvTimer);
    newConvTimer = null;
  }

  function scheduleNewConvBar() {
    clearTimeout(newConvTimer);
    // Arată bannerul după 3 minute de inactivitate (sau imediat dacă nu există conversație)
    var delay = msgHistory.length === 0 ? 0 : 3 * 60 * 1000;
    newConvTimer = setTimeout(showNewConvBar, delay);
  }

  function startNewConversation() {
    sessionId = uuid();
    msgHistory = [];
    contactFormShown = false;
    welcomed = true;
    try {
      localStorage.setItem(SESSION_KEY, sessionId);
      localStorage.removeItem(HISTORY_KEY);
      localStorage.removeItem(WIN_STATE_KEY);
    } catch (e) {}
    msgList.innerHTML = '';
    hideNewConvBar();
    renderMessage('bot', WELCOME, [], false);
    input.focus();
  }

  newConvBar.addEventListener('click', startNewConversation);

  // ── Auto-open dacă fereastra era deschisă la ultima vizită ─────────────────
  try {
    if (localStorage.getItem(WIN_STATE_KEY) === '1') {
      // Mic delay ca pagina să fie randată complet înainte de animație
      setTimeout(openChat, 400);
    }
  } catch (e) {}

})();
