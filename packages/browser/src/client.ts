import type { BeforeSend, BrowserEvent, CaptureOptions, EventTargetLike, InitOptions, Integration, JsonRecord, Level, Transport } from './types.js';
import { copyContext, fitWireLimits, trimmed, trimmedStack } from './sanitize.js';

const PRIORITY: Record<Level, number> = { debug: 0, info: 1, warning: 2, error: 3, critical: 4 };
const MAX_BREADCRUMBS = 20;
const MAX_IN_FLIGHT = 32;
const UNKNOWN_EXCEPTION = 'Unknown exception';

function isError(value: unknown): value is Error {
  try {
    return value instanceof Error || Object.prototype.toString.call(value) === '[object Error]';
  } catch {
    return false; // A revoked Proxy throws on any inspection.
  }
}

/** The receiver schema requires a non-blank message. */
function messageOr(value: unknown, fallback: string): string {
  const message = typeof value === 'string' ? trimmed(value) : '';
  return message.trim() === '' ? trimmed(fallback) : message;
}

/** Explicitly initialized SDK. Browser listeners do not collect any request/user metadata. */
export class FaultNestClient {
  private options: InitOptions | null = null;
  private readonly pending = new Set<Promise<boolean>>();
  private readonly scope = new Map<string, JsonRecord>();
  private readonly tags = new Map<string, string>();
  private readonly breadcrumbs: Array<{ message: string; data: JsonRecord }> = [];
  private target: EventTargetLike | null = null;
  private readonly onError = (event: { error?: unknown; message?: unknown }): void => {
    if (isError(event.error)) {
      void this.captureException(event.error);
    } else if (typeof event.message === 'string') {
      void this.captureMessage(event.message, 'error');
    }
  };
  private readonly onUnhandled = (event: { reason?: unknown }): void => {
    void this.captureException(event.reason);
  };

  /** Calling init again replaces listeners and configuration without double registration. */
  public init(options: InitOptions, target?: EventTargetLike): void {
    if (typeof options.transport !== 'function') throw new TypeError('FaultNest requires a transport');
    const integrations = options.integrations ?? [];
    if (integrations.some(value => value !== 'javascript' && value !== 'unhandledrejection')) {
      throw new TypeError('Unknown FaultNest browser integration');
    }
    if (options.minLevel !== undefined && !(options.minLevel in PRIORITY)) {
      throw new TypeError('Unknown FaultNest minimum level');
    }
    this.dispose();
    this.options = { ...options, integrations: [...new Set(integrations)] };
    if (options.enabled === false) return;
    const resolved = target ?? (typeof window !== 'undefined' ? window : null);
    if (!resolved) return;
    this.target = resolved;
    if (integrations.includes('javascript')) resolved.addEventListener('error', this.onError);
    if (integrations.includes('unhandledrejection')) resolved.addEventListener('unhandledrejection', this.onUnhandled);
  }

  public captureException(error: unknown, options: CaptureOptions = {}): Promise<boolean> {
    let message: unknown = typeof error === 'string' ? error : undefined;
    let exceptionType = 'Error';
    let stack: string | undefined;
    if (isError(error)) {
      // Each read is separate: a hostile Error subclass must not cost the other diagnostics or throw into the host.
      try { if (typeof error.name === 'string' && error.name) exceptionType = trimmed(error.name, 255); } catch { /* ignored */ }
      try { message = error.message; } catch { /* ignored */ }
      try { if (typeof error.stack === 'string') stack = trimmedStack(error.stack); } catch { /* ignored */ }
    }
    return this.capture({ kind: 'exception', level: options.level ?? 'error', source: options.source ?? 'browser',
      message: messageOr(message, isError(error) ? exceptionType : UNKNOWN_EXCEPTION), exceptionType,
      ...(stack === undefined ? {} : { stack }) }, options.context);
  }

  public captureMessage(message: string, level: Level = 'error', context?: JsonRecord): Promise<boolean> {
    return this.capture({ kind: 'message', source: 'browser', level, message: messageOr(message, 'Unknown message') }, context);
  }

  public setContext(key: string, value: JsonRecord): void {
    this.guarded(() => { this.scope.set(this.validateKey(key), copyContext(value)); });
  }

  public setTag(key: string, value: string): void {
    this.guarded(() => {
      if (typeof value !== 'string') throw new TypeError('FaultNest tag values must be strings');
      this.tags.set(this.validateKey(key), trimmed(value, 255));
    });
  }

  public addBreadcrumb(message: string, data: JsonRecord = {}): void {
    this.guarded(() => {
      this.breadcrumbs.push({ message: trimmed(String(message)), data: copyContext(data) });
      if (this.breadcrumbs.length > MAX_BREADCRUMBS) this.breadcrumbs.shift();
    });
  }

  public clearScope(): void {
    this.scope.clear();
    this.tags.clear();
    this.breadcrumbs.length = 0;
  }

  public dispose(): void {
    if (this.target) {
      this.target.removeEventListener('error', this.onError);
      this.target.removeEventListener('unhandledrejection', this.onUnhandled);
      this.target = null;
    }
  }

  public async flush(): Promise<void> {
    await Promise.allSettled([...this.pending]);
  }

  public async close(): Promise<void> {
    this.dispose();
    await this.flush();
    this.options = null;
  }

  /** Rejected values are never stored; monitoring must not throw into application code. */
  private guarded(action: () => void): void {
    try {
      action();
    } catch (error) {
      this.reportInternal(error, this.options);
    }
  }

  private reportInternal(error: unknown, options: InitOptions | null): void {
    try { options?.onInternalError?.(error); } catch { /* Never report monitoring failures recursively. */ }
  }

  private validateKey(key: string): string {
    if (!key || key.length > 120 || ['__proto__', 'constructor', 'prototype'].includes(key)) {
      throw new TypeError('Invalid FaultNest context key');
    }
    return key;
  }

  private capture(
    core: Pick<BrowserEvent, 'kind' | 'source' | 'level' | 'message'> & Pick<Partial<BrowserEvent>, 'exceptionType' | 'stack'>,
    explicit?: JsonRecord,
  ): Promise<boolean> {
    const current = this.options;
    if (!current || current.enabled === false || PRIORITY[core.level] === undefined
        || PRIORITY[core.level] < PRIORITY[current.minLevel ?? 'warning']
        || this.pending.size >= MAX_IN_FLIGHT) return Promise.resolve(false);

    const operation = async (): Promise<boolean> => {
      try {
        const context = Object.create(null) as JsonRecord;
        for (const [key, value] of this.scope) context[key] = value;
        // Invalid per-call context costs the context, never the error report itself.
        if (explicit !== undefined) {
          try { Object.assign(context, copyContext(explicit)); } catch (error) { this.reportInternal(error, current); }
        }
        const event: BrowserEvent = { schema: 1, ...core };
        if (Object.keys(context).length) {
          try { event.context = copyContext(context); } catch (error) { this.reportInternal(error, current); }
        }
        if (this.tags.size) event.tags = Object.fromEntries(this.tags);
        if (this.breadcrumbs.length) event.breadcrumbs = this.breadcrumbs.map(crumb => ({ message: crumb.message, data: crumb.data }));
        const transformed = current.beforeSend ? await current.beforeSend(event) : event;
        if (transformed === null) return false;
        if (!transformed || transformed.schema !== 1 || typeof transformed.message !== 'string'
            || !(transformed.level in PRIORITY)) return false;
        // Only the caller can add explicit context. Revalidate transform results before transport.
        const final: BrowserEvent = {
          schema: 1, kind: core.kind, source: core.source, level: transformed.level,
          message: messageOr(transformed.message, core.message),
          // Absent means removed: beforeSend is the host's privacy hook and must be able to drop these.
          ...(transformed.exceptionType === undefined ? {} : { exceptionType: trimmed(transformed.exceptionType, 255) }),
          ...(transformed.stack === undefined ? {} : { stack: trimmedStack(transformed.stack) }),
        };
        if (PRIORITY[final.level] < PRIORITY[current.minLevel ?? 'warning']) return false;
        if (transformed.context !== undefined) final.context = copyContext(transformed.context);
        if (transformed.tags !== undefined) {
          final.tags = Object.fromEntries(Object.entries(transformed.tags).map(([key, value]) => [this.validateKey(key), trimmed(value, 255)]));
        }
        if (transformed.breadcrumbs !== undefined) {
          final.breadcrumbs = transformed.breadcrumbs.slice(-MAX_BREADCRUMBS).map(crumb => ({
            message: trimmed(crumb.message), data: copyContext(crumb.data),
          }));
        }
        await current.transport(fitWireLimits(final));
        return true;
      } catch (error) {
        this.reportInternal(error, current);
        return false;
      }
    };
    const promise = operation();
    this.pending.add(promise);
    void promise.finally(() => { this.pending.delete(promise); });
    return promise;
  }
}

/** Convenience singleton. Instantiate FaultNestClient for independent applications/roots. */
export const FaultNest = new FaultNestClient();
