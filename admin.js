'use strict';

// Everything returned by the gateway is inserted as text, never as HTML.
const csrf = document.querySelector('meta[name="csrf-token"]').content;
const byId = id => document.getElementById(id);
let snapshot = null;
let clockOffset = 0;
let settingsLoaded = false;
let settingsDirty = false;
let refreshBusy = false;
let sessionExpired = false;
let testRequestId = null;

function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = String(text);
    return node;
}

function notify(message, error = false) {
    const box = byId('notification');
    box.className = error ? 'notice error' : 'notice';
    box.textContent = message;
    box.hidden = false;
}

async function request(action, values = null) {
    const options = {credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json'}};
    if (values !== null) {
        options.method = 'POST';
        const data = new FormData();
        data.set('csrf', csrf);
        for (const [key, value] of Object.entries(values)) data.set(key, String(value));
        options.body = data;
    }
    const controller = new AbortController();
    options.signal = controller.signal;
    const timer = setTimeout(() => controller.abort(), 15000);
    try {
        const response = await fetch('/admin.php?action=' + encodeURIComponent(action), options);
        const data = await response.json();
        if (response.status === 401) {
            sessionExpired = true;
            location.assign('/admin.php');
            throw new Error('Your session expired. Sign in again.');
        }
        if (!response.ok) throw new Error(data.error || 'The request could not be completed.');
        return data;
    } catch (error) {
        if (error.name === 'AbortError') throw new Error('Request timed out. Refresh the queue before trying again.');
        throw error;
    } finally {
        clearTimeout(timer);
    }
}

function duration(seconds) {
    seconds = Math.max(0, Math.ceil(seconds));
    if (seconds < 60) return seconds + 's';
    if (seconds < 3600) return Math.floor(seconds / 60) + 'm ' + String(seconds % 60).padStart(2, '0') + 's';
    return Math.floor(seconds / 3600) + 'h ' + Math.floor((seconds % 3600) / 60) + 'm';
}

function isoTime(value) {
    if (!value) return '';
    const date = typeof value === 'number' ? new Date(value * 1000) : new Date(value);
    return Number.isNaN(date.getTime()) ? String(value) : date.toISOString().replace('.000Z', 'Z');
}

function emptyList(container, title, detail) {
    const box = element('div', 'empty');
    box.append(element('strong', '', title), element('span', '', detail));
    container.replaceChildren(box);
}

function renderMessages(container, records, queue = false) {
    container.replaceChildren();
    if (!records.length) {
        emptyList(container, queue ? 'All clear. No messages waiting.' : 'No messages to show.', queue ? 'New messages will appear here before they are sent.' : 'Results will appear here as the queue is processed.');
        return;
    }
    for (const record of records) {
        const row = element('article', 'message');
        const body = element('div', 'message-body');
        const head = element('div', 'message-head');
        const status = String(record.status || 'queued');
        const statusClass = ['queued', 'sending', 'retry', 'sent', 'failed', 'uncertain', 'expired', 'cancelled'].includes(status) ? status : '';
        head.append(element('span', 'message-phone', record.phone || 'Unknown number'), element('span', 'badge ' + statusClass, status));
        body.append(head, element('p', 'message-text', record.text || ''));
        const meta = element('div', 'message-meta');
        const timestamp = queue ? record.created_at : (record.sent_at || record.finished_at || record.created_at);
        if (timestamp) meta.append(element('time', '', isoTime(timestamp)));
        meta.append(element('span', '', 'From ' + (record.source_ip || 'unknown')), element('span', '', (record.attempts || 0) + ' attempt(s)'));
        body.append(meta);
        if (record.reason) body.append(element('p', 'message-reason', record.reason));
        if (status === 'uncertain') body.append(element('p', 'message-reason', 'Delivery was not confirmed. Not retried automatically.'));
        row.append(body);
        if (queue && status !== 'sending') {
            const actions = element('div', 'message-actions');
            const remove = element('button', 'button danger small', 'Remove');
            remove.type = 'button';
            remove.setAttribute('aria-label', 'Remove queued message to ' + record.phone);
            remove.addEventListener('click', async () => {
                if (!window.confirm('Remove this queued message to ' + record.phone + '?')) return;
                remove.disabled = true;
                try {
                    const result = await request('remove', {id: record.id});
                    notify(result.message);
                    await refresh();
                } catch (error) {
                    notify(error.message, true);
                    remove.disabled = false;
                }
            });
            actions.append(remove);
            row.append(actions);
        }
        container.append(row);
    }
}

function renderHistory() {
    if (!snapshot) return;
    const filter = byId('history-filter').value;
    const history = (snapshot.history || []).filter(record => filter === 'all' || (filter === 'sent' ? record.status === 'sent' : ['failed', 'uncertain', 'expired'].includes(record.status)));
    renderMessages(byId('history-list'), history);
}

function tick() {
    if (!snapshot) return;
    const now = Date.now() / 1000 + clockOffset;
    const cooldown = Math.max(0, (snapshot.cooldown_until || 0) - now);
    const wait = Math.max(0, (snapshot.next_send_at || 0) - now);
    const banner = byId('status-banner');
    let title, detail, state;
    if (snapshot.paused) {
        title = 'Sending is paused';
        detail = 'New requests can still join the queue. Resume when you are ready.';
        state = 'hold';
    } else if (cooldown > 0) {
        title = 'Startup hold · ' + duration(cooldown) + ' remaining';
        detail = 'Waiting one full window after RAM state was lost, so a restart cannot bypass the sending limit.';
        state = 'hold';
    } else if (snapshot.attempts_in_window >= snapshot.limit) {
        title = 'Sending budget used';
        detail = 'Messages stay queued until earlier attempts leave the rolling window.';
        state = 'hold';
    } else if ((snapshot.queue || []).length) {
        title = wait > 0 ? 'Queue is waiting for the next slot' : 'Queue ready to send';
        detail = 'Messages are processed oldest first. Every attempt counts towards the shared limit.';
        state = 'ready';
    } else {
        title = 'Ready for new messages';
        detail = 'The queue is empty. Sending limits remain active for new requests.';
        state = 'ready';
    }
    banner.className = 'status-banner ' + state;
    byId('status-title').textContent = title;
    byId('status-detail').textContent = detail;
    byId('metric-next').textContent = snapshot.paused ? 'Paused' : (cooldown > 0 ? duration(cooldown) : wait > 0 ? duration(wait) : 'Ready');
}

function applySnapshot(data) {
    snapshot = data;
    clockOffset = Number(data.now || Date.now() / 1000) - Date.now() / 1000;
    byId('metric-queue').textContent = (data.queue || []).length;
    byId('queue-count').textContent = (data.queue || []).length;
    byId('metric-budget').textContent = data.attempts_in_window + ' / ' + data.limit;
    byId('metric-window').textContent = 'In the last ' + duration(data.window_seconds);
    byId('metric-interval').textContent = 'At least ' + data.settings.interval_seconds + ' seconds between attempts';
    byId('metric-sent').textContent = (data.history || []).filter(record => record.status === 'sent').length;
    byId('history-note').textContent = 'Up to ' + data.settings.history_limit + ' recent results in RAM. Times are UTC.';
    const pause = byId('pause-button');
    pause.textContent = data.paused ? 'Resume sending' : 'Pause sending';
    pause.disabled = false;
    byId('test-form').querySelector('button').disabled = false;
    renderMessages(byId('queue-list'), data.queue || [], true);
    renderHistory();
    if (!settingsLoaded || !settingsDirty) {
        const form = byId('settings-form');
        for (const [key, value] of Object.entries(data.settings)) {
            const input = form.elements.namedItem(key);
            if (input) {
                if (input.type === 'checkbox') input.checked = Boolean(value);
                else input.value = value;
            }
        }
        form.querySelector('button').disabled = false;
        byId('settings-state').textContent = 'Current settings';
        settingsLoaded = true;
    }
    tick();
    byId('connection').className = 'connection online';
    byId('connection').textContent = 'Connected';
    byId('last-updated').textContent = 'Updated ' + new Date().toLocaleTimeString();
}

async function refresh() {
    if (refreshBusy || sessionExpired) return;
    refreshBusy = true;
    try {
        applySnapshot(await request('snapshot'));
    } catch (error) {
        byId('connection').className = 'connection offline';
        byId('connection').textContent = 'Connection lost';
        byId('status-title').textContent = 'Cannot read the gateway';
        byId('status-detail').textContent = error.message + ' Displayed counters may be out of date.';
        byId('status-banner').className = 'status-banner hold';
    } finally {
        refreshBusy = false;
    }
}

const tabs = [...document.querySelectorAll('[data-view]')];
function selectTab(tab) {
    for (const item of tabs) {
        const active = item === tab;
        item.classList.toggle('active', active);
        item.setAttribute('aria-selected', String(active));
        item.tabIndex = active ? 0 : -1;
        byId('view-' + item.dataset.view).hidden = !active;
    }
}
tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => selectTab(tab));
    tab.addEventListener('keydown', event => {
        let next;
        if (event.key === 'ArrowRight') next = tabs[(index + 1) % tabs.length];
        if (event.key === 'ArrowLeft') next = tabs[(index + tabs.length - 1) % tabs.length];
        if (event.key === 'Home') next = tabs[0];
        if (event.key === 'End') next = tabs[tabs.length - 1];
        if (next) {event.preventDefault(); selectTab(next); next.focus();}
    });
});

byId('history-filter').addEventListener('change', renderHistory);
byId('pause-button').addEventListener('click', async () => {
    if (!snapshot) return;
    const button = byId('pause-button');
    button.disabled = true;
    try {
        const result = await request('pause', {paused: !snapshot.paused});
        notify(result.message);
        await refresh();
    } catch (error) {
        notify(error.message, true);
    } finally {
        button.disabled = false;
    }
});

byId('test-form').addEventListener('input', () => {
    byId('character-count').textContent = new TextEncoder().encode(byId('test-form').elements.text.value).length + ' / 160 bytes';
    testRequestId = null;
});
byId('test-form').addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const button = form.querySelector('button');
    button.disabled = true;
    // Keep the ID after a network timeout: trying the same form again must not duplicate it.
    if (!testRequestId) {
        const bytes = new Uint8Array(16);
        crypto.getRandomValues(bytes);
        testRequestId = 'admin-' + [...bytes].map(value => value.toString(16).padStart(2, '0')).join('');
    }
    try {
        const result = await request('enqueue', {phone: form.elements.phone.value, text: form.elements.text.value, request_id: testRequestId});
        notify(result.message);
        form.elements.text.value = '';
        byId('character-count').textContent = '0 / 160 bytes';
        testRequestId = null;
        await refresh();
    } catch (error) {
        notify(error.message, true);
    } finally {
        button.disabled = false;
    }
});

byId('settings-form').addEventListener('input', () => {
    settingsDirty = true;
    byId('settings-state').textContent = 'Unsaved changes';
});
byId('settings-form').addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const button = form.querySelector('button');
    button.disabled = true;
    try {
        const values = Object.fromEntries(new FormData(form));
        values.paused = form.elements.paused.checked;
        const result = await request('save_settings', values);
        settingsDirty = false;
        notify(result.message);
        await refresh();
    } catch (error) {
        notify(error.message, true);
    } finally {
        button.disabled = false;
    }
});

byId('inbox-refresh').addEventListener('click', async () => {
    const button = byId('inbox-refresh');
    button.disabled = true;
    button.textContent = 'Loading…';
    try {
        const data = await request('inbox');
        const list = byId('inbox-list');
        list.replaceChildren();
        if (!data.messages.length) emptyList(list, 'Inbox is empty.', 'No received messages were returned by the router.');
        for (const message of data.messages) {
            const row = element('article', 'message');
            const body = element('div', 'message-body');
            body.append(element('div', 'message-head', message.phone || message['phone-number'] || 'Unknown sender'), element('p', 'message-text', message.message || message.text || ''), element('div', 'message-meta', message.timestamp || message.time || ''));
            row.append(body);
            list.append(row);
        }
        button.textContent = 'Refresh inbox';
    } catch (error) {
        notify(error.message, true);
        button.textContent = 'Load inbox';
    } finally {
        button.disabled = false;
    }
});

document.addEventListener('visibilitychange', () => {if (!document.hidden) refresh();});
refresh();
setInterval(() => {if (!document.hidden) refresh();}, 5000);
setInterval(() => {if (byId('connection').classList.contains('online')) tick();}, 1000);
