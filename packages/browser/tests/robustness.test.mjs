import assert from 'node:assert/strict';
import test from 'node:test';
import vm from 'node:vm';
import { FaultNestClient, createFetchTransport } from '../dist/index.js';
import { captureReactError } from '../dist/react.js';

function setup(extra = {}) {
  const events = [];
  const internal = [];
  const sdk = new FaultNestClient();
  sdk.init({ transport: async event => { events.push(JSON.parse(JSON.stringify(event))); }, onInternalError: e => internal.push(e), minLevel: 'debug', ...extra });
  return { sdk, events, internal };
}

test('errors without a message still satisfy the receiver schema', async () => {
  const { sdk, events } = setup();
  assert.equal(await sdk.captureException(new Error('')), true);
  assert.equal(await sdk.captureException(new TypeError('   ')), true);
  assert.equal(await sdk.captureMessage(''), true);
  for (const event of events) assert.notEqual(event.message.trim(), '');
  assert.equal(events[0].message, 'Error');
  assert.equal(events[1].message, 'TypeError');
});

test('beforeSend can remove stack and exception type, and empty edits stay valid', async () => {
  const { sdk, events } = setup({
    beforeSend: ({ stack, exceptionType, ...rest }) => ({ ...rest, message: rest.message === 'blank' ? '' : rest.message }),
  });
  await sdk.captureException(new Error('private stack'));
  await sdk.captureException(new Error('blank'));
  assert.equal(events[0].stack, undefined, 'A privacy hook must be able to drop the stack.');
  assert.equal(events[0].exceptionType, undefined);
  assert.notEqual(events[1].message.trim(), '');
});

test('invalid scope values never throw into the host and are reported to the observer', async () => {
  const { sdk, events, internal } = setup();
  const cyclic = {}; cyclic.self = cyclic;
  let getterRan = false;
  const getter = {}; Object.defineProperty(getter, 'secret', { enumerable: true, get() { getterRan = true; return 'x'; } });
  assert.doesNotThrow(() => sdk.setContext('cyclic', cyclic));
  assert.doesNotThrow(() => sdk.setContext('getter', getter));
  assert.doesNotThrow(() => sdk.setContext('__proto__', { a: 1 }));
  assert.doesNotThrow(() => sdk.addBreadcrumb('date', { value: new Date() }));
  assert.doesNotThrow(() => sdk.setTag('', 'x'));
  assert.doesNotThrow(() => sdk.setTag('number', 5));
  assert.equal(internal.length, 6);
  assert.equal(getterRan, false);
  await sdk.captureMessage('after');
  assert.equal(events[0].context, undefined);
  assert.equal(events[0].tags, undefined);
  assert.equal(events[0].breadcrumbs, undefined);
});

test('hostile or malformed errors never throw and always produce a promise', async () => {
  const { sdk, events } = setup();
  const numericName = new Error('x'); numericName.name = 5;
  const objectMessage = new Error('x'); objectMessage.message = {};
  const numericStack = new Error('x'); numericStack.stack = 7;
  const throwing = new Error('x'); Object.defineProperty(throwing, 'message', { get() { throw new Error('hostile'); } });
  const { proxy, revoke } = Proxy.revocable({}, {}); revoke();
  for (const value of [numericName, objectMessage, numericStack, throwing, proxy, null, undefined, 42, Symbol('s'), {}, () => 1]) {
    assert.equal(await sdk.captureException(value), true);
  }
  assert.equal(await sdk.captureMessage(undefined), true);
  assert.equal(await sdk.captureMessage(Symbol('s')), true);
  assert.equal(await captureReactError(proxy, undefined, sdk), true);
  assert.equal(events.length, 14);
  for (const event of events) {
    assert.equal(typeof event.message, 'string');
    assert.notEqual(event.message.trim(), '');
  }
});

test('errors from another realm such as an iframe keep their diagnostics', async () => {
  const { sdk, events } = setup();
  await sdk.captureException(vm.runInNewContext('new RangeError("from iframe")'));
  assert.equal(events[0].message, 'from iframe');
  assert.equal(events[0].exceptionType, 'RangeError');
});

test('events always fit the receiver wire limits and keep their diagnostics', async () => {
  const { sdk, events, internal } = setup();
  for (let i = 0; i < 40; i++) sdk.setTag(`tag${i}`, 'v');
  for (let i = 0; i < 20; i++) sdk.addBreadcrumb('b'.repeat(400), { data: 'd'.repeat(3000) });
  await sdk.captureException(new Error('bulky'), { context: { a: 'x'.repeat(5000) } });
  const event = events[0];
  assert.equal(event.message, 'bulky');
  assert.ok(Object.keys(event.tags ?? {}).length <= 32);
  assert.ok((event.breadcrumbs ?? []).length <= 20);
  const explicit = JSON.stringify({ context: event.context ?? [], tags: event.tags ?? [], breadcrumbs: event.breadcrumbs ?? [] });
  assert.ok(Buffer.byteLength(explicit) <= 8192, `explicit data ${Buffer.byteLength(explicit)} bytes`);
  assert.ok(Buffer.byteLength(JSON.stringify(event)) <= 16384);

  // Oversized or invalid per-call context costs the context, not the error report.
  const before = internal.length;
  assert.equal(await sdk.captureException(new Error('big ctx'), { context: { a: 'x'.repeat(9000) } }), true);
  assert.equal(await sdk.captureMessage('bad ctx', 'error', { date: new Date() }), true);
  assert.equal(events[1].message, 'big ctx');
  assert.equal(events[2].message, 'bad ctx');
  assert.equal(internal.length, before + 2);
});

test('transport failure is isolated and a later event recovers', async () => {
  let online = false;
  const sent = [];
  const failures = [];
  const sdk = new FaultNestClient();
  sdk.init({ transport: async event => { if (!online) throw new Error('offline'); sent.push(event); }, onInternalError: e => failures.push(e) });
  assert.equal(await sdk.captureMessage('lost'), false);
  online = true;
  assert.equal(await sdk.captureMessage('delivered'), true);
  assert.deepEqual(sent.map(e => e.message), ['delivered']);
  assert.equal(failures.length, 1);
});

test('synchronous transport or beforeSend throw is contained', async () => {
  const sdk = new FaultNestClient();
  sdk.init({ transport: () => { throw new Error('sync'); } });
  assert.equal(await sdk.captureMessage('x'), false);
  sdk.init({ transport: () => {}, beforeSend: () => { throw new Error('hook'); } });
  assert.equal(await sdk.captureMessage('x'), false);
  sdk.init({ transport: () => {}, beforeSend: () => 'not an event' });
  assert.equal(await sdk.captureMessage('x'), false);
});

test('flush waits for in-flight delivery and close stops later capture', async () => {
  const sent = [];
  let release;
  const gate = new Promise(resolve => { release = resolve; });
  const sdk = new FaultNestClient();
  sdk.init({ transport: async event => { await gate; sent.push(event.message); } });
  void sdk.captureMessage('slow');
  let flushed = false;
  const flushing = sdk.flush().then(() => { flushed = true; });
  await new Promise(resolve => setTimeout(resolve, 5));
  assert.equal(flushed, false);
  release();
  await flushing;
  assert.deepEqual(sent, ['slow']);
  await sdk.close();
  assert.equal(await sdk.captureMessage('after close'), false);
  assert.equal(sent.length, 1);
});

test('dispose removes listeners but keeps the manual API; disabled init sends nothing', async () => {
  const handlers = new Map();
  const target = { addEventListener: (n, h) => handlers.set(n, h), removeEventListener: n => handlers.delete(n) };
  const events = [];
  const sdk = new FaultNestClient();
  sdk.init({ transport: e => { events.push(e); }, integrations: ['javascript'] }, target);
  assert.equal(handlers.size, 1);
  sdk.dispose();
  assert.equal(handlers.size, 0);
  assert.equal(await sdk.captureMessage('manual'), true);

  sdk.init({ transport: e => { events.push(e); }, enabled: false, integrations: ['javascript'] }, target);
  assert.equal(handlers.size, 0);
  assert.equal(await sdk.captureMessage('off'), false);
  assert.equal(events.length, 1);
});

test('repeated init switches transport without leaking the previous one', async () => {
  const a = []; const b = [];
  const sdk = new FaultNestClient();
  sdk.init({ transport: e => { a.push(e); } });
  await sdk.captureMessage('one');
  sdk.init({ transport: e => { b.push(e); } });
  await sdk.captureMessage('two');
  assert.deepEqual([a.length, b.length], [1, 1]);
});

test('global error listener handles events without an Error and an error thrown during capture', async () => {
  const handlers = new Map();
  const target = { addEventListener: (n, h) => handlers.set(n, h), removeEventListener: n => handlers.delete(n) };
  const { sdk, events } = setup();
  sdk.init({ transport: async e => { events.push(e); }, integrations: ['javascript', 'unhandledrejection'] }, target);
  handlers.get('error')({ message: 'Script error.' });
  handlers.get('error')({});
  handlers.get('unhandledrejection')({ reason: { custom: 'object is never serialized' } });
  await sdk.flush();
  assert.deepEqual(events.map(e => e.message), ['Script error.', 'Unknown exception']);
  assert.equal(JSON.stringify(events).includes('never serialized'), false);
});

test('an error storm is bounded instead of opening unlimited requests', async () => {
  let release;
  const gate = new Promise(resolve => { release = resolve; });
  let started = 0;
  const sdk = new FaultNestClient();
  sdk.init({ transport: async () => { started++; await gate; } });
  const results = [];
  for (let i = 0; i < 500; i++) results.push(sdk.captureMessage('storm'));
  assert.ok(started <= 32, `started ${started} concurrent deliveries`);
  release();
  await Promise.all(results);
});

test('message size is bounded in UTF-8 bytes without splitting characters', async () => {
  const { sdk, events } = setup();
  await sdk.captureMessage('🎉'.repeat(1000));
  assert.ok(Buffer.byteLength(events[0].message) <= 512);
  assert.doesNotMatch(events[0].message, /�/);
  const stacked = new Error('s'); stacked.stack = 'at x (https://a.test/a.js:1:1)\n'.repeat(1000);
  await sdk.captureException(stacked);
  assert.ok(Buffer.byteLength(events[1].stack) <= 4096);
});

test('stack URLs of any scheme lose origin, path and query', async () => {
  const { sdk, events } = setup();
  const failure = new Error('t');
  failure.stack = [
    'at a (chrome-extension://abcdef/private/ext.js?k=1:1:1)',
    'at b (webpack://my-private-app/src/secret/view.js:2:2)',
    'at c (capacitor://localhost/private/c.js:3:3)',
    'd@https://user:pass@a.test/private/d.js?token=x:4:4',
  ].join('\n');
  await sdk.captureException(failure);
  for (const leak of ['abcdef', 'private', 'my-private-app', 'secret', 'token', 'pass@', 'k=1']) {
    assert.equal(events[0].stack.includes(leak), false, leak);
  }
  assert.match(events[0].stack, /\/ext\.js:1:1/);
  assert.match(events[0].stack, /\/d\.js:4:4/);
});

test('fetch transport sends minimal JSON, rejects non-2xx and redirects are refused', async () => {
  const seen = [];
  const transport = createFetchTransport({
    endpoint: 'http://127.0.0.1:9/events', headers: { 'X-CSRF-TOKEN': 'host-value' },
    fetcher: async (url, args) => { seen.push(args); return { ok: false, status: 429 }; },
  });
  await assert.rejects(transport({ schema: 1, kind: 'message', source: 'browser', level: 'error', message: 'm' }), /429/);
  assert.equal(seen[0].method, 'POST');
  assert.equal(seen[0].headers['X-CSRF-TOKEN'], 'host-value');
  assert.equal(seen[0].redirect, 'error');
  assert.equal(seen[0].credentials, 'same-origin');
  assert.throws(() => createFetchTransport({ endpoint: 'ftp://localhost/x' }), /protocol/);
});

test('fetch transport times out so flush and close cannot hang forever', async () => {
  const transport = createFetchTransport({
    endpoint: 'http://127.0.0.1:9/events', timeoutMs: 20,
    fetcher: (url, args) => new Promise((_, reject) => { args.signal.addEventListener('abort', () => reject(args.signal.reason)); }),
  });
  const sdk = new FaultNestClient();
  let failure;
  sdk.init({ transport, onInternalError: e => { failure = e; } });
  assert.equal(await sdk.captureMessage('hang'), false);
  assert.ok(failure);
  await sdk.close();
});

test('an opted-in React component stack has its URLs reduced like the error stack', async () => {
  const { sdk, events } = setup();
  await captureReactError(new Error('x'), { componentStack: '\n    at Bomb (https://app.test/private/app.js?token=secret:1:2)' }, sdk, true);
  const stack = events[0].context.react.componentStack;
  assert.match(stack, /\/app\.js:1:2/);
  for (const leak of ['app.test', 'private', 'token']) assert.equal(stack.includes(leak), false, leak);
});
