import type { JsonRecord, JsonValue } from './types.js';

const MAX_CONTEXT_BYTES = 8192;
const MAX_CONTEXT_DEPTH = 8;
const MAX_MESSAGE_BYTES = 512;
const MAX_STACK_BYTES = 4096;

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
  return trimmed(value, MAX_STACK_BYTES);
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
