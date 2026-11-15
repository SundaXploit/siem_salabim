import { test } from 'node:test';
import assert from 'node:assert/strict';
import { notificationComposer } from '../../resources/js/notification-composer.js';

function composer(t, bulk = true) {
    const previous = { document: globalThis.document, FormData: globalThis.FormData };
    globalThis.document = { querySelector: () => ({ content: 'test-csrf' }) };
    t.after(() => Object.assign(globalThis, previous));
    const component = notificationComposer({ bulk, asyncSubmit: bulk, templateUrl: '/preview', sendUrl: '/send', operationId: '13f9a375-a2bb-47d4-901b-65b50ae2c767' });
    component.$refs = { notificationDialog: { open: false, showModal() { this.open = true; }, close() { this.open = false; } } };
    component.$dispatch = () => {};
    return component;
}

test('bulk selection uses independent rendered messages and keeps edits when reopened', async t => {
    const component = composer(t);
    const messages = [{ destination_key: 'a', label: 'Tim A', alert_count: 1, message: 'Agent A only' }, { destination_key: 'b', label: 'Tim B', alert_count: 2, message: 'Agent B only' }];
    const custom = messages.map(item => ({ ...item, message: '<b>Agent:</b> ' }));
    const templates = [{ id: 1, name: 'Default', messages }, { id: 2, name: 'Custom / Pesan Bebas', messages: custom }];
    t.mock.method(globalThis, 'fetch', async () => ({ ok: true, json: async () => ({ templates, default_messages: messages }) }));
    await component.openNotifModal([1, 2, 3]);
    assert.deepEqual(component.destinations, ['Tim A', 'Tim B']);
    component.messages[0].message = 'Edited A';
    assert.equal(component.messages[1].message, 'Agent B only');
    component.closeNotifModal();
    await component.openNotifModal([1, 2, 3]);
    assert.equal(component.messages[0].message, 'Edited A');
    component.selectTemplate(templates[1]);
    component.messages[0].message += 'Manual A';
    assert.equal(templates[1].messages[0].message, '<b>Agent:</b> ');
    assert.equal(component.messages[1].message, '<b>Agent:</b> ');
    for (let index = 0; index < 8; index++) component.addEvidence();
    assert.equal(component.evidences.length, 5);
    assert.equal(new Set(component.evidences.map(item => item.id)).size, 5);
});

test('single custom uses the same freely editable composer and blocks duplicate native submits', async t => {
    const component = composer(t, false);
    const template = { id: 2, name: 'Custom / Pesan Bebas', body: '<b>Agent:</b> ' };
    t.mock.method(globalThis, 'fetch', async () => ({ ok: true, json: async () => ({ templates: [template], default_message: template.body, destinations: ['SOC'] }) }));
    await component.openNotifModal();
    component.selectTemplate(template);
    assert.equal(component.message, '<b>Agent:</b> ');
    component.message = '🚨 Manual free message';
    const event = { preventDefault: t.mock.fn() };
    await component.submitNotification(event);
    assert.equal(component.submitting, true);
    assert.equal(event.preventDefault.mock.callCount(), 0);
    await component.submitNotification(event);
    assert.equal(event.preventDefault.mock.callCount(), 1);
    assert.equal(component.characterCount('🚨'), 1);
});

test('lost bulk response preserves UUID, exact edited messages, and attachments for retry', async t => {
    const component = composer(t);
    component.destinations = ['SOC'];
    component.messages = [{ destination_key: 'a', message: 'Literal {agent_name} edited by analyst' }];
    component.alertIds = ['1', '2'];
    const NativeFormData = globalThis.FormData;
    const file = new File(['evidence content'], 'bukti.png', { type: 'image/png' });
    globalThis.FormData = class extends NativeFormData {
        constructor() {
            super();
            this.set('operation_id', component.operationId);
            component.alertIds.forEach(id => this.append('alert_ids[]', id));
            this.set('messages[0][destination_key]', 'a');
            this.set('messages[0][message]', component.message);
            this.append('evidence[]', new File([], ''));
            this.append('evidence[]', file);
        }
    };
    const fetch = t.mock.method(globalThis, 'fetch', async () => { throw Error('Lost response'); });
    const event = { target: { reset() {} }, preventDefault() {} };
    await component.submitNotification(event);
    await component.submitNotification(event);
    assert.equal(fetch.mock.callCount(), 2);
    for (const call of fetch.mock.calls) {
        const [url, request] = call.arguments;
        assert.equal(url, '/send');
        assert.equal(request.headers['Content-Type'], undefined);
        assert.equal(request.body.get('operation_id'), '13f9a375-a2bb-47d4-901b-65b50ae2c767');
        assert.equal(request.body.get('messages[0][message]'), component.message);
        assert.equal(request.body.getAll('evidence[]').length, 1);
        assert.equal(await request.body.get('evidence[]').text(), 'evidence content');
    }
    assert.equal(component.submitting, false);
    assert.equal(component.loadedSelection, null);
});

test('preview errors disable sending and do not expose stale destinations', async t => {
    const component = composer(t);
    component.destinations = ['Old route'];
    component.messages = [{ message: 'Old draft' }];
    t.mock.method(globalThis, 'fetch', async () => ({ ok: false, status: 422, json: async () => ({ errors: { alert_ids: ['ACK owner changed'] } }) }));
    await component.openNotifModal([1]);
    assert.equal(component.notificationError, 'ACK owner changed');
    assert.deepEqual(component.destinations, []);
    assert.equal(component.canSend, false);
});
