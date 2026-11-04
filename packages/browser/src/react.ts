import { FaultNest, type FaultNestClient } from './client.js';

/** React 19 root error info or ErrorBoundary componentDidCatch info (no React dependency). */
export interface ReactErrorInfo { componentStack?: string | null }

/** Explicitly reports an error, attaching component stack only if the application enables it. */
export function captureReactError(error: unknown, info?: ReactErrorInfo, client: FaultNestClient = FaultNest, includeComponentStack = false): Promise<boolean> {
  const stack = includeComponentStack && info?.componentStack ? { react: { componentStack: info.componentStack } } : undefined;
  return client.captureException(error, { source: 'react', ...(stack ? { context: stack } : {}) });
}

/** Pass these callbacks to React 19 createRoot/hydrateRoot. */
export function createReactRootHandlers(client: FaultNestClient = FaultNest): {
  onCaughtError: (error: unknown, info: ReactErrorInfo) => void;
  onUncaughtError: (error: unknown, info: ReactErrorInfo) => void;
  onRecoverableError: (error: unknown, info: ReactErrorInfo) => void;
} {
  const report = (error: unknown, info: ReactErrorInfo): void => { void captureReactError(error, info, client); };
  return { onCaughtError: report, onUncaughtError: report, onRecoverableError: report };
}
