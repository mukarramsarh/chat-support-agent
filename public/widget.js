/*!
 * support-ai · embeddable chat widget
 * One shortcode, zero dependencies, fully isolated in a Shadow DOM.
 *
 *   <script src="https://your-host/widget.js" data-agent="AGENT_PUBLIC_ID" defer></script>
 */
(function () {
  'use strict';

  var self = document.currentScript;
  if (!self) {
    var scripts = document.getElementsByTagName('script');
    for (var i = scripts.length - 1; i >= 0; i--) {
      if (/widget\.js(\?|$)/.test(scripts[i].src)) { self = scripts[i]; break; }
    }
  }
  var API_BASE = new URL(self.src).origin + new URL(self.src).pathname.replace(/\/widget\.js.*$/, '');
  var AGENT = self.getAttribute('data-agent') || '';
  // data-launcher="off" hides the floating bubble so you can open chat from your
  // own link/button instead (see the data-support-ai-open trigger below).
  var HIDE_LAUNCHER = (self.getAttribute('data-launcher') || '').toLowerCase() === 'off';

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
    welcome: 'Hi! How can I help you today?', form: null, rtl: false
  };

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
        cfg.form = data.startup_form || null;
        cfg.rtl = !!data.rtl;
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
    var gate = root.getElementById('sa-gate');
    var open = false, greeted = false;

    if (cfg.rtl) { panel.setAttribute('dir', 'rtl'); }

    function toggle(show) {
      open = show;
      panel.classList.toggle('open', open);
      launcher.classList.toggle('active', open);
      if (open) { start(); }
    }
    launcher.addEventListener('click', function () { toggle(!open); });
    closeBtn.addEventListener('click', function () { toggle(false); });

    if (HIDE_LAUNCHER) { launcher.style.display = 'none'; }

    // Public API — call from your own link/button: window.supportAI.open()
    window.supportAI = {
      open: function () { toggle(true); },
      close: function () { toggle(false); },
      toggle: function () { toggle(!open); }
    };
    // Any element with data-support-ai-open becomes a trigger (delegated).
    document.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target.closest('[data-support-ai-open]') : null;
      if (t) { e.preventDefault(); toggle(true); }
    });

    // Decide whether to show the pre-chat form or go straight to chat.
    function needGate() {
      return cfg.form && cfg.form.fields && cfg.form.fields.length &&
             !LS.getItem('sa_lead_' + AGENT);
    }
    function start() {
      if (needGate()) { showGate(); }
      else { showChat(); }
    }
    function showChat() {
      gate.style.display = 'none';
      form.style.display = '';
      log.style.display = '';
      if (!greeted) { greeted = true; addMessage('bot', cfg.welcome); }
      setTimeout(function () { input.focus(); }, 120);
    }

    // ── Pre-chat lead form ────────────────────────────────────────────────
    function showGate() {
      log.style.display = 'none';
      form.style.display = 'none';
      var f = cfg.form;
      var html = '<div class="sa-gate-title">' + esc(f.title || '') + '</div>' +
                 (f.subtitle ? '<div class="sa-gate-sub">' + esc(f.subtitle) + '</div>' : '');
      f.fields.forEach(function (fld) {
        var type = fld.key === 'email' ? 'email' : (fld.key === 'phone' ? 'tel' : 'text');
        html += '<input class="sa-gate-in" data-key="' + esc(fld.key) + '" type="' + type + '" placeholder="' +
                esc(fld.label) + (fld.required ? ' *' : '') + '" ' + (fld.required ? 'required' : '') + '>';
      });
      if (f.consent_required) {
        html += '<label class="sa-gate-consent"><input type="checkbox" class="sa-gate-agree"> <span>' +
                esc(f.consent_text || 'I agree to the processing of my data.') +
                (f.privacy_url ? ' <a href="' + esc(f.privacy_url) + '" target="_blank">Privacy</a>' : '') + '</span></label>';
      }
      html += '<div class="sa-gate-err" style="display:none"></div>' +
              '<button class="sa-gate-btn" type="button">Start chat</button>';
      gate.innerHTML = html;
      gate.style.display = '';

      var err = gate.querySelector('.sa-gate-err');
      gate.querySelector('.sa-gate-btn').addEventListener('click', function () {
        var payload = { visitor_id: visitorId, conversation_id: conversationId || '', page_url: location.href, consent: false };
        var ok = true;
        gate.querySelectorAll('.sa-gate-in').forEach(function (el) {
          var v = el.value.trim();
          if (el.hasAttribute('required') && !v) { ok = false; el.classList.add('bad'); }
          else { el.classList.remove('bad'); }
          payload[el.getAttribute('data-key')] = v;
        });
        var agree = gate.querySelector('.sa-gate-agree');
        if (agree) { payload.consent = agree.checked; }
        if (!ok) { return fail('Please fill the required fields.'); }
        if (f.consent_required && !payload.consent) { return fail('Please accept to continue.'); }

        fetch(API_BASE + '/api/chat/lead', {
          method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
        }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
          .then(function (res) {
            if (!res.ok) { return fail(res.d && res.d.error ? res.d.error : 'Please check your details.'); }
            if (res.d.conversation_id) { conversationId = res.d.conversation_id; LS.setItem('sa_conversation_' + AGENT, conversationId); }
            LS.setItem('sa_lead_' + AGENT, '1');
            showChat();
          }).catch(function () { fail('Could not submit. Please try again.'); });
      });
      function fail(m) { err.textContent = m; err.style.display = ''; }
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var text = input.value.trim();
      if (!text || form.dataset.busy) { return; }
      input.value = '';
      addMessage('user', text);
      send(text);
    });

    function send(text) {
      form.dataset.busy = '1';
      var typing = addTyping();
      var bubble = null;
      fetch(API_BASE + '/api/chat/message', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message: text, visitor_id: visitorId, conversation_id: conversationId || '', page_url: location.href })
      }).then(function (res) {
        if (!res.ok || !res.body) { throw new Error('bad response'); }
        var reader = res.body.getReader(), decoder = new TextDecoder(), buffer = '';
        function pump() {
          return reader.read().then(function (r) {
            if (r.done) { cleanup(); return; }
            buffer += decoder.decode(r.value, { stream: true });
            var events = buffer.split('\n\n'); buffer = events.pop();
            events.forEach(handleEvent);
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
            conversationId = payload.conversation_id; LS.setItem('sa_conversation_' + AGENT, conversationId);
          } else if (event === 'token') {
            if (!bubble) { typing.remove(); bubble = addMessage('bot', ''); }
            bubble.textContent += payload.text; scroll();
          } else if (event === 'error') {
            if (typing.parentNode) { typing.remove(); }
            addMessage('bot', payload.message || 'Something went wrong.');
          }
        }
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
      el.className = 'sa-msg sa-' + who; el.textContent = text;
      log.appendChild(el); scroll(); return el;
    }
    function addTyping() {
      var el = document.createElement('div');
      el.className = 'sa-msg sa-bot sa-typing';
      el.innerHTML = '<span></span><span></span><span></span>';
      log.appendChild(el); scroll(); return el;
    }
    function scroll() { log.scrollTop = log.scrollHeight; }
  }

  function template() {
    var side = cfg.position === 'left' ? 'left:24px' : 'right:24px';
    return '' +
    '<style>' +
    ':host{all:initial}' +
    '*{box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}' +
    '#sa-launcher{position:fixed;bottom:24px;' + side + ';width:60px;height:60px;border:0;border-radius:50%;' +
      'background:linear-gradient(135deg,' + cfg.primary + ',' + cfg.accent + ');color:#fff;font-size:26px;cursor:pointer;' +
      'box-shadow:0 10px 30px rgba(79,70,229,.4);transition:transform .2s;z-index:2147483000}' +
    '#sa-launcher:hover{transform:translateY(-3px) scale(1.05)}#sa-launcher.active{transform:rotate(90deg)}' +
    '#sa-panel{position:fixed;bottom:100px;' + side + ';width:380px;max-width:calc(100vw - 32px);height:600px;max-height:calc(100vh - 130px);' +
      'background:#fff;border-radius:20px;box-shadow:0 24px 60px rgba(15,23,42,.28);display:flex;flex-direction:column;overflow:hidden;' +
      'opacity:0;transform:translateY(16px) scale(.98);pointer-events:none;transition:opacity .22s,transform .22s;z-index:2147483000}' +
    '#sa-panel.open{opacity:1;transform:none;pointer-events:auto}' +
    '.sa-head{background:linear-gradient(135deg,' + cfg.primary + ',' + cfg.accent + ');color:#fff;padding:18px 20px;display:flex;align-items:center;gap:12px}' +
    '.sa-ava{width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:20px}' +
    '.sa-head h3{margin:0;font-size:16px;font-weight:600}.sa-head p{margin:2px 0 0;font-size:12px;opacity:.85}' +
    '#sa-close{margin-inline-start:auto;background:transparent;border:0;color:#fff;font-size:22px;cursor:pointer;opacity:.8}' +
    '#sa-log{flex:1;overflow-y:auto;padding:20px;background:#f8fafc;display:flex;flex-direction:column;gap:10px}' +
    '.sa-msg{max-width:80%;padding:11px 15px;border-radius:16px;font-size:14px;line-height:1.5;white-space:pre-wrap;word-wrap:break-word;animation:sa-in .2s ease}' +
    '.sa-bot{align-self:flex-start;background:#fff;color:#0f172a;border:1px solid #e5e7eb;border-bottom-left-radius:4px}' +
    '.sa-user{align-self:flex-end;background:linear-gradient(135deg,' + cfg.primary + ',' + cfg.accent + ');color:#fff;border-bottom-right-radius:4px}' +
    '.sa-typing{display:flex;gap:4px;align-items:center}' +
    '.sa-typing span{width:7px;height:7px;border-radius:50%;background:#cbd5e1;animation:sa-bounce 1.2s infinite}' +
    '.sa-typing span:nth-child(2){animation-delay:.2s}.sa-typing span:nth-child(3){animation-delay:.4s}' +
    '#sa-gate{flex:1;overflow-y:auto;padding:22px;display:none;flex-direction:column;gap:10px;background:#fff}' +
    '.sa-gate-title{font-size:17px;font-weight:700;color:#0f172a}.sa-gate-sub{font-size:13px;color:#64748b;margin-bottom:6px}' +
    '.sa-gate-in{border:1px solid #e2e8f0;border-radius:10px;padding:11px 13px;font-size:14px;outline:none}' +
    '.sa-gate-in:focus{border-color:' + cfg.primary + '}.sa-gate-in.bad{border-color:#ef4444}' +
    '.sa-gate-consent{display:flex;gap:8px;font-size:12px;color:#475569;align-items:flex-start;line-height:1.4}' +
    '.sa-gate-consent a{color:' + cfg.primary + '}' +
    '.sa-gate-err{color:#b91c1c;font-size:13px}' +
    '.sa-gate-btn{margin-top:6px;border:0;border-radius:12px;padding:12px;font-size:15px;font-weight:600;cursor:pointer;' +
      'background:linear-gradient(135deg,' + cfg.primary + ',' + cfg.accent + ');color:#fff}' +
    '#sa-form{display:flex;gap:8px;padding:14px;border-top:1px solid #eef2f7;background:#fff}' +
    '#sa-input{flex:1;border:1px solid #e2e8f0;border-radius:12px;padding:11px 14px;font-size:14px;outline:none}' +
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
      '<div id="sa-gate"></div>' +
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
