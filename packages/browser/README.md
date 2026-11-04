# FaultNest browser SDK (experimental)

A small TypeScript SDK that reports **only intrinsic error diagnostics and explicitly supplied application context** to your chosen transport. No user profiles, request bodies, IP addresses, current URL, cookies, session, performance metrics, or telemetry are gathered.

The SDK sends **nothing** until the host explicitly calls `init({ transport })`. It creates no server or routes. `@jbumaniss/faultnest-browser` is a planned npm package identifier; this workspace is currently private and unpublished.

```ts
import { FaultNestClient, createFetchTransport } from '@jbumaniss/faultnest-browser';

const monitor = new FaultNestClient();
monitor.init({
  transport: createFetchTransport({ endpoint: '/my-app/errors', headers: { 'X-CSRF-TOKEN': myCsrfToken } }),
  integrations: ['javascript', 'unhandledrejection'],
  minLevel: 'warning',
  beforeSend: event => event.message.includes('ResizeObserver') ? null : event,
});

monitor.setContext('order', { id: 42 }); // Only if your application chooses to add this.
await monitor.captureMessage('Import failed', 'error');
await monitor.flush();
await monitor.close();
```

`setContext`, `setTag`, and `addBreadcrumb` are only populated when application code calls them. They never throw: values that are not plain JSON are discarded and reported to the optional `onInternalError` callback. Events shed breadcrumbs, then context, then tags to fit the receiver's default limits, so the error itself is still delivered. At most 32 deliveries run at once; further events are dropped until one settles. Default React root handlers can be wired explicitly:

```ts
import { createReactRootHandlers } from '@jbumaniss/faultnest-browser/react';
import { createRoot } from 'react-dom/client';

const root = createRoot(container, createReactRootHandlers(monitor));
```

React's component stack is **not captured by default**. `captureReactError(error, info, monitor, true)` lets an application explicitly include it. For React Error Boundaries, call `captureReactError(error, info, monitor)` from `componentDidCatch`. Root `onCaughtError` also fires for boundary errors, so use one of the two for caught errors or expect two events. URLs in an included component stack are reduced to their file name.

The transport uses `POST`, JSON, same-origin credentials, forbids redirects, aborts after `timeoutMs` (10 seconds by default), and requires HTTPS except on localhost. Host applications supply the route, CSRF/auth policy, payload validation, and rate limiting. A public endpoint is not authenticated merely because it has a project name.

### Development

```sh
npm install
npm test
npm run typecheck
```

No npm publication, GitHub workflow, or browser tracking occurs automatically.
