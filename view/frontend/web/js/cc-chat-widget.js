/**
 * Chat – Web Chat Widget
 *
 * Framework-agnostic, dependency-free implementation that works identically on
 * BOTH the Luma and the Hyvä frontends.
 *
 *  - No RequireJS: the script is loaded with a plain <script defer> tag and
 *    self-initializes on DOMContentLoaded. (Hyvä ships neither RequireJS nor an
 *    AMD loader, so the previous define()/x-magento-init approach never ran and
 *    the button stayed invisible.)
 *  - No jQuery: all DOM access uses the native API.
 *  - FPC-safe login check: the customer login state is read from the shared
 *    `mage-cache-storage` localStorage key, which both Luma and Hyvä populate
 *    from the Magento customer-data sections API. No PHP session is touched, so
 *    the rendered markup stays Full-Page-Cache friendly.
 *  - The form key is read from Magento's own form_key cookie (always
 *    session-correct, FPC-safe).
 */
(function () {
    'use strict';

    var SECTION_STORAGE_KEY = 'mage-cache-storage';

    function init() {
        var root = document.getElementById('cc-chat-root');
        if (!root || root.dataset.ccInit === '1') {
            return;
        }
        root.dataset.ccInit = '1';

        var cfg = {};
        try {
            cfg = JSON.parse(root.getAttribute('data-config') || '{}');
        } catch (e) {
            cfg = {};
        }

        // Per-store session keys prevent cross-store session leakage when both stores
        // share the same origin (same-domain multi-store) and the same browser tab.
        var SESSION_KEY = 'cc_chat_session_' + (cfg.storeId || 0);
        var HISTORY_KEY = 'cc_chat_history_' + (cfg.storeId || 0);

        var fab        = root.querySelector('#cc-chat-fab');
        var backdrop   = root.querySelector('#cc-chat-backdrop');
        var modal      = root.querySelector('#cc-chat-modal');
        var messages   = root.querySelector('#cc-chat-messages');
        var typing     = root.querySelector('#cc-chat-typing');
        var form       = root.querySelector('#cc-chat-form');
        var input      = root.querySelector('#cc-message-input');
        var fileInput  = root.querySelector('#cc-file-input');
        var preview    = root.querySelector('#cc-attachments-preview');
        var sessionIdEl = root.querySelector('#cc-session-id');
        var formKeyEl  = root.querySelector('#cc-form-key');
        var sendBtn    = root.querySelector('#cc-send-btn');
        var closeBtn   = root.querySelector('#cc-chat-close');
        var statusText = root.querySelector('#cc-status-text');
        var fabIconChat  = fab ? fab.querySelector('.cc-fab-icon-chat') : null;
        var fabIconClose = fab ? fab.querySelector('.cc-fab-icon-close') : null;

        if (!fab || !modal || !messages || !form || !input) {
            return;
        }

        var isOpen       = false;
        var isLoading    = false;
        var isLoggedIn   = false;
        var sessionId    = '';
        var pendingFiles = [];

        // ── Apply primary color ─────────────────────────────────────────────
        if (cfg.primaryColor) {
            root.style.setProperty('--cc-primary', cfg.primaryColor);
            root.style.setProperty('--cc-primary-dark', darkenHex(cfg.primaryColor, 20));
        }

        // ── Form key: read from Magento's form_key cookie (FPC-safe) ─────────
        function refreshFormKey() {
            var cookieMatch = document.cookie.match(/(?:^|;\s*)form_key=([^;]+)/);
            if (cookieMatch) {
                var key = decodeURIComponent(cookieMatch[1]);
                if (formKeyEl) { formKeyEl.value = key; }
                return key;
            }
            // Fallback: read from Magento's standard DOM form_key input (non-FPC setups)
            var inputs = document.querySelectorAll('input[name="form_key"]');
            for (var i = 0; i < inputs.length; i++) {
                if (inputs[i] !== formKeyEl && inputs[i].value) {
                    if (formKeyEl) { formKeyEl.value = inputs[i].value; }
                    return inputs[i].value;
                }
            }
            return '';
        }

        // ── Customer-data: FPC-safe, theme-agnostic login check ─────────────
        // Both Luma and Hyvä persist the Magento customer-data sections into the
        // `mage-cache-storage` localStorage object, so we can detect the login
        // state without RequireJS or the Magento_Customer AMD module.
        function readLoginState() {
            try {
                var raw = window.localStorage.getItem(SECTION_STORAGE_KEY);
                if (!raw) { return false; }
                var data = JSON.parse(raw);
                var customer = data && data.customer;
                return !!(customer && (customer.fullname || customer.firstname));
            } catch (e) {
                return false;
            }
        }

        function applyLoginState(loggedIn) {
            if (loggedIn === isLoggedIn) { return; }
            isLoggedIn = loggedIn;

            if (loggedIn) {
                fab.classList.remove('cc-chat-fab--hidden');
                if (window.location.hash === '#cc-chat-open' && !isOpen) {
                    openChat();
                }
            } else {
                fab.classList.add('cc-chat-fab--hidden');
                if (isOpen) { closeChat(); }
            }
        }

        function refreshLoginState() {
            applyLoginState(readLoginState());
        }

        // Evaluate immediately, then keep in sync. The sections AJAX that fills
        // localStorage on a first/cold visit resolves asynchronously and does not
        // emit a `storage` event in the same tab, so we re-check on a light timer
        // in addition to cross-tab `storage` events and tab focus changes.
        refreshLoginState();
        window.addEventListener('storage', function (e) {
            if (!e || e.key === null || e.key === SECTION_STORAGE_KEY) {
                refreshLoginState();
            }
        });
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) { refreshLoginState(); }
        });
        setInterval(refreshLoginState, 2000);

        // ── Session handling ────────────────────────────────────────────────
        function loadSession() {
            try {
                sessionId = sessionStorage.getItem(SESSION_KEY) || '';
            } catch (e) { sessionId = ''; }
            if (sessionIdEl) { sessionIdEl.value = sessionId; }
        }

        function saveSession(id) {
            sessionId = id;
            if (sessionIdEl) { sessionIdEl.value = id; }
            try { sessionStorage.setItem(SESSION_KEY, id); } catch (e) {}
        }

        function loadHistory() {
            try { return JSON.parse(sessionStorage.getItem(HISTORY_KEY) || '[]'); }
            catch (e) { return []; }
        }

        function saveHistory(history) {
            try {
                sessionStorage.setItem(HISTORY_KEY, JSON.stringify(history.slice(-40)));
            } catch (e) {}
        }

        // ── Open / close ────────────────────────────────────────────────────
        function openChat() {
            if (!isLoggedIn) { return; }
            isOpen = true;
            refreshFormKey();
            backdrop.classList.add('cc-open');
            modal.classList.add('cc-open');
            modal.setAttribute('aria-hidden', 'false');
            if (fabIconChat)  { fabIconChat.style.display = 'none'; }
            if (fabIconClose) { fabIconClose.style.display = ''; }
            input.focus();
        }

        function closeChat() {
            isOpen = false;
            backdrop.classList.remove('cc-open');
            modal.classList.remove('cc-open');
            modal.setAttribute('aria-hidden', 'true');
            if (fabIconChat)  { fabIconChat.style.display = ''; }
            if (fabIconClose) { fabIconClose.style.display = 'none'; }
        }

        fab.addEventListener('click', function () { isOpen ? closeChat() : openChat(); });
        if (closeBtn) { closeBtn.addEventListener('click', closeChat); }
        backdrop.addEventListener('click', closeChat);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen) { closeChat(); }
        });

        // ── Render messages ─────────────────────────────────────────────────
        function renderMessage(role, text, fileNames, timestamp) {
            var isUser   = role === 'user';
            var timeStr  = timestamp || currentTimeStr();
            var safeText = isUser
                ? escapeHtml(text).replace(/\n/g, '<br>')
                : sanitizeAiHtml(text);

            var fileTags = '';
            if (fileNames && fileNames.length) {
                fileTags = '<div class="cc-file-tags">'
                    + fileNames.map(function (n) {
                        return '<span class="cc-file-tag">📎 ' + escapeHtml(n) + '</span>';
                    }).join('') + '</div>';
            }

            var html = '<div class="cc-msg cc-msg-' + role + '">'
                + '<div class="cc-bubble">' + safeText + fileTags + '</div>'
                + '<span class="cc-msg-time">' + escapeHtml(timeStr) + '</span>'
                + '</div>';

            messages.insertAdjacentHTML('beforeend', html);
            scrollToBottom();
        }

        function scrollToBottom() {
            messages.scrollTop = messages.scrollHeight;
        }

        // ── File attachment handling ─────────────────────────────────────────
        fileInput.addEventListener('change', function () {
            Array.prototype.slice.call(this.files || []).forEach(function (f) {
                pendingFiles.push({ file: f, name: f.name });
            });
            this.value = '';
            renderPreviews();
        });

        function renderPreviews() {
            preview.innerHTML = '';
            if (!pendingFiles.length) { preview.style.display = 'none'; return; }
            preview.style.display = '';
            pendingFiles.forEach(function (item, idx) {
                var badge = document.createElement('span');
                badge.className = 'cc-preview-badge';
                badge.innerHTML =
                    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
                    + '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>'
                    + '<span class="cc-preview-name"></span>';
                badge.querySelector('.cc-preview-name').textContent = truncate(item.name, 22);

                var removeBtn = document.createElement('button');
                removeBtn.className = 'cc-preview-remove';
                removeBtn.type = 'button';
                removeBtn.setAttribute('aria-label', 'Remove ' + item.name);
                removeBtn.innerHTML = '✕';
                removeBtn.addEventListener('click', function () {
                    pendingFiles.splice(idx, 1);
                    renderPreviews();
                });
                badge.appendChild(removeBtn);
                preview.appendChild(badge);
            });
        }

        // ── Send message ────────────────────────────────────────────────────
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (isLoading || !isLoggedIn) { return; }

            var text = input.value.trim();
            if (!text && !pendingFiles.length) { return; }

            var fileNames = pendingFiles.map(function (i) { return i.name; });
            var history   = loadHistory();

            renderMessage('user', text, fileNames);
            history.push({ role: 'user', text: text, files: fileNames, time: currentTimeStr() });

            input.value = '';
            autoResize();

            var fd = new FormData();
            fd.append('form_key', refreshFormKey());
            fd.append('session_id', sessionId);
            fd.append('message', text);
            pendingFiles.forEach(function (item) {
                fd.append('attachments[]', item.file, item.name);
            });
            pendingFiles = [];
            renderPreviews();

            setLoading(true);

            fetch(cfg.sendUrl, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                return response.text().then(function (raw) {
                    var body = null;
                    try { body = raw ? JSON.parse(raw) : null; } catch (ex) { body = null; }
                    return { ok: response.ok, status: response.status, body: body };
                });
            }).then(function (res) {
                setLoading(false);
                if (!res.ok) {
                    throw res;
                }
                var resp = res.body || {};
                if (resp.response_html || resp.response) {
                    var content = resp.response_html || resp.response;
                    renderMessage('ai', content);
                    history.push({ role: 'ai', text: content, time: currentTimeStr() });
                    saveHistory(history);
                    if (resp.session_id) { saveSession(resp.session_id); }
                } else {
                    renderMessage('ai', 'Sorry, an unexpected error occurred.');
                }
                if (resp.debug_blocks && resp.debug_blocks.length) {
                    resp.debug_blocks.forEach(renderDebugBlock);
                    scrollToBottom();
                }
            }).catch(function (res) {
                setLoading(false);
                var msg = 'Sorry, something went wrong. Please try again.';
                var status = res && res.status;
                var body   = res && res.body;
                if ((body && body.error === 'unauthorized') || status === 401) {
                    msg = 'Your account is not authorized for this service.';
                } else if (body && body.error) {
                    msg = String(body.error);
                }
                renderMessage('ai', msg);
            });
        });

        // ── Auto-resize textarea ─────────────────────────────────────────────
        function autoResize() {
            input.style.height = 'auto';
            input.style.height = Math.min(Math.max(input.scrollHeight, 40), 120) + 'px';
        }
        input.addEventListener('input', autoResize);

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.dispatchEvent(new Event('submit', { cancelable: true }));
                }
            }
        });

        // ── Loading state ────────────────────────────────────────────────────
        var STATUS_MESSAGES = [
            'Anfrage wird analysiert…',
            'Produktkatalog wird durchsucht…',
            'KI verarbeitet Ihre Anfrage…',
            'Bestellhistorie wird geprüft…',
            'Antwort wird aufbereitet…'
        ];
        var statusTimer = null;
        var statusIndex = 0;

        function startStatusCycle() {
            statusIndex = 0;
            if (statusText) { statusText.textContent = STATUS_MESSAGES[0]; }
            statusTimer = setInterval(function () {
                statusIndex = Math.min(statusIndex + 1, STATUS_MESSAGES.length - 1);
                if (statusText) { statusText.textContent = STATUS_MESSAGES[statusIndex]; }
            }, 5000);
        }

        function stopStatusCycle() {
            clearInterval(statusTimer);
            statusTimer = null;
            if (statusText) { statusText.textContent = ''; }
        }

        function setLoading(state) {
            isLoading = state;
            sendBtn.disabled = state;
            input.disabled = state;
            if (state) {
                typing.style.display = '';
                startStatusCycle();
                scrollToBottom();
            } else {
                stopStatusCycle();
                typing.style.display = 'none';
            }
        }

        // ── LLM Debug Block renderer ─────────────────────────────────────────
        function renderDebugBlock(block) {
            var requestJson = JSON.stringify(block.request, null, 2);
            var responseStr = block.response;
            try {
                responseStr = JSON.stringify(JSON.parse(responseStr), null, 2);
            } catch (e) {}

            var html = '<div class="cc-debug-block">'
                + '<div class="cc-debug-title">&#x1F50D; LLM DEBUG &#x2014; ' + escapeHtml(block.title) + '</div>'
                + '<div class="cc-debug-toggle cc-debug-section"><span class="cc-debug-chevron">&#x25B6;</span> Request (Payload)</div>'
                + '<pre class="cc-debug-pre" style="display:none">' + escapeHtml(requestJson) + '</pre>'
                + '<div class="cc-debug-toggle cc-debug-section"><span class="cc-debug-chevron">&#x25B6;</span> Response (HTTP Body)</div>'
                + '<pre class="cc-debug-pre" style="display:none">' + escapeHtml(responseStr) + '</pre>'
                + '</div>';

            messages.insertAdjacentHTML('beforeend', html);
        }

        messages.addEventListener('click', function (e) {
            var toggle = e.target.closest ? e.target.closest('.cc-debug-toggle') : null;
            if (!toggle || !messages.contains(toggle)) { return; }
            var pre = toggle.nextElementSibling;
            var chevron = toggle.querySelector('.cc-debug-chevron');
            if (!pre) { return; }
            var hidden = pre.style.display === 'none' || pre.style.display === '';
            pre.style.display = hidden ? 'block' : 'none';
            if (chevron) { chevron.innerHTML = hidden ? '&#x25BC;' : '&#x25B6;'; }
        });

        // ── Restore session / history on load ────────────────────────────────
        function bootstrap() {
            loadSession();

            var history = loadHistory();
            if (history.length) {
                history.forEach(function (entry) {
                    renderMessage(entry.role, entry.text, entry.files || [], entry.time);
                });
            } else {
                renderMessage('ai', cfg.welcomeMessage || 'Hello! How can I help you today?');
            }
        }

        // ── Utilities ────────────────────────────────────────────────────────
        function escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        var ALLOWED_TAGS = /^(p|br|strong|b|em|i|ul|ol|li|span|a|h[1-6]|blockquote|pre|code|hr|img|table|thead|tbody|tr|td|th|div)$/i;

        function sanitizeAiHtml(html) {
            if (!html) { return ''; }
            var div = document.createElement('div');
            div.innerHTML = html;
            sanitizeNode(div);
            return div.innerHTML;
        }

        function sanitizeNode(node) {
            Array.prototype.slice.call(node.childNodes).forEach(function (child) {
                if (child.nodeType === 3) { return; }
                if (child.nodeType === 1) {
                    if (!ALLOWED_TAGS.test(child.tagName)) {
                        while (child.firstChild) { node.insertBefore(child.firstChild, child); }
                        node.removeChild(child);
                    } else {
                        var tag = child.tagName.toLowerCase();
                        Array.prototype.slice.call(child.attributes || []).forEach(function (attr) {
                            if (tag === 'a' && attr.name === 'href') { return; }
                            if (tag === 'img' && attr.name === 'src') { return; }
                            if (tag === 'img' && attr.name === 'alt') { return; }
                            if (tag === 'img' && attr.name === 'width') { return; }
                            child.removeAttribute(attr.name);
                        });
                        if (tag === 'a') {
                            child.setAttribute('target', '_blank');
                            child.setAttribute('rel', 'noopener noreferrer');
                            var href = child.getAttribute('href') || '';
                            if (/^javascript:/i.test(href.trim())) { child.removeAttribute('href'); }
                        }
                        if (tag === 'img') {
                            var src = child.getAttribute('src') || '';
                            if (!/^(data:image\/|https:\/\/)/i.test(src.trim())) {
                                child.removeAttribute('src');
                            }
                            child.setAttribute('style', 'max-width:160px;border-radius:6px;margin:4px 0;display:block;');
                        }
                        sanitizeNode(child);
                    }
                } else {
                    node.removeChild(child);
                }
            });
        }

        function currentTimeStr() {
            var d = new Date();
            return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
        }

        function truncate(str, max) {
            return str.length > max ? str.slice(0, max - 1) + '…' : str;
        }

        function darkenHex(hex, amount) {
            try {
                hex = hex.replace('#', '');
                if (hex.length === 3) {
                    hex = hex.split('').map(function (c) { return c + c; }).join('');
                }
                var r = Math.max(0, parseInt(hex.slice(0, 2), 16) - amount);
                var g = Math.max(0, parseInt(hex.slice(2, 4), 16) - amount);
                var b = Math.max(0, parseInt(hex.slice(4, 6), 16) - amount);
                return '#' + [r, g, b].map(function (n) { return ('0' + n.toString(16)).slice(-2); }).join('');
            } catch (e) { return '#' + hex; }
        }

        bootstrap();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
