import type { BrowserEvent, Transport } from './types.js';

export interface FetchTransportOptions {
  /** Explicit application-owned endpoint. HTTPS or same-origin; no default destination. */
  endpoint: string;
  headers?: Readonly<Record<string, string>>;
  fetcher?: typeof fetch;
  allowCrossOrigin?: boolean;
}

/** Creates an opt-in HTTP transport. The Laravel route, CSRF/auth, and rate limits belong to the host. */
export function createFetchTransport(options: FetchTransportOptions): Transport {
  if (!options.endpoint || typeof options.endpoint !== 'string') throw new TypeError('FaultNest endpoint is required');
  const locationHref = typeof location !== 'undefined' ? location.href : undefined;
  const endpoint = new URL(options.endpoint, locationHref);
  if (locationHref && endpoint.origin !== new URL(locationHref).origin && !options.allowCrossOrigin) {
    throw new TypeError('FaultNest cross-origin endpoint requires explicit opt-in');
  }
  if (!['https:', 'http:'].includes(endpoint.protocol)) throw new TypeError('Invalid FaultNest endpoint protocol');
  if (endpoint.protocol !== 'https:' && endpoint.hostname !== 'localhost' && endpoint.hostname !== '127.0.0.1') {
    throw new TypeError('FaultNest transport requires HTTPS for non-local endpoints');
  }
  const send = options.fetcher ?? globalThis.fetch;
  if (typeof send !== 'function') throw new TypeError('Fetch is not available');
  return async (event: BrowserEvent) => {
    const response = await send(endpoint.href, {
      method: 'POST',
      headers: { 'content-type': 'application/json', ...options.headers },
      body: JSON.stringify(event),
      credentials: 'same-origin',
      redirect: 'error',
      keepalive: false,
    });
    if (!response.ok) throw new Error(`FaultNest endpoint rejected event (${response.status})`);
  };
}
