import { test } from 'node:test';
import assert from 'node:assert/strict';
import { alertTable } from '../../resources/js/live-alerts.js';

function component(t) {
    const previous = { document: globalThis.document, window: globalThis.window, DOMParser: globalThis.DOMParser };
    globalThis.document = { querySelector: () => ({ content: 'test-csrf' }) };
    globalThis.window = { location: { href: 'http://localhost/live-alerts?status=new&per_page=100' } };
    t.after(() => Object.assign(globalThis, previous));
    const table = alertTable('/live-alerts/triage');
    table.$root = { querySelectorAll: selector => selector === '[data-triage-id]'
        ? ['1', '2'].map(id => ({ dataset: { triageId: id } })) : [] };
    table.$refs = { bulkDialog: { showModal() {}, close() {} }, bulkReason: { focus() {} }, bulkConfirm: { focus() {} } };
    table.$nextTick = callback => callback();
    table.$dispatch = () => {};
    table.readPageIds();
    return table;
}

test('select all uses only the selectable rows on this page and clears cleanly', t => {
    const table = component(t);
    assert.equal(table.bulkMode, false);
    assert.equal(table.refreshPaused, false);
    table.toggleAll(true);
    assert.deepEqual(table.selectedIds, []);
    table.startBulkMode();
    assert.equal(table.bulkMode, true);
    assert.equal(table.refreshPaused, true);
    table.toggleAll(true);
    assert.deepEqual(table.selectedIds, ['1', '2']);
    assert.equal(table.allSelected, true);
    table.selectedIds.pop();
    assert.equal(table.allSelected, false);
    table.cancelBulkMode();
    assert.deepEqual(table.selectedIds, []);
    assert.equal(table.bulkMode, false);
    assert.equal(table.refreshPaused, false);
});

test('refresh does not send requests while alerts are selected or a row dialog is open', async t => {
    const table = component(t);
    const fetch = t.mock.method(globalThis, 'fetch', async () => { throw new Error('Unexpected refresh'); });
    table.startBulkMode();
    table.toggleAll(true);
    await table.refreshNow();
    table.cancelBulkMode();
    table.$root.querySelectorAll = () => [{ getClientRects: () => [1] }];
    await table.refreshNow();
    assert.equal(fetch.mock.callCount(), 0);
});

test('ignore rejects a whitespace reason without calling the server', async t => {
    const table = component(t);
    table.startBulkMode();
    table.selectedIds = ['1'];
    table.openBulk('ignore');
    table.bulkReason = '    ';
    const fetch = t.mock.method(globalThis, 'fetch', async () => {});
    await table.submitBulk();
    assert.match(table.bulkError, /Alasan/);
    assert.equal(fetch.mock.callCount(), 0);
    assert.deepEqual(table.selectedIds, ['1']);
});

test('duplicate submit is blocked and success updates the table only once', async t => {
    const table = component(t);
    table.startBulkMode();
    table.toggleAll(true);
    table.openBulk('ignore');
    table.bulkReason = '  Pemeliharaan terkonfirmasi.  ';
    let resolve;
    const fetch = t.mock.method(globalThis, 'fetch', () => new Promise(done => { resolve = done; }));
    const refresh = t.mock.method(table, 'refreshData', async () => {});
    const first = table.submitBulk();
    await table.submitBulk();
    assert.equal(fetch.mock.callCount(), 1);
    const [url, options] = fetch.mock.calls[0].arguments;
    assert.equal(url, '/live-alerts/triage');
    assert.equal(options.headers['X-CSRF-TOKEN'], 'test-csrf');
    assert.deepEqual(JSON.parse(options.body), {
        action: 'ignore', alert_ids: ['1', '2'], reason: 'Pemeliharaan terkonfirmasi.',
    });
    resolve({ ok: true, json: async () => ({ success: true, message: '2 alert berhasil diabaikan.' }) });
    await first;
    assert.equal(refresh.mock.callCount(), 1);
    assert.equal(table.bulkAction, null);
    assert.equal(table.bulkMode, false);
    assert.equal(table.submitting, false);
    assert.deepEqual(table.selectedIds, []);
    assert.equal(table.bulkMessage, '2 alert berhasil diabaikan.');
});

test('server validation failure retains selection and reason for correction', async t => {
    const table = component(t);
    table.startBulkMode();
    table.selectedIds = ['1'];
    table.openBulk('ignore');
    table.bulkReason = 'Aktivitas terkonfirmasi.';
    t.mock.method(globalThis, 'fetch', async () => ({
        ok: false, status: 422, json: async () => ({ errors: { alert_ids: ['Alert sudah tidak tersedia.'] } }),
    }));
    const refresh = t.mock.method(table, 'refreshData', async () => {});
    await table.submitBulk();
    assert.equal(table.bulkError, 'Alert sudah tidak tersedia.');
    assert.equal(table.bulkReason, 'Aktivitas terkonfirmasi.');
    assert.deepEqual(table.selectedIds, ['1']);
    assert.equal(table.bulkAction, 'ignore');
    assert.equal(table.bulkMode, true);
    assert.equal(refresh.mock.callCount(), 0);
    assert.equal(table.submitting, false);
});

test('a refresh already in flight cannot replace newly selected rows', async t => {
    const table = component(t);
    let resolve;
    let replaced = false;
    table.$root.querySelector = () => ({ set innerHTML(value) { replaced = true; } });
    const fetch = t.mock.method(globalThis, 'fetch', () => new Promise(done => { resolve = done; }));
    globalThis.DOMParser = class { parseFromString() { return { getElementById: () => ({ innerHTML: 'new rows' }) }; } };
    const pending = table.refreshData();
    await table.refreshData();
    assert.equal(fetch.mock.callCount(), 1);
    table.selectedIds = ['1'];
    resolve({ ok: true, redirected: false, text: async () => 'updated table' });
    await pending;
    assert.equal(replaced, false);
    assert.deepEqual(table.selectedIds, ['1']);
    assert.equal(table.isRefreshing, false);
});
