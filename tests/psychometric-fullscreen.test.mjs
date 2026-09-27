import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../resources/js/app.js', import.meta.url), 'utf8')
    .replace("import Swal from 'sweetalert2';", '');

function setup({ cancel = false, supported = true, denied = false, failed = false } = {}) {
    const listeners = {};
    const requests = [];
    const dialogs = [];
    const button = { disabled: false };
    const form = {
        dataset: { startLabel: 'Mulai bagian 1' },
        action: { name: 'action', value: 'start' },
        getAttribute: (name) => name === 'action' ? '/test' : null,
        querySelector: () => button,
    };
    const document = {
        fullscreenEnabled: supported, fullscreenElement: null,
        addEventListener: (name, handler) => { listeners[name] = handler; },
        querySelector: () => null, querySelectorAll: () => [],
        documentElement: { requestFullscreen: async () => {
            if (denied) throw new Error('Denied');
            document.fullscreenElement = document.documentElement;
        } },
    };
    const context = vm.createContext({
        document, AbortSignal, FormData: class {},
        window: { location: { reload() {} } },
        DOMParser: class { parseFromString() { return { querySelector: () => null }; } },
        fetch: async (...args) => {
            assert.equal(document.fullscreenElement, document.documentElement);
            requests.push(args);
            if (failed) throw new Error('Connection lost');
            return { ok: true, text: async () => '' };
        },
        Swal: {
            showValidationMessage: (message) => dialogs.push(message),
            fire: async (options) => {
                dialogs.push(options);
                return { isConfirmed: !cancel && options.preConfirm ? await options.preConfirm() : false };
            },
        },
    });
    vm.runInContext(source, context);
    return { requests, dialogs, button, form, submit: () => listeners.submit({
        target: { closest: () => form }, preventDefault() {},
    }) };
}

test('cancelling readiness does not start the timer on the server', async () => {
    const app = setup({ cancel: true });
    await app.submit();
    assert.equal(app.requests.length, 0);
    assert.equal(app.button.disabled, false);
});

for (const condition of [{ supported: false }, { denied: true }]) {
    test(`fullscreen failure prevents starting: ${JSON.stringify(condition)}`, async () => {
        const app = setup(condition);
        await app.submit();
        assert.equal(app.requests.length, 0);
        assert.equal(typeof app.dialogs[1], 'string');
        assert.equal(app.button.disabled, false);
    });
}

test('confirmation starts only after fullscreen succeeds and prevents duplicate requests', async () => {
    const app = setup();
    await Promise.all([app.submit(), app.submit()]);
    assert.equal(app.requests.length, 1);
    assert.equal(app.dialogs[0].title, 'Sudah siap melakukan tes ?');
});

test('connection failure reports uncertainty and allows retry', async () => {
    const app = setup({ failed: true });
    await app.submit();
    assert.equal(app.dialogs[1].icon, 'error');
    assert.match(app.dialogs[1].text, /timer tetap berjalan/);
    assert.equal(app.button.disabled, false);
    assert.equal(app.form.dataset.pending, undefined);
});

test('start posts to the form URL even when an input is named action', async () => {
    const app = setup();
    await app.submit();
    assert.equal(app.requests.length, 1);
    assert.equal(app.requests[0][0], '/test');
    assert.equal(app.requests[0][1].method, 'POST');
});
