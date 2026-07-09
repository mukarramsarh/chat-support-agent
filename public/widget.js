/*!
 * support-ai · embeddable chat widget
 * One shortcode, zero dependencies, fully isolated in a Shadow DOM so it never
 * fights the host site's CSS (and the host can't break it).
 *
 *   <script src="https://your-host/widget.js" data-agent="AGENT_PUBLIC_ID" defer></script>
 *
 * The widget self-configures from its own <script> tag: the API base is derived
 * from the script src, so there is nothing else to wire up.
 */
(function () {
  'use strict';

  // ── Resolve our own script + API base ──────────────────────────────────
  var self = document.currentScript;
  if (!self) {
    var scripts = document.getElementsByTagName('script');
    for (var i = scripts.length - 1; i >= 0; i--) {
      if (/widget\.js(\?|$)/.test(scripts[i].src)) { self = scripts[i]; break; }
    }
  }
  var API_BASE = new URL(self.src).origin + new URL(self.src).pathname.replace(/\/widget\.js.*$/, '');
  var AGENT = self.getAttribute('data-agent') || '';

  // ── Persistent visitor + conversation identity ─────────────────────────
  var LS = window.localStorage;
  function id(key, make) {
    var v = LS.getItem(key);
    if (!v && make) { v = make(); LS.setItem(key, v); }
    return v;
  }
  var visitorId = id('sa_visitor', function () {
    return 'v_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
  });
  var conversationId = id('sa_conversation_' + AGENT, null);

  var cfg = {
    primary: '#4f46e5', accent: '#7c3aed', position: 'right',
    launcher: '💬', title: 'Support', subtitle: 'Typically replies instantly',
    welcome: 'Hi! How can I help you today?'
  };

  // ── Boot: fetch config, then render ────────────────────────────────────
  fetch(API_BASE + '/api/widget/config')
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (data) {
      if (data && data.agent) {
        var t = data.agent.theme || {};
        cfg.primary = t.primary || cfg.primary;
        cfg.accent = t.accent || cfg.accent;
        cfg.position = t.position || cfg.position;
        cfg.launcher = t.launcher || cfg.launcher;
        cfg.title = t.title || data.agent.name || cfg.title;
        cfg.subtitle = t.subtitle || cfg.subtitle;
        cfg.welcome = data.agent.welcome_message || cfg.welcome;
      }
    })
    .catch(function () {})
    .finally(render);

  function render() {
    var host = document.createElement('div');
    host.setAttribute('id', 'support-ai-widget');
    document.body.appendChild(host);
    var root = host.attachShadow({ mode: 'open' });
    root.innerHTML = template();

    var launcher = root.getElementById('sa-launcher');
    var panel = root.getElementById('sa-panel');
    var closeBtn = root.getElementById('sa-close');
    var form = root.getElementById('sa-form');
    var input = root.getElementById('sa-input');
    var log = root.getElementById('sa-log');
    var open = false;

    function toggle(show) {
      open = show;
      panel.classList.toggle('open', open);
      launcher.classList.toggle('active', open);
      if (open) { setTimeout(function () { input.focus(); }, 120); }
    }
    launcher.addEventListener('click', function () { toggle(!open); });
    closeBtn.addEventListener('click', function () { toggle(false); });

    // Greeting.
    addMessage('bot', cfg.welcome);

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var text = input.value.trim();
      if (!text || form.dataset.busy) { return; }
      input.value = '';
      addMessage('user', text);
      send(text);
    });

    // ── Send + stream the reply over SSE (fetch + ReadableStream) ─────────
    function send(text) {
      form.dataset.busy = '1';
      var typing = addTyping();
      var bubble = null;

      fetch(API_BASE + '/api/chat/message', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          message: text,
          visitor_id: visitorId,
          conversation_id: conversationId || '',
          page_url: location.href
        })
      }).then(function (res) {
        if (!res.ok || !res.body) { throw new Error('bad response'); }
        var reader = res.body.getReader();
        var decoder = new TextDecoder();
        var buffer = '';

        function pump() {
          return reader.read().then(function (r) {
            if (r.done) { finish(); return; }
            buffer += decoder.decode(r.value, { stream: true });
            var events = buffer.split('\n\n');
            buffer = events.pop();
            events.forEach(function (raw) { handleEvent(raw); });
            return pump();
          });
        }
        function handleEvent(raw) {
          var event = 'message', data = '';
          raw.split('\n').forEach(function (line) {
            if (line.indexOf('event:') === 0) { event = line.slice(6).trim(); }
            else if (line.indexOf('data:') === 0) { data += line.slice(5).trim(); }
          });
          if (!data) { return; }
          var payload; try { payload = JSON.parse(data); } catch (e) { return; }

          if (event === 'meta' && payload.conversation_id) {
            conversationId = payload.conversation_id;
            LS.setItem('sa_conversation_' + AGENT, conversationId);
          } else if (event === 'token') {
            if (!bubble) { typing.remove(); bubble = addMessage('bot', ''); }
            bubble.textContent += payload.text;
            scroll();
          } else if (event === 'error') {
            if (typing.parentNode) { typing.remove(); }
            addMessage('bot', payload.message || 'Something went wrong.');
          }
        }
        function finish() { cleanup(); }
        return pump();
      }).catch(function () {
        if (typing.parentNode) { typing.remove(); }
        addMessage('bot', 'I could not reach the server. Please try again.');
        cleanup();
      });

      function cleanup() { delete form.dataset.busy; scroll(); }
    }

    function addMessage(who, text) {
      var el = document.createElement('div');
      el.className = 'sa-msg sa-' + who;
      el.textContent = text;
      log.appendChild(el);
      scroll();
      return el;
    }
    function addTyping() {
      var el = document.createElement('div');
      el.className = 'sa-msg sa-bot sa-typing';
      el.innerHTML = '<span></span><span></span><span></span>';
      log.appendChild(el); scroll();
      return el;
    }
    function scroll() { log.scrollTop = log.scrollHeight; }
  }

  // ── Markup + fully-scoped styles ───────────────────────────────────────
  function template() {
    var side = cfg.position === 'left' ? 'left:24px' : 'right:24px';
    return '' +
    '<style>' +
    ':host{all:initial}' +
    '*{box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}' +
    '#sa-launcher{position:fixed;bottom:24px;' + side + ';width:60px;height:60px;border:0;border-radius:50%;' +
      'background:linear-gradient(135deg,' + cfg.primary + ',' + cfg.accent + ');color:#fff;font-size:26px;cursor:pointer;' +
      'box-shadow:0 10px 30px rgba(79,70,229,.4);transition:transform .2s,box-shadow .2s;z-index:2147483000}' +
    '#sa-launcher:hover{transform:translateY(-3px) scale(1.05)}' +
    '#sa-launcher.active{transform:rotate(90deg)}' +
    '#sa-panel{position:fixed;bottom:100px;' + side + ';width:380px;max-width:calc(100vw - 32px);height:600px;max-height:calc(100vh - 130px);' +
      'background:#fff;border-radius:20px;box-shadow:0 24px 60px rgba(15,23,42,.28);display:flex;flex-direction:column;overflow:hidden;' +
      'opacity:0;transform:translateY(16px) scale(.98);pointer-events:none;transition:opacity .22s,transform .22s;z-index:2147483000}' +
    '#sa-panel.open{opacity:1;transform:none;pointer-events:auto}' +
    '.sa-head{background:linear-gradient(135deg,' + cfg.primary + ',' + cfg.accent + ');color:#fff;padding:18px 20px;display:flex;align-items:center;gap:12px}' +
    '.sa-ava{width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:20px}' +
    '.sa-head h3{margin:0;font-size:16px;font-weight:600}.sa-head p{margin:2px 0 0;font-size:12px;opacity:.85}' +
    '#sa-close{margin-left:auto;background:transparent;border:0;color:#fff;font-size:22px;cursor:pointer;opacity:.8}' +
    '#sa-close:hover{opacity:1}' +
    '#sa-log{flex:1;overflow-y:auto;padding:20px;background:#f8fafc;display:flex;flex-direction:column;gap:10px}' +
    '.sa-msg{max-width:80%;padding:11px 15px;border-radius:16px;font-size:14px;line-height:1.5;white-space:pre-wrap;word-wrap:break-word;animation:sa-in .2s ease}' +
    '.sa-bot{align-self:flex-start;background:#fff;color:#0f172a;border:1px solid #e5e7eb;border-bottom-left-radius:4px}' +
    '.sa-user{align-self:flex-end;background:linear-gradient(135deg,' + cfg.primary + ',' + cfg.accent + ');color:#fff;border-bottom-right-radius:4px}' +
    '.sa-typing{display:flex;gap:4px;align-items:center}' +
    '.sa-typing span{width:7px;height:7px;border-radius:50%;background:#cbd5e1;animation:sa-bounce 1.2s infinite}' +
    '.sa-typing span:nth-child(2){animation-delay:.2s}.sa-typing span:nth-child(3){animation-delay:.4s}' +
    '#sa-form{display:flex;gap:8px;padding:14px;border-top:1px solid #eef2f7;background:#fff}' +
    '#sa-input{flex:1;border:1px solid #e2e8f0;border-radius:12px;padding:11px 14px;font-size:14px;outline:none;transition:border .15s}' +
    '#sa-input:focus{border-color:' + cfg.primary + '}' +
    '#sa-form button{border:0;border-radius:12px;padding:0 16px;background:linear-gradient(135deg,' + cfg.primary + ',' + cfg.accent + ');color:#fff;font-size:16px;cursor:pointer}' +
    '.sa-foot{text-align:center;font-size:11px;color:#94a3b8;padding:0 0 10px;background:#fff}' +
    '@keyframes sa-in{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}' +
    '@keyframes sa-bounce{0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-5px)}}' +
    '@media(max-width:480px){#sa-panel{bottom:88px;height:calc(100vh - 110px)}}' +
    '</style>' +
    '<button id="sa-launcher" aria-label="Open chat">' + cfg.launcher + '</button>' +
    '<div id="sa-panel" role="dialog" aria-label="Support chat">' +
      '<div class="sa-head"><div class="sa-ava">' + cfg.launcher + '</div>' +
        '<div><h3>' + esc(cfg.title) + '</h3><p>' + esc(cfg.subtitle) + '</p></div>' +
        '<button id="sa-close" aria-label="Close">×</button></div>' +
      '<div id="sa-log"></div>' +
      '<form id="sa-form"><input id="sa-input" autocomplete="off" placeholder="Type your message…" />' +
        '<button type="submit" aria-label="Send">➤</button></form>' +
      '<div class="sa-foot">Powered by support-ai</div>' +
    '</div>';
  }

  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
  }); }
})();
