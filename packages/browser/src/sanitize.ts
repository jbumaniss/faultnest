import type { BrowserEvent, JsonRecord, JsonValue } from './types.js';

const MAX_CONTEXT_BYTES = 8192;
const MAX_CONTEXT_DEPTH = 8;
const MAX_MESSAGE_BYTES = 512;
const MAX_STACK_BYTES = 4096;
// Receiver defaults: 32 tags and 8192 bytes for explicit data including its JSON wrapper.
const MAX_TAGS = 32;
const MAX_EXPLICIT_BYTES = 8000;

export function trimmed(value: string, maximum = MAX_MESSAGE_BYTES): string {
  const normalized = value.replace(/[\r\n\0]+/g, ' ');
  // Keep UTF-8 size bounded instead of cutting a multibyte sequence in half.
  const encoder = new TextEncoder();
  if (encoder.encode(normalized).length <= maximum) return normalized;
  let result = '';
  for (const char of normalized) {
    if (encoder.encode(result + char).length > maximum) break;
    result += char;
  }
  return result;
}

export function trimmedStack(value: string): string {
  // Preserve line breaks for stack parsing, but never transmit full browser URLs.
  const safe = value.replace(/\0/g, '').replace(/[a-z][a-z0-9+.-]*:\/\/[^\s)]+/gi, match => {
    const suffix = match.match(/:\d+(?::\d+)?$/)?.[0] ?? '';
    const address = suffix ? match.slice(0, -suffix.length) : match;
    try {
      const parsed = new URL(address);
      const basename = parsed.pathname.split('/').filter(Boolean).at(-1) ?? 'unknown';
      return `/${basename}${suffix}`;
    } catch {
      return '/unknown';
    }
  });
  const encoder = new TextEncoder();
  if (encoder.encode(safe).length <= MAX_STACK_BYTES) return safe;
  let result = '';
  for (const char of safe) {
    if (encoder.encode(result + char).length > MAX_STACK_BYTES) break;
    result += char;
  }
  return result;
}

/** Sheds optional explicit data, oldest first, so the receiver never rejects the diagnostics with it. */
export function fitWireLimits(event: BrowserEvent): BrowserEvent {
  const fitted: BrowserEvent = { ...event };
  if (fitted.tags !== undefined) fitted.tags = Object.fromEntries(Object.entries(fitted.tags).slice(0, MAX_TAGS));
  const size = (): number => new TextEncoder().encode(JSON.stringify({
    context: fitted.context ?? [], tags: fitted.tags ?? [], breadcrumbs: fitted.breadcrumbs ?? [],
  })).length;
  let breadcrumbs = fitted.breadcrumbs;
  while (breadcrumbs?.length && size() > MAX_EXPLICIT_BYTES) {
    breadcrumbs = breadcrumbs.slice(1);
    fitted.breadcrumbs = breadcrumbs;
  }
  if (size() > MAX_EXPLICIT_BYTES) delete fitted.context;
  if (size() > MAX_EXPLICIT_BYTES) delete fitted.tags;
  return fitted;
}

/** Copy only explicit JSON primitives/plain data properties; never invoke getters or toJSON. */
export function copyContext(value: unknown): JsonRecord {
  if (value === undefined) return {};
  const copied = copyValue(value, 0);
  if (copied === null || Array.isArray(copied) || typeof copied !== 'object') {
    throw new TypeError('FaultNest context must be a plain object');
  }
  if (new TextEncoder().encode(JSON.stringify(copied)).length > MAX_CONTEXT_BYTES) {
    throw new RangeError('FaultNest context is too large');
  }
  return copied as JsonRecord;
}

function copyValue(value: unknown, depth: number, seen = new WeakSet<object>()): JsonValue {
  if (depth > MAX_CONTEXT_DEPTH) throw new RangeError('FaultNest context nesting is too deep');
  if (value === null || typeof value === 'string' || typeof value === 'boolean') return value;
  if (typeof value === 'number' && Number.isFinite(value)) return value;
  if (typeof value !== 'object') throw new TypeError('FaultNest context accepts only JSON-compatible data');
  if (seen.has(value)) throw new TypeError('FaultNest context cannot contain cycles');
  const isArray = Array.isArray(value);
  if (!isArray && Object.getPrototypeOf(value) !== Object.prototype && Object.getPrototypeOf(value) !== null) {
    throw new TypeError('FaultNest context must use plain objects');
  }
  seen.add(value);
  const target: JsonValue[] | JsonRecord = isArray ? [] : Object.create(null) as JsonRecord;
  const descriptors = Object.getOwnPropertyDescriptors(value);
  if (Object.keys(descriptors).length > 512) throw new RangeError('Too many FaultNest context fields');
  for (const [key, descriptor] of Object.entries(descriptors)) {
    if (key === 'length' && isArray) continue;
    if (!Object.hasOwn(descriptor, 'value')) throw new TypeError('FaultNest context getters are not supported');
    if (key === '__proto__' || key === 'constructor' || key === 'prototype') {
      throw new TypeError('Unsafe FaultNest context key');
    }
    if (key.length > 120) throw new RangeError('FaultNest context key is too long');
    const copied = copyValue(descriptor.value, depth + 1, seen);
    if (isArray) {
      if (!/^(0|[1-9]\d*)$/.test(key)) throw new TypeError('FaultNest array has non-index keys');
      if (Number(key) > 1000) throw new RangeError('FaultNest array index is too large');
      (target as JsonValue[])[Number(key)] = copied;
    } else {
      (target as JsonRecord)[key] = copied;
    }
  }
  seen.delete(value);
  return target;
}
