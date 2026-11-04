import type { BeforeSend, BrowserEvent, CaptureOptions, EventTargetLike, InitOptions, Integration, JsonRecord, Level, Transport } from './types.js';
import { copyContext, trimmed, trimmedStack } from './sanitize.js';

const PRIORITY: Record<Level, number> = { debug: 0, info: 1, warning: 2, error: 3, critical: 4 };

/** Explicitly initialized SDK. Browser listeners do not collect any request/user metadata. */
export class FaultNestClient {
  private options: InitOptions | null = null;
  private readonly pending = new Set<Promise<boolean>>();
  private readonly scope = new Map<string, JsonRecord>();
  private readonly tags = new Map<string, string>();
  private readonly breadcrumbs: Array<{ message: string; data: JsonRecord }> = [];
  private target: EventTargetLike | null = null;
  private readonly onError = (event: { error?: unknown; message?: unknown }): void => {
    if (event.error instanceof Error) {
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
    let message = 'Unknown exception';
    let exceptionType = 'Error';
    let stack: string | undefined;
    if (error instanceof Error) {
      try {
        message = error.message;
        exceptionType = error.name || 'Error';
        stack = error.stack;
      } catch { /* A hostile Error subclass must not throw into the host. */ }
    } else if (typeof error === 'string') {
      message = error;
    }
    return this.capture({ kind: 'exception', level: options.level ?? 'error', source: options.source ?? 'browser',
      message: trimmed(message), exceptionType: trimmed(exceptionType, 255),
      ...(stack === undefined ? {} : { stack: trimmedStack(stack) }) }, options.context);
  }

  public captureMessage(message: string, level: Level = 'error', context?: JsonRecord): Promise<boolean> {
    return this.capture({ kind: 'message', source: 'browser', level, message: trimmed(message) }, context);
  }

  public setContext(key: string, value: JsonRecord): void {
    this.scope.set(this.validateKey(key), copyContext(value));
  }

  public setTag(key: string, value: string): void {
    this.tags.set(this.validateKey(key), trimmed(value, 255));
  }

  public addBreadcrumb(message: string, data: JsonRecord = {}): void {
    this.breadcrumbs.push({ message: trimmed(message), data: copyContext(data) });
    if (this.breadcrumbs.length > 20) this.breadcrumbs.shift();
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
        || PRIORITY[core.level] < PRIORITY[current.minLevel ?? 'warning']) return Promise.resolve(false);

    const operation = async (): Promise<boolean> => {
      try {
        const context = Object.create(null) as JsonRecord;
        for (const [key, value] of this.scope) context[key] = value;
        if (explicit !== undefined) Object.assign(context, copyContext(explicit));
        const event: BrowserEvent = { schema: 1, ...core };
        if (Object.keys(context).length) event.context = copyContext(context);
        if (this.tags.size) event.tags = Object.fromEntries(this.tags);
        if (this.breadcrumbs.length) event.breadcrumbs = this.breadcrumbs.map(crumb => ({ message: crumb.message, data: crumb.data }));
        const transformed = current.beforeSend ? await current.beforeSend(event) : event;
        if (transformed === null) return false;
        if (!transformed || transformed.schema !== 1 || typeof transformed.message !== 'string'
            || !(transformed.level in PRIORITY)) return false;
        // Only the caller can add explicit context. Revalidate transform results before transport.
        const final: BrowserEvent = {
          schema: 1, kind: core.kind, source: core.source, level: transformed.level,
          message: trimmed(transformed.message),
          ...((transformed.exceptionType ?? core.exceptionType) === undefined ? {}
            : { exceptionType: trimmed(transformed.exceptionType ?? core.exceptionType ?? '', 255) }),
          ...((transformed.stack ?? core.stack) === undefined ? {}
            : { stack: trimmedStack(transformed.stack ?? core.stack ?? '') }),
        };
        if (PRIORITY[final.level] < PRIORITY[current.minLevel ?? 'warning']) return false;
        if (transformed.context !== undefined) final.context = copyContext(transformed.context);
        if (transformed.tags !== undefined) {
          final.tags = Object.fromEntries(Object.entries(transformed.tags).map(([key, value]) => [this.validateKey(key), trimmed(value, 255)]));
        }
        if (transformed.breadcrumbs !== undefined) {
          final.breadcrumbs = transformed.breadcrumbs.slice(-20).map(crumb => ({
            message: trimmed(crumb.message), data: copyContext(crumb.data),
          }));
        }
        await current.transport(final);
        return true;
      } catch (error) {
        try { current.onInternalError?.(error); } catch { /* Never report monitoring failures recursively. */ }
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
