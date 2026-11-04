import assert from 'node:assert/strict';
import test from 'node:test';
import { FaultNestClient, createFetchTransport } from '../dist/index.js';
import { createReactRootHandlers, captureReactError } from '../dist/react.js';

class FakeTarget {
  listeners = new Map();
  addEventListener(name, handler) { this.listeners.set(name, [...(this.listeners.get(name) ?? []), handler]); }
  removeEventListener(name, handler) { this.listeners.set(name, (this.listeners.get(name) ?? []).filter(h => h !== handler)); }
  dispatch(name, event) { for (const handler of this.listeners.get(name) ?? []) handler(event); }
  count(name) { return this.listeners.get(name)?.length ?? 0; }
}

test('does not send anything before explicit initialization', async () => {
  const sdk = new FaultNestClient();
  assert.equal(await sdk.captureMessage('not configured'), false);
  assert.throws(() => sdk.init({}), /transport/);
});

test('captures intrinsic fields and only explicitly provided context', async () => {
  const events = [];
  const sdk = new FaultNestClient();
  sdk.init({ transport: async value => { events.push(value); }, minLevel: 'debug' });
  await sdk.captureException(new TypeError('failure'));
  assert.equal(events.length, 1);
  assert.equal(events[0].source, 'browser');
  assert.equal(events[0].exceptionType, 'TypeError');
  assert.equal(events[0].context, undefined);
  assert.equal(events[0].tags, undefined);
  assert.equal(events[0].url, undefined);
  sdk.setContext('custom', { user: { id: 17 } });
  sdk.setTag('feature', 'import');
  sdk.addBreadcrumb('clicked', { explicit: true });
  await sdk.captureMessage('failed import', 'warning', { other: { id: 'e42' } });
  assert.deepEqual(JSON.parse(JSON.stringify(events[1].context.custom)), { user: { id: 17 } });
  assert.equal(events[1].context.other.id, 'e42');
  assert.equal(events[1].tags.feature, 'import');
  assert.equal(events[1].breadcrumbs.length, 1);
  sdk.clearScope();
  await sdk.captureMessage('clean', 'warning');
  assert.equal(events[2].context, undefined);
  await sdk.close();
});

test('beforeSend can discard or change permitted fields; minimum level respected', async () => {
  const events = [];
  const sdk = new FaultNestClient();
  sdk.init({ transport: async evt => { events.push(evt); }, minLevel: 'warning', beforeSend: async evt => evt.message === 'ignore' ? null : ({ ...evt, message: 'edited', context: { added: true } }) });
  assert.equal(await sdk.captureMessage('debug', 'info'), false);
  assert.equal(await sdk.captureMessage('ignore', 'error'), false);
  assert.equal(await sdk.captureMessage('capture', 'error'), true);
  assert.equal(events.length, 1);
  assert.equal(events[0].message, 'edited');
  assert.equal(events[0].context.added, true);
});

test('init twice replaces global handlers, disposal removes them', async () => {
  const fake = new FakeTarget();
  const events = [];
  const sdk = new FaultNestClient();
  sdk.init({ transport: e => { events.push(e); }, integrations: ['javascript', 'unhandledrejection'] }, fake);
  sdk.init({ transport: e => { events.push(e); }, integrations: ['javascript', 'unhandledrejection', 'javascript'] }, fake);
  assert.equal(fake.count('error'), 1);
  assert.equal(fake.count('unhandledrejection'), 1);
  fake.dispatch('error', { error: new Error('boom') });
  fake.dispatch('unhandledrejection', { reason: 'broken promise' });
  await sdk.flush();
  assert.equal(events.length, 2);
  assert.equal(events[1].message, 'broken promise');
  sdk.dispose();
  assert.equal(fake.count('error'), 0);
  assert.equal(fake.count('unhandledrejection'), 0);
});

test('transport errors are isolated from host and optional observer is safe', async () => {
  let reported = 0;
  const sdk = new FaultNestClient();
  sdk.init({ transport: async () => { throw Error('offline'); }, onInternalError: () => { reported++; throw Error('observer'); } });
  assert.equal(await sdk.captureMessage('x'), false);
  assert.equal(reported, 1);
  await sdk.flush();
});

test('react integration is explicit and does not automatically collect component stack', async () => {
  const events = [];
  const sdk = new FaultNestClient();
  sdk.init({ transport: value => { events.push(value); } });
  const handlers = createReactRootHandlers(sdk);
  handlers.onCaughtError(new Error('caught'), { componentStack: 'at <Private>' });
  await sdk.flush();
  assert.equal(events[0].source, 'react');
  assert.equal(events[0].context, undefined);
  await captureReactError(new Error('explicit'), { componentStack: 'at <App>' }, sdk, true);
  assert.equal(events[1].context.react.componentStack, 'at <App>');
});

test('fetch transport requires explicitly configured endpoint and rejects HTTP remote URLs', async () => {
  assert.throws(() => createFetchTransport({ endpoint: '' }), /required/);
  assert.throws(() => createFetchTransport({ endpoint: 'http://remote.example/events' }), /HTTPS/);
  const requests = [];
  const transport = createFetchTransport({
    endpoint: 'http://localhost:8342/events',
    fetcher: async (url, args) => { requests.push({ url, args }); return { ok: true }; },
  });
  await transport({ schema: 1, source: 'browser', kind: 'message', message: 'test', level: 'error' });
  assert.equal(requests[0].url, 'http://localhost:8342/events');
  assert.equal(requests[0].args.credentials, 'same-origin');
  assert.equal(requests[0].args.redirect, 'error');
});

test('stack retains parseable lines but drops URL query, fragment and private path', async () => {
  const events = [];
  const sdk = new FaultNestClient();
  sdk.init({ transport: value => { events.push(value); } });
  const failure = new Error('test');
  failure.stack = 'Error: test\n    at render (https://example.com/orders/123/assets/app.js?token=secret#fragment:43:9)';
  assert.equal(await sdk.captureException(failure), true);
  assert.match(events[0].stack, /\n/);
  assert.match(events[0].stack, /\/app\.js:43:9/);
  assert.doesNotMatch(events[0].stack, /token=secret|orders\/123|example\.com/);
});
