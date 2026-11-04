// Drives a real Chromium against the real Laravel host. Usage: node run.mjs http://127.0.0.1:PORT
import { chromium, firefox, webkit } from 'playwright';
import assert from 'node:assert/strict';

const base = process.argv[2];
const engine = process.env.E2E_BROWSER ?? 'chromium';
const browser = await ({ chromium, firefox, webkit })[engine].launch();
const context = await browser.newContext();
await context.addCookies([{ name: 'private_session', value: 'cookie-must-not-travel-in-body', url: base }]);
const page = await context.newPage();
const posts = [];
const foreign = [];
page.on('request', r => {
  if (!r.url().startsWith(base)) foreign.push(r.url());
  if (r.method() === 'POST') posts.push({ url: r.url(), body: r.postData(), redirect: r.redirectedFrom() !== null });
});
const responses = [];
page.on('response', r => { if (r.request().method() === 'POST') responses.push(r.status()); });
page.on('pageerror', () => {});

const results = [];
async function step(name, fn) {
  try { await fn(); results.push(['PASS', name]); console.log('PASS', name); }
  catch (e) { results.push(['FAIL', name]); console.log('FAIL', name, '\n   ', String(e.message).split('\n').slice(0, 6).join('\n    ')); }
}
const settle = () => page.evaluate(() => window.monitor.flush());
const state = async () => (await (await context.request.get(`${base}/e2e/state`)).json());
const sentCount = () => posts.length;

await page.goto(`${base}/e2e/page?token=url-query-secret#frag`);
await page.waitForFunction(() => window.e2eReady === true);
await step('page and React root load with no monitor traffic', async () => assert.equal(posts.length, 0));

await step('global JavaScript error is captured once after repeated init', async () => {
  const n = sentCount();
  await page.evaluate(() => { setTimeout(() => { throw new TypeError('e2e global error'); }, 0); });
  await page.waitForTimeout(200); await settle();
  assert.equal(sentCount(), n + 1);
  const body = JSON.parse(posts.at(-1).body);
  assert.equal(body.exceptionType, 'TypeError');
  assert.equal(body.message, 'e2e global error');
  assert.ok(body.stack.includes('/e2e') === false || !body.stack.includes('url-query-secret'));
});

await step('unhandled Promise rejection is captured', async () => {
  const n = sentCount();
  await page.evaluate(() => { void Promise.reject(new RangeError('e2e rejection')); });
  await page.waitForTimeout(200); await settle();
  assert.equal(sentCount(), n + 1);
  assert.equal(JSON.parse(posts.at(-1).body).exceptionType, 'RangeError');
});

await step('React ErrorBoundary and root onCaughtError report with react source and optional component stack', async () => {
  const n = sentCount();
  await page.evaluate(() => window.e2e.setCaught(true));
  await page.waitForSelector('#fallback');
  await settle();
  const new_ = posts.slice(n).map(p => JSON.parse(p.body));
  assert.ok(new_.length >= 1, 'no event');
  assert.ok(new_.every(e => e.source === 'react' && e.message === 'react boundary failure'));
  assert.ok(new_.some(e => e.context?.react?.componentStack), 'explicit component stack missing');
  console.log('   note: events for one boundary error =', new_.length, '(boundary helper + root onCaughtError)');
});

await step('manual capture with explicit context, tags, breadcrumbs reaches host storage', async () => {
  await page.evaluate(async () => {
    window.monitor.setContext('order', { id: 42 });
    window.monitor.setTag('feature', 'checkout');
    window.monitor.addBreadcrumb('clicked pay', { step: 3 });
    await window.monitor.captureMessage('e2e manual message', 'warning', { extra: { api_key: 'must-redact', ok: true } });
  });
  const s = await state();
  const e = s.events.find(x => x.message === 'e2e manual message');
  assert.ok(e, 'not stored');
  assert.equal(e.source, 'browser');
  assert.equal(e.level, 'warning');
  assert.equal(e.context.order.id, 42);
  assert.equal(e.context.extra.api_key, '[REDACTED]');
  assert.equal(e.tags.feature, 'checkout');
  assert.equal(e.breadcrumbs[0].data.step, 3);
});

await step('beforeSend cancellation sends nothing, transformation is stored', async () => {
  const n = sentCount();
  assert.equal(await page.evaluate(() => window.monitor.captureMessage('e2e-cancel', 'error')), false);
  assert.equal(sentCount(), n);
  assert.equal(await page.evaluate(() => window.monitor.captureMessage('e2e-transform', 'error')), true);
  const s = await state();
  assert.ok(s.events.find(x => x.message === 'transformed by host' && x.tags.transformed === 'yes'));
});

await step('critical is capped by server and error without message is accepted end to end', async () => {
  const before = responses.length;
  await page.evaluate(async () => {
    await window.monitor.captureMessage('e2e critical attempt', 'critical');
    await window.monitor.captureException(new Error(''));
  });
  assert.deepEqual(responses.slice(before), [202, 202]);
  const s = await state();
  assert.equal(s.events.find(x => x.message === 'e2e critical attempt').level, 'error');
});

await step('oversized scope still delivers diagnostics, accepted by the real receiver', async () => {
  const before = responses.length;
  await page.evaluate(async () => {
    window.monitor.clearScope();
    for (let i = 0; i < 40; i++) window.monitor.setTag('t' + i, 'v');
    for (let i = 0; i < 20; i++) window.monitor.addBreadcrumb('x'.repeat(400), { d: 'y'.repeat(3000) });
    await window.monitor.captureException(new Error('e2e oversized scope'), { context: { c: 'z'.repeat(7000) } });
    window.monitor.clearScope();
  });
  assert.deepEqual(responses.slice(before), [202]);
  assert.ok((await state()).events.find(x => x.message === 'e2e oversized scope'));
});

await step('network failure is isolated and the next event recovers', async () => {
  await page.route('**/e2e/errors', r => r.abort(), { times: 1 });
  const first = await page.evaluate(() => window.monitor.captureMessage('e2e offline', 'error'));
  const second = await page.evaluate(() => window.monitor.captureMessage('e2e back online', 'error'));
  assert.equal(first, false); assert.equal(second, true);
  assert.ok((await page.evaluate(() => window.internal.length)) >= 1);
});

// Playwright cannot fulfill a redirect on WebKit, so this step does not run there.
if (engine !== 'webkit') await step('redirecting endpoint is refused', async () => {
  await page.route('**/e2e/errors', r => r.fulfill({ status: 302, headers: { location: 'https://example.test/steal' } }), { times: 1 });
  assert.equal(await page.evaluate(() => window.monitor.captureMessage('e2e redirect', 'error')), false);
  await page.unroute('**/e2e/errors');
});

await step('uncaught React render error reaches onUncaughtError', async () => {
  const n = sentCount();
  await page.evaluate(() => window.e2e.setUncaught(true));
  await page.waitForTimeout(300); await settle();
  const new_ = posts.slice(n).map(p => JSON.parse(p.body));
  assert.ok(new_.some(e => e.source === 'react' && e.message === 'react uncaught root failure'));
});

await step('no foreign traffic and no automatic URL, cookie, UA or user data on the wire', async () => {
  assert.deepEqual(foreign, []);
  for (const p of posts) {
    assert.ok(p.url.startsWith(`${base}/e2e/errors`));
    for (const leak of ['cookie-must-not-travel', 'url-query-secret', 'Mozilla', 'userAgent', '"url"', '"user"', '"ip"', '127.0.0.1']) {
      assert.equal(p.body.includes(leak), false, `${leak} in ${p.body.slice(0, 200)}`);
    }
    const keys = Object.keys(JSON.parse(p.body));
    assert.ok(keys.every(k => ['schema', 'kind', 'source', 'level', 'message', 'exceptionType', 'stack', 'context', 'tags', 'breadcrumbs'].includes(k)), keys.join());
  }
});

await step('stored browser frames contain only basenames, never URLs', async () => {
  const s = await state();
  const browserEvents = s.events.filter(x => x.source === 'browser' || x.source === 'react');
  assert.ok(browserEvents.length >= 5);
  assert.equal(JSON.stringify(browserEvents).includes('url-query-secret'), false);
  assert.equal(/https?:\/\//.test(JSON.stringify(browserEvents.map(e => e.frames))), false);
  assert.ok(browserEvents.some(e => e.frames.length > 0 && e.file === 'bundle.js'), 'expected bundle.js frames: ' + JSON.stringify(browserEvents[0]).slice(0, 300));
});

await step('browser events are stored but queue no alerts with the default notification sources', async () => {
  const s = await state();
  assert.ok(s.events.some(e => e.source === 'browser'));
  assert.equal(s.queued, 0);
});

await step('dispose removes listeners and close stops capture', async () => {
  const n = sentCount();
  await page.evaluate(() => { window.monitor.dispose(); setTimeout(() => { throw new Error('after dispose'); }, 0); });
  await page.waitForTimeout(150);
  assert.equal(sentCount(), n);
  assert.equal(await page.evaluate(() => window.monitor.captureMessage('manual after dispose', 'error')), true);
  await page.evaluate(() => window.monitor.close());
  assert.equal(await page.evaluate(() => window.monitor.captureMessage('after close', 'error')), false);
});

await browser.close();
const failed = results.filter(r => r[0] === 'FAIL').length;
console.log(`\nBROWSER E2E (${engine}): ${results.length - failed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
