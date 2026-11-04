/** Fields intrinsic to a browser error. No cookies, URL, user, or request data are added. */
export type Level = 'debug' | 'info' | 'warning' | 'error' | 'critical';
export type JsonValue = null | boolean | number | string | JsonValue[] | { [key: string]: JsonValue };
export type JsonRecord = Record<string, JsonValue>;
export type Integration = 'javascript' | 'unhandledrejection';
export type EventSource = 'browser' | 'react';

export interface BrowserEvent {
  schema: 1;
  kind: 'exception' | 'message';
  source: EventSource;
  level: Level;
  message: string;
  exceptionType?: string;
  stack?: string;
  context?: JsonRecord;
  tags?: Record<string, string>;
  breadcrumbs?: ReadonlyArray<{ message: string; data: JsonRecord }>;
}

export type BeforeSend = (event: BrowserEvent) => BrowserEvent | null | Promise<BrowserEvent | null>;
export type Transport = (event: BrowserEvent) => void | Promise<void>;

export interface InitOptions {
  /** An explicit transport is required for an enabled SDK; no implicit network destination. */
  transport: Transport;
  enabled?: boolean;
  integrations?: ReadonlyArray<Integration>;
  minLevel?: Level;
  beforeSend?: BeforeSend;
  /** Optional explicit diagnostic callback, never called recursively. */
  onInternalError?: (error: unknown) => void;
}

export interface CaptureOptions {
  level?: Level;
  context?: JsonRecord;
  source?: EventSource;
}

export interface EventTargetLike {
  addEventListener(type: string, listener: (event: any) => void): void;
  removeEventListener(type: string, listener: (event: any) => void): void;
}
