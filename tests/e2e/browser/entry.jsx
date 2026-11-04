import React from 'react';
import { createRoot } from 'react-dom/client';
import { FaultNestClient, createFetchTransport } from '../../../packages/browser/dist/index.js';
import { captureReactError, createReactRootHandlers } from '../../../packages/browser/dist/react.js';

const monitor = new FaultNestClient();
const internal = [];
let drop = false;
monitor.init({
  transport: createFetchTransport({ endpoint: '/e2e/errors' }),
  integrations: ['javascript', 'unhandledrejection'],
  minLevel: 'warning',
  onInternalError: e => internal.push(String(e)),
  beforeSend: e => (drop || e.message.includes('e2e-cancel')) ? null : (e.message.includes('e2e-transform') ? { ...e, message: 'transformed by host', tags: { ...(e.tags ?? {}), transformed: 'yes' } } : e),
});
monitor.init({ transport: createFetchTransport({ endpoint: '/e2e/errors' }), integrations: ['javascript', 'unhandledrejection'], minLevel: 'warning',
  onInternalError: e => internal.push(String(e)),
  beforeSend: e => e.message.includes('e2e-cancel') ? null : (e.message.includes('e2e-transform') ? { ...e, message: 'transformed by host', tags: { ...(e.tags ?? {}), transformed: 'yes' } } : e) });

class Boundary extends React.Component {
  state = { failed: false };
  static getDerivedStateFromError() { return { failed: true }; }
  componentDidCatch(error, info) { captureReactError(error, info, monitor, true); }
  render() { return this.state.failed ? React.createElement('p', { id: 'fallback' }, 'fallback') : this.props.children; }
}
function Bomb({ armed, label }) { if (armed) throw new Error(label); return React.createElement('p', null, 'ok'); }

function App() {
  const [caught, setCaught] = React.useState(false);
  const [uncaught, setUncaught] = React.useState(false);
  window.e2e = { setCaught, setUncaught };
  return React.createElement('div', null,
    React.createElement(Boundary, null, React.createElement(Bomb, { armed: caught, label: 'react boundary failure' })),
    React.createElement(Bomb, { armed: uncaught, label: 'react uncaught root failure' }));
}
createRoot(document.getElementById('root'), createReactRootHandlers(monitor)).render(React.createElement(App));
Object.assign(window, { monitor, internal });
window.e2eReady = true;
