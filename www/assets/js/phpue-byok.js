/*
    PHPue AI chat: "use your own key" mode (browser-only).

    The visitor's Gemini or OpenAI key is kept in this browser (sessionStorage, or localStorage when
    they tick "remember") and sent ONLY to the provider's API (generativelanguage.googleapis.com or
    api.openai.com). It never goes to ai.phpue.co.uk: no request in this file targets our origin
    except GET /llms-full.txt (the grounding), which carries no key. The /chat page also sends a
    Content-Security-Policy whose connect-src only allows 'self' and those two APIs.
*/
(function () {
    'use strict';

    var STORE_KEY = 'phpue-byok';
    var knowledgeCache = null;

    var PROVIDERS = {
        gemini: {
            label: 'Google Gemini',
            keyHelp: 'https://aistudio.google.com/apikey',
            listModels: function (key) {
                return api('https://generativelanguage.googleapis.com/v1beta/models?pageSize=1000', {
                    headers: { 'x-goog-api-key': key }
                }).then(function (data) {
                    return (data.models || [])
                        .filter(function (m) {
                            return (m.supportedGenerationMethods || []).indexOf('generateContent') !== -1
                                && /gemini/.test(m.name)
                                && !/(embedding|tts|image|audio|live|vision|aqa)/.test(m.name);
                        })
                        .map(function (m) { return { id: m.name.replace(/^models\//, ''), label: m.displayName || m.name }; })
                        .sort(byIdDesc);
                });
            },
            preferred: [/flash$/, /flash(?!.*lite)/, /pro/],
            ask: function (key, model, system, history) {
                return api('https://generativelanguage.googleapis.com/v1beta/models/' + encodeURIComponent(model) + ':generateContent', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'x-goog-api-key': key },
                    body: JSON.stringify({
                        system_instruction: { parts: [{ text: system }] },
                        contents: history.map(function (m) {
                            return { role: m.role === 'assistant' ? 'model' : 'user', parts: [{ text: m.text }] };
                        }),
                        generationConfig: { maxOutputTokens: 8192, temperature: 0.4 }
                    })
                }).then(function (data) {
                    var candidate = (data.candidates || [])[0] || {};
                    var parts = (candidate.content && candidate.content.parts) || [];
                    var text = parts.map(function (p) { return p.text || ''; }).join('').trim();
                    if (!text) throw new Error('Gemini returned no text' + (candidate.finishReason ? ' (' + candidate.finishReason + ')' : '') + '.');
                    return { text: text, truncated: candidate.finishReason === 'MAX_TOKENS' };
                });
            }
        },
        openai: {
            label: 'OpenAI',
            keyHelp: 'https://platform.openai.com/api-keys',
            listModels: function (key) {
                return api('https://api.openai.com/v1/models', {
                    headers: { 'Authorization': 'Bearer ' + key }
                }).then(function (data) {
                    return (data.data || [])
                        .map(function (m) { return m.id; })
                        .filter(function (id) {
                            return /^(gpt-|o\d|chatgpt-)/.test(id)
                                && !/(audio|realtime|tts|transcribe|image|search|embedding|instruct|moderation|codex|computer|vision)/.test(id);
                        })
                        .map(function (id) { return { id: id, label: id }; })
                        .sort(byIdDesc);
                });
            },
            preferred: [/^gpt-[\d.]+-mini$/, /^gpt-[\d.]+$/, /^gpt-/],
            ask: function (key, model, system, history) {
                return api('https://api.openai.com/v1/chat/completions', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + key },
                    body: JSON.stringify({
                        model: model,
                        messages: [{ role: 'system', content: system }].concat(history.map(function (m) {
                            return { role: m.role === 'assistant' ? 'assistant' : 'user', content: m.text };
                        }))
                    })
                }).then(function (data) {
                    var choice = (data.choices || [])[0] || {};
                    var text = ((choice.message && choice.message.content) || '').trim();
                    if (!text) throw new Error('OpenAI returned no text' + (choice.finish_reason ? ' (' + choice.finish_reason + ')' : '') + '.');
                    return { text: text, truncated: choice.finish_reason === 'length' };
                });
            }
        }
    };

    function byIdDesc(a, b) { return a.id < b.id ? 1 : a.id > b.id ? -1 : 0; }

    /** fetch + JSON, turning provider error bodies into readable messages. Never includes the key in errors. */
    function api(url, options) {
        options = options || {};
        options.referrerPolicy = 'no-referrer';
        options.credentials = 'omit';
        return fetch(url, options).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) {
                if (!res.ok) {
                    var msg = (data.error && (data.error.message || data.error.status)) || ('HTTP ' + res.status);
                    throw new Error(msg);
                }
                return data;
            });
        });
    }

    // ---------------------------------------------------------------- storage

    function readStore() {
        try {
            var raw = sessionStorage.getItem(STORE_KEY) || localStorage.getItem(STORE_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch (e) { return null; }
    }

    function writeStore(cfg, remember) {
        forget();
        try { (remember ? localStorage : sessionStorage).setItem(STORE_KEY, JSON.stringify(cfg)); } catch (e) { /* storage blocked: keep in memory */ }
        memory = cfg;
    }

    function forget() {
        memory = null;
        try { sessionStorage.removeItem(STORE_KEY); } catch (e) {}
        try { localStorage.removeItem(STORE_KEY); } catch (e) {}
    }

    var memory = readStore();

    function config() { return memory; }

    // ---------------------------------------------------------------- grounding

    function knowledge() {
        if (knowledgeCache) return Promise.resolve(knowledgeCache);
        return fetch('/llms-full.txt', { credentials: 'omit' })
            .then(function (r) { return r.ok ? r.text() : ''; })
            .then(function (t) { knowledgeCache = t; return t; })
            .catch(function () { return ''; });
    }

    /**
     * Ask the visitor's provider. history = [{role: 'user'|'assistant', text}], oldest first, ending with the new question.
     * Leading assistant messages (the welcome) are dropped and same-role runs merged, as both APIs expect.
     */
    function ask(history, preamble) {
        var cfg = config();
        if (!cfg) return Promise.reject(new Error('Add your API key first.'));
        var provider = PROVIDERS[cfg.provider];
        var turns = [];
        history.forEach(function (m) {
            if (!turns.length && m.role !== 'user') return;
            var last = turns[turns.length - 1];
            if (last && last.role === m.role) last.text += '\n\n' + m.text;
            else turns.push({ role: m.role, text: m.text });
        });
        turns = turns.slice(-12);
        if (turns.length && turns[0].role !== 'user') turns.shift();
        return knowledge().then(function (kb) {
            var system = preamble + (kb ? '\n\n=== PHPUE KNOWLEDGE BASE ===\n' + kb : '');
            return provider.ask(cfg.key, cfg.model, system, turns);
        });
    }

    // ---------------------------------------------------------------- settings panel

    function el(id) { return document.getElementById(id); }

    function setStatus(text, isError) {
        var s = el('byokStatus');
        if (!s) return;
        s.textContent = text;
        s.className = 'text-xs ' + (isError ? 'text-red-300' : 'text-gray-400');
    }

    function renderState() {
        var cfg = config();
        var active = !!cfg;
        if (el('byokActive')) el('byokActive').classList.toggle('hidden', !active);
        if (el('byokForm')) el('byokForm').classList.toggle('hidden', active);
        if (el('byokActiveLabel') && cfg) {
            el('byokActiveLabel').textContent = PROVIDERS[cfg.provider].label + ' · ' + cfg.model;
        }
        var badge = el('chatModeBadge');
        if (badge) {
            badge.textContent = active
                ? 'Using your ' + PROVIDERS[cfg.provider].label + ' key (browser-only)'
                : (badge.dataset.serverKey === '1' ? 'Using the site’s Gemini key' : 'Add your own Gemini or OpenAI key to start chatting');
        }
        document.dispatchEvent(new CustomEvent('phpue-byok-change', { detail: { active: active } }));
    }

    function updateKeyHelp() {
        var provider = PROVIDERS[el('byokProvider').value];
        el('byokKeyLink').href = provider.keyHelp;
        el('byokKeyLink').textContent = 'Get a ' + provider.label + ' key';
        var models = el('byokModel');
        models.innerHTML = '<option value="">Load models first</option>';
        models.disabled = true;
        el('byokSave').disabled = true;
    }

    function loadModels() {
        var provider = PROVIDERS[el('byokProvider').value];
        var key = el('byokKey').value.trim();
        if (!key) { setStatus('Paste your API key first.', true); return; }
        setStatus('Checking key and loading models from ' + provider.label + '…');
        el('byokLoad').disabled = true;
        provider.listModels(key).then(function (models) {
            if (!models.length) throw new Error('No chat models available for this key.');
            var select = el('byokModel');
            select.innerHTML = '';
            models.forEach(function (m) {
                var option = document.createElement('option');
                option.value = m.id;
                option.textContent = m.label;
                select.appendChild(option);
            });
            var pick = null;
            provider.preferred.some(function (re) {
                pick = models.filter(function (m) { return re.test(m.id); })[0] || null;
                return !!pick;
            });
            select.value = (pick || models[0]).id;
            select.disabled = false;
            el('byokSave').disabled = false;
            setStatus('Key works. ' + models.length + ' models available. Choose one and click Use this key.');
        }).catch(function (err) {
            setStatus('Could not use this key: ' + err.message, true);
        }).then(function () {
            el('byokLoad').disabled = false;
        });
    }

    function save() {
        var cfg = {
            provider: el('byokProvider').value,
            key: el('byokKey').value.trim(),
            model: el('byokModel').value
        };
        if (!cfg.key || !cfg.model) { setStatus('Load the models and choose one first.', true); return; }
        writeStore(cfg, el('byokRemember').checked);
        el('byokKey').value = '';
        setStatus('');
        renderState();
    }

    function forgetAndReset() {
        forget();
        renderState();
        setStatus('Key removed from this browser.');
    }

    function init() {
        if (!el('byokProvider')) return;
        el('byokProvider').addEventListener('change', updateKeyHelp);
        el('byokKey').addEventListener('input', function () { el('byokSave').disabled = true; });
        el('byokLoad').addEventListener('click', loadModels);
        el('byokSave').addEventListener('click', save);
        el('byokForget').addEventListener('click', forgetAndReset);
        updateKeyHelp();
        renderState();
    }

    window.PHPueBYOK = {
        isActive: function () { return !!config(); },
        providerLabel: function () { var c = config(); return c ? PROVIDERS[c.provider].label : ''; },
        ask: ask
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
