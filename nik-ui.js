/**
 * nik-ui.js — NiK (نیک) assistant chat widget.
 * Loaded on BOTH index.php (CPM) and tasks.php. Self-contained.
 * Speech: browser speechSynthesis reads the last answer (fa/en).
 */
(function () {
    'use strict';

    const $ = (id) => document.getElementById(id);
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));

    function csrfToken() {
        const meta = document.querySelector('meta[name="nawara-csrf-token"]');
        return meta ? meta.content : '';
    }

    async function api(url, opts = {}) {
        const headers = Object.assign({}, opts.headers || {});
        if (opts.json !== undefined) {
            headers['Content-Type'] = 'application/json';
            headers['X-CSRF-Token'] = csrfToken();
            opts.body = JSON.stringify(opts.json);
            delete opts.json;
        }
        const res = await fetch(url, Object.assign({ credentials: 'same-origin' }, opts, { headers }));
        let data = null;
        try { data = await res.json(); } catch (e) { data = { success: false, error: 'Bad response' }; }
        if (!res.ok && (!data || data.success === false)) {
            throw new Error((data && data.error) || ('HTTP ' + res.status));
        }
        return data;
    }

    const SUGGESTIONS = [
        'پیشرفت پروژه‌ها چطور است؟',
        'کدام پروژه عقب است؟',
        'کارهای من چیست؟',
        'گزارش مالی بده',
        'به خاطر بسپار: جلسه ساعت ۹ صبح',
        'چه کسانی بیشترین امتیاز را دارند؟',
        'امروز چی تاریخ است؟',
    ];

    let lastAnswer = '';
    let historyLoaded = false;

    function addBubble(role, text) {
        const box = $('nikMessages');
        if (!box) return;
        const b = document.createElement('div');
        b.className = 'nik-msg nik-' + (role === 'user' ? 'user' : 'bot');
        b.textContent = text;
        box.appendChild(b);
        box.scrollTop = box.scrollHeight;
        if (role !== 'user') lastAnswer = text;
    }

    function renderSuggestions(items) {
        const box = $('nikSuggest');
        if (!box) return;
        box.innerHTML = '';
        (items || []).slice(0, 4).forEach((s) => {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'nik-chip';
            chip.textContent = s;
            chip.addEventListener('click', () => {
                const input = $('nikText');
                if (input) { input.value = s; $('nikForm').requestSubmit(); }
            });
            box.appendChild(chip);
        });
    }

    async function loadHistory() {
        if (historyLoaded) return;
        historyLoaded = true;
        try {
            const d = await api('nik_api.php?action=history');
            (d.messages || []).forEach((m) => addBubble(m.role === 'user' ? 'user' : 'nik', m.content));
            renderSuggestions(d.suggestions || SUGGESTIONS);
            if (!(d.messages || []).length) {
                addBubble('nik', 'سلام! من نیکم 🤖 — از پیشرفت پروژه‌ها، کارها، مالی و هر چیزی توی سیستم بپرس. گپ معمولی بگو، می‌فهمم!');
            }
        } catch (e) {
            addBubble('nik', 'سلام! من نیکم 🤖 — یک سوال از من بپرس.');
            renderSuggestions(SUGGESTIONS);
        }
    }

    function openPanel() {
        const panel = $('nikPanel');
        if (!panel) return;
        panel.classList.add('nik-open');
        panel.setAttribute('aria-hidden', 'false');
        loadHistory();
        setTimeout(() => { const i = $('nikText'); if (i) i.focus(); }, 60);
    }

    function closePanel() {
        const panel = $('nikPanel');
        if (!panel) return;
        panel.classList.remove('nik-open');
        panel.setAttribute('aria-hidden', 'true');
        if (window.speechSynthesis) window.speechSynthesis.cancel();
    }

    async function ask(question) {
        const q = String(question || '').trim();
        if (!q) return;
        addBubble('user', q);
        const box = $('nikMessages');
        const thinking = document.createElement('div');
        thinking.className = 'nik-msg nik-bot nik-thinking';
        thinking.textContent = '…';
        box.appendChild(thinking);
        box.scrollTop = box.scrollHeight;
        try {
            const d = await api('nik_api.php', { json: { action: 'ask', q } });
            thinking.remove();
            addBubble('nik', d.answer || '—');
            renderSuggestions(d.suggestions || SUGGESTIONS);
        } catch (e) {
            thinking.remove();
            addBubble('nik', 'یک لحظه مشکل پیش آمد — دوباره بگو. (' + e.message + ')');
        }
    }

    function speakLast() {
        if (!lastAnswer || !window.speechSynthesis) return;
        window.speechSynthesis.cancel();
        const u = new SpeechSynthesisUtterance(lastAnswer);
        const isAscii = /^[\x00-\x7F\s]+$/.test(lastAnswer);
        u.lang = isAscii ? 'en-US' : 'fa-IR';
        u.rate = 1.0;
        window.speechSynthesis.speak(u);
    }

    document.addEventListener('click', (event) => {
        const t = event.target;
        if (t && t.id === 'nikFab') { openPanel(); return; }
        if (t && t.id === 'nikCloseBtn') { closePanel(); return; }
        if (t && t.id === 'nikSpeakBtn') { speakLast(); return; }
    }, false);

    document.addEventListener('submit', (event) => {
        if (event.target && event.target.id === 'nikForm') {
            event.preventDefault();
            const input = $('nikText');
            const q = input ? input.value : '';
            if (input) input.value = '';
            ask(q);
        }
    }, false);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closePanel();
    }, false);
})();
