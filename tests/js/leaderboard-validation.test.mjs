import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

test('leaderboard shows validation feedback, preserves existing rankings, and can retry successfully', async () => {
    const blade = readFileSync(new URL('../../resources/views/leaderboard/index.blade.php', import.meta.url), 'utf8');
    const script = blade.slice(blade.indexOf('function leaderboard('), blade.lastIndexOf('</script>'));
    const requests = [];
    const responses = [
        { ok: false, status: 422, json: async () => ({ errors: { date_to: ['Rentang leaderboard maksimal 366 hari.'] } }) },
        { ok: true, status: 200, json: async () => ({ by_total: [{ user_id: 1, total: 2 }] }) },
    ];
    const context = {
        URLSearchParams,
        console: { error() {} },
        fetch: async (url, options) => { requests.push({ url, options }); return responses.shift(); },
    };
    runInNewContext(script, context);
    const component = context.leaderboard(1);
    const existing = { by_total: [{ user_id: 1, total: 1 }] };
    Object.assign(component, {
        period: 'custom', dateFrom: '2020-01-01', dateTo: '2026-11-15', data: existing, ready: true,
        destroyAllCharts() {}, renderCharts() {}, renderSparklines() {}, $nextTick: async () => {},
    });

    await component.fetchData();
    assert.equal(component.error, 'Rentang leaderboard maksimal 366 hari.');
    assert.equal(component.data, existing);
    assert.equal(component.ready, true);
    assert.equal(component.loading, false);
    assert.equal(requests[0].options.headers.Accept, 'application/json');

    component.dateFrom = '2026-11-01';
    await component.fetchData();
    assert.equal(component.error, '');
    assert.equal(component.data.by_total[0].total, 2);
    assert.equal(component.loading, false);
});
