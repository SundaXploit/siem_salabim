import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { alertEvidence } from '../../resources/js/alert-evidence.js';

function viewer(t, storedAnalysis = null, evidence = { alert_3: { log: '</script><img src=x onerror=alert(1)>' }, alert_9: { log: 'second log' } }) {
    const previousDocument = globalThis.document;
    globalThis.document = { querySelector: () => ({ content: 'csrf-test' }) };
    t.after(() => { globalThis.document = previousDocument; });
    const component = alertEvidence({ analysisUrl: '/live-alerts/triage/ai-analysis', analysisPayload: { alert_ids: [3, 9] }, storedAnalysis });
    component.$refs = { rawEvidence: { textContent: JSON.stringify(evidence) } };
    component.init();
    return component;
}

test('raw viewer keeps selected logs in JSON and table without interpreting log HTML or calling AI', t => {
    const request = t.mock.method(globalThis, 'fetch', async () => { throw Error('Unexpected AI request'); });
    const component = viewer(t);
    assert.equal(component.mode, 'json');
    assert.equal(request.mock.callCount(), 0);
    assert.equal(component.highlighted.includes('<img'), false);
    assert.equal(component.highlighted.includes('&lt;img'), true);
    assert.deepEqual(component.flatRows.map(row => row[0]), ['alert_3.log', 'alert_9.log']);
});

test('the Wazuh sample keeps all metadata in JSON, table and copied JSON', async t => {
    const source = JSON.parse(readFileSync(new URL('../Fixtures/wazuh-pam-session.json', import.meta.url), 'utf8'));
    const component = viewer(t, null, source);
    assert.deepEqual(JSON.parse(component.highlighted.replace(/<\/?span\b[^>]*>/g, '')), source);
    const rows = Object.fromEntries(component.flatRows);
    assert.equal(rows['rule.description'], source.rule.description);
    assert.equal(rows.description, undefined);
    assert.equal(rows['agent.id'], '007');
    assert.equal(rows['data.uid'], '0');
    assert.equal(rows['rule.mail'], 'true');
    assert.equal(rows.full_log, source.full_log);
    assert.equal(rows.timestamp, source.timestamp);
    assert.equal(rows['@timestamp'], source['@timestamp']);
    for (const field of ['tsc', 'gdpr', 'gpg13', 'hipaa', 'groups', 'pci_dss', 'nist_800_53']) {
        assert.deepEqual(JSON.parse(rows[`rule.${field}`]), source.rule[field]);
    }
    for (const field of ['id', 'tactic', 'technique']) {
        assert.deepEqual(JSON.parse(rows[`rule.mitre.${field}`]), source.rule.mitre[field]);
    }

    let copied;
    const previousNavigator = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
    const previousWindow = Object.getOwnPropertyDescriptor(globalThis, 'window');
    Object.defineProperty(globalThis, 'navigator', { configurable: true, value: { clipboard: { writeText: async text => { copied = text; } } } });
    Object.defineProperty(globalThis, 'window', { configurable: true, value: { isSecureContext: true } });
    t.after(() => {
        if (previousNavigator) Object.defineProperty(globalThis, 'navigator', previousNavigator);
        else delete globalThis.navigator;
        if (previousWindow) Object.defineProperty(globalThis, 'window', previousWindow);
        else delete globalThis.window;
        component.destroy();
    });
    component.copyRaw();
    await Promise.resolve();
    assert.deepEqual(JSON.parse(copied), source);
    assert.equal(component.copied, true);
});

test('bulk AI submits the exact selection once and includes force on reanalysis', async t => {
    const component = viewer(t);
    let resolve;
    const request = t.mock.method(globalThis, 'fetch', () => new Promise(done => { resolve = done; }));
    const analysis = component.analyseWithAi(true);
    await component.analyseWithAi(true);
    assert.equal(request.mock.callCount(), 1);
    const [url, options] = request.mock.calls[0].arguments;
    assert.equal(url, '/live-alerts/triage/ai-analysis');
    assert.deepEqual(JSON.parse(options.body), { alert_ids: [3, 9], force: true });
    resolve({ ok: true, json: async () => ({ success: true, analysis: { verdict: 'suspicious', correlations: [{ alert_ids: [3, 9] }] } }) });
    await analysis;
    assert.equal(component.aiLoading, false);
    assert.equal(component.aiResult.verdict, 'suspicious');
});

test('opening saved analysis avoids a provider call and a failed refresh preserves the previous result', async t => {
    const stored = { verdict: 'inconclusive', summary: 'Prior evidence assessment.' };
    const component = viewer(t, stored);
    const request = t.mock.method(globalThis, 'fetch', async () => ({ ok: false, json: async () => ({ error: 'Provider unavailable' }) }));
    component.openAi();
    assert.equal(component.mode, 'ai');
    assert.equal(request.mock.callCount(), 0);
    await component.analyseWithAi(true);
    assert.equal(component.aiError, 'Provider unavailable');
    assert.equal(component.aiLoading, false);
    assert.deepEqual(component.aiResult, stored);
});

test('a stale selection displays the specific validation message', async t => {
    const component = viewer(t);
    t.mock.method(globalThis, 'fetch', async () => ({ ok: false, json: async () => ({ message: 'Validation failed', errors: { alert_ids: ['Alert sudah tidak tersedia.'] } }) }));
    await component.analyseWithAi();
    assert.equal(component.aiError, 'Alert sudah tidak tersedia.');
});
