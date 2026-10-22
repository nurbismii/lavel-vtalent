import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../resources/js/technical-assessments.js', import.meta.url), 'utf8').replace("import Swal from 'sweetalert2';", '');

function setup(respond = async () => ({ ok: true, json: async () => ({ section: 1, revision: 1, active: true, completed: false, remaining: 60 }) }), dataset = {}, storage = new Map()) {
    const listeners = {};
    const intervals = new Map();
    const timeouts = [];
    const requests = [];
    const alerts = [];
    const fields = [{ disabled: false, name: 'answers[A01]' }, { disabled: false, name: 'answers[process]' }];
    const status = {};
    const timer = { classList: { toggle() {} } };
    const revision = { value: '0' };
    const activityStatus = {};
    const answers = new Map([['answers[A01]', 'C'], ['answers[process]', 'Langkah saya.']]);
    const elements = { '[data-assessment-status]': status, '[data-assessment-timer]': timer, '[data-assessment-activity-status]': activityStatus, '[name="_token"]': { value: 'csrf' }, '[name="revision"]': revision, '[data-assessment-progress]': {}, '[data-assessment-fullscreen]': { addEventListener() {} } };
    const buttons = [{ disabled: false }];
    const form = {
        dataset: { section: '1', revision: '0', remaining: '60', endpoint: '/save', activityUrl: '/activity', stateUrl: '/state', ...dataset },
        querySelector: selector => elements[selector],
        querySelectorAll: selector => selector.startsWith('[name^=') ? fields : selector === '.assessment-question' ? [{}, {}] : buttons,
        addEventListener: (name, callback) => listeners[`form:${name}`] = callback,
    };
    let elapsed = 0;
    let reloads = 0;
    const location = { reload: () => reloads++ };
    const document = { hidden: false, fullscreenElement: null, documentElement: {}, querySelector: selector => selector === '[data-assessment-form]' ? form : null, addEventListener: (name, callback) => listeners[name] = callback };
    vm.runInNewContext(source, {
        document, window: { addEventListener: (name, callback) => listeners[name] = callback, location, sessionStorage: { getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value) } },
        performance: { now: () => elapsed }, Date, AbortSignal, Swal: { fire: async options => { alerts.push(options); return { isConfirmed: true }; } },
        setInterval: (callback, ms) => intervals.set(ms, callback), setTimeout: callback => { timeouts.push(callback); return timeouts.length; }, clearTimeout() {},
        FormData: class { [Symbol.iterator]() { return answers.entries(); } },
        fetch: async (url, options) => { requests.push({ url, ...options }); return url === '/activity' ? { ok: true } : respond(url, options); },
    });
    return { alerts, location, form, listeners, intervals, timeouts, requests, fields, status, timer, revision, activityStatus, answers, setElapsed: value => elapsed = value, reloads: () => reloads };
}

test('autosave sends choices and text and adopts the server revision', async () => {
    const state = setup();
    state.listeners['form:input']();
    await state.timeouts.at(-1)();

    const request = state.requests.find(item => item.url === '/save');
    assert.deepEqual(JSON.parse(request.body), { action: 'save', section: 1, revision: 0, answers: { A01: 'C', process: 'Langkah saya.' } });
    assert.equal(state.revision.value, 1);
    assert.equal(state.status.textContent, 'Jawaban tersimpan');
});

test('typing while a save is pending is saved next with the new revision', async () => {
    let resolve;
    const state = setup(() => new Promise(done => resolve = done));
    state.listeners['form:input']();
    const pending = state.timeouts.at(-1)();
    state.answers.set('answers[A01]', 'B');
    state.listeners['form:input']();
    resolve({ ok: true, json: async () => ({ section: 1, revision: 1, active: true, completed: false, remaining: 60 }) });
    await pending;
    assert.equal(state.status.textContent, 'Ada perubahan yang belum tersimpan');
    const next = state.timeouts.at(-1)();
    const requests = state.requests.filter(item => item.url === '/save');
    assert.equal(JSON.parse(requests[0].body).answers.A01, 'C');
    assert.equal(JSON.parse(requests[1].body).answers.A01, 'B');
    assert.equal(JSON.parse(requests[1].body).revision, 1);
    resolve({ ok: true, json: async () => ({ section: 1, revision: 2, active: true, completed: false, remaining: 59 }) });
    await next;
});

test('revision conflict locks editing and keeps unsaved text visible', async () => {
    const state = setup(async () => ({ ok: false, status: 409, json: async () => ({ message: 'Status berubah di sesi lain.' }) }));
    state.listeners['form:input']();
    await state.timeouts.at(-1)();
    assert.equal(state.fields[0].disabled, true);
    assert.equal(state.answers.get('answers[process]'), 'Langkah saya.');
    assert.match(state.status.textContent, /Status berubah/);
    assert.equal(state.reloads(), 0);
});

test('poll updates extended server time and timeout reloads a closed stage', async () => {
    const state = setup(async url => ({ ok: true, json: async () => url === '/state' ? { section: 1, revision: 0, active: true, completed: false, remaining: 120 } : { section: 2, revision: 1, active: false, completed: false, remaining: null } }));
    await state.intervals.get(10000)();
    await state.intervals.get(250)();
    assert.equal(state.timer.textContent, '02:00');
    state.setElapsed(121000);
    await state.intervals.get(250)();
    assert.equal(state.reloads(), 1);
});

test('focus and tab events are logged separately from answer saves', async () => {
    const state = setup();
    state.listeners.blur();
    const activity = state.requests.filter(item => item.url === '/activity').map(item => JSON.parse(item.body).event);
    assert.deepEqual(activity, ['page_loaded', 'window_blur']);
    assert.equal(state.requests.some(item => item.url === '/save'), false);
});

test('connection failure keeps answers and autosave retries', async () => {
    let failing = true;
    const state = setup(async () => {
        if (failing) throw new Error('Koneksi terputus.');
        return { ok: true, json: async () => ({ section: 1, revision: 1, active: true, completed: false, remaining: 60 }) };
    });
    state.listeners['form:input']();
    await state.timeouts.at(-1)();
    assert.match(state.status.textContent, /belum tersimpan/);
    assert.equal(state.answers.get('answers[A01]'), 'C');
    failing = false;
    await state.intervals.get(5000)();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(state.status.textContent, 'Jawaban tersimpan');
});

test('Next submits the four choices and reloads the process screen within the same section', async () => {
    const state = setup(async () => ({ ok: true, json: async () => ({ section: 1, revision: 2, active: true, completed: false, reasoning_locked: true, remaining: 45 }) }));
    state.answers.clear();
    for (const [question, answer] of Object.entries({ B01: 'B', B02: 'C', B03: 'A', B04: 'C' })) {
        state.answers.set(`answers[${question}]`, answer);
    }

    await state.listeners['form:submit']({ preventDefault() {}, submitter: { value: 'next' } });

    const request = state.requests.find(item => item.url === '/save');
    assert.deepEqual(JSON.parse(request.body), { action: 'next', section: 1, revision: 0, answers: { B01: 'B', B02: 'C', B03: 'A', B04: 'C' } });
    assert.equal(state.reloads(), 1);
    assert.equal(state.fields[0].disabled, true);
});

test('a failed Next keeps choices editable and can be retried without finishing the section', async () => {
    let failing = true;
    const state = setup(async () => {
        if (failing) throw new Error('Koneksi terputus.');
        return { ok: true, json: async () => ({ section: 1, revision: 2, active: true, completed: false, reasoning_locked: true, remaining: 40 }) };
    });

    await state.listeners['form:submit']({ preventDefault() {}, submitter: { value: 'next' } });

    assert.equal(state.fields[0].disabled, false);
    assert.equal(state.reloads(), 0);
    assert.equal(state.answers.get('answers[A01]'), 'C');
    failing = false;
    await state.listeners['form:submit']({ preventDefault() {}, submitter: { value: 'next' } });
    assert.equal(state.reloads(), 1);
});

test('simulation Next reloads at the facts below without changing the section', async () => {
    const state = setup(async () => ({ ok: true, json: async () => ({ section: 1, revision: 2, active: true, completed: false, simulation_locked: true, remaining: 420 }) }), { simulation: 'true', simulationLocked: 'false' });
    state.answers.clear();
    for (const number of [1, 2, 3]) {
        for (const field of ['priority', 'action', 'evidence', 'coordination']) state.answers.set(`answers[priority_${number}_${field}]`, 'Jawaban kandidat.');
    }

    await state.listeners['form:submit']({ preventDefault() {}, submitter: { value: 'next' } });

    const body = JSON.parse(state.requests.find(item => item.url === '/save').body);
    assert.equal(body.action, 'next');
    assert.equal(body.section, 1);
    assert.equal(Object.keys(body.answers).length, 12);
    assert.equal(state.location.hash, 'simulation-facts');
    assert.equal(state.reloads(), 1);
});

test('autosaving a facts response keeps the simulation phase open and uses remaining time', async () => {
    const state = setup(async () => ({ ok: true, json: async () => ({ section: 1, revision: 1, active: true, completed: false, simulation_locked: true, remaining: 420 }) }), { simulation: 'true', simulationLocked: 'true' });
    state.answers.clear();
    state.answers.set('answers[revised_decision]', 'Simpan bukti dan eskalasi.');

    state.listeners['form:input']();
    await state.timeouts.at(-1)();
    await state.intervals.get(250)();

    assert.deepEqual(JSON.parse(state.requests.find(item => item.url === '/save').body).answers, { revised_decision: 'Simpan bukti dan eskalasi.' });
    assert.equal(state.timer.textContent, '07:00');
    assert.equal(state.reloads(), 0);
});

test('behavior reminder appears once after five of seven minutes without resetting the timer', async () => {
    const state = setup(undefined, { remaining: '420', twoMinuteWarning: 'true' });
    state.setElapsed(299000);
    await state.intervals.get(250)();
    assert.equal(state.alerts.length, 0);
    state.setElapsed(300000);
    await state.intervals.get(250)();
    assert.equal(state.alerts.length, 1);
    assert.equal(state.alerts[0].title, 'Sisa waktu 2 menit');
    assert.equal(state.timer.textContent, '02:00');
    state.setElapsed(301000);
    await state.intervals.get(250)();
    assert.equal(state.alerts.length, 1);
    assert.equal(state.timer.textContent, '01:59');
    assert.equal(state.fields[0].disabled, false);
});

test('behavior reminder remains acknowledged after refreshing the same stage', async () => {
    const storage = new Map();
    const first = setup(undefined, { remaining: '120', twoMinuteWarning: 'true' }, storage);
    await first.intervals.get(250)();
    assert.equal(first.alerts.length, 1);
    const refreshed = setup(undefined, { remaining: '110', twoMinuteWarning: 'true' }, storage);
    await refreshed.intervals.get(250)();
    assert.equal(refreshed.alerts.length, 0);
    assert.equal(refreshed.timer.textContent, '01:50');
});

test('other assessment stages do not show the behavior reminder', async () => {
    const state = setup(undefined, { remaining: '120', twoMinuteWarning: 'false' });
    await state.intervals.get(250)();
    assert.equal(state.alerts.length, 0);
});
