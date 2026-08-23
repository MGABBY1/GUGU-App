import { createContext, useContext, useState, useCallback, useRef, useEffect, ReactNode } from 'react';

export type StackScreen = {
  id: string;
  name: string;
  params?: Record<string, unknown>;
};

type StackContextType = {
  stack: StackScreen[];
  push: (name: string, params?: Record<string, unknown>) => void;
  pop: () => void;
  popToRoot: () => void;
  resetTo: (name: string, params?: Record<string, unknown>) => void;
  replace: (name: string, params?: Record<string, unknown>) => void;
  current: StackScreen;
  canGoBack: boolean;
};

const StackContext = createContext<StackContextType | null>(null);

const STACK_KEY = 'gugu_stack_screen';

/** Screens safe to restore after refresh (skip login/register) */
const RESTORE_OK = new Set([
  'items', 'home', 'sell', 'detail', 'profile', 'settings', 'account', 'dashboard',
  'neighborhood', 'chat', 'chat-room', 'favorites', 'services',
  'recent', 'benefits', 'jobs', 'post-job', 'my-listings',
]);

let screenId = 0;
function newId() { return `s-${++screenId}-${Date.now()}`; }

export function saveStackScreen(name: string, params?: Record<string, unknown>) {
  try {
    if (!RESTORE_OK.has(name)) return;
    sessionStorage.setItem(STACK_KEY, JSON.stringify({ name, params: params || {} }));
  } catch { /* ignore */ }
}

export function readSavedStackScreen(): { name: string; params?: Record<string, unknown> } | null {
  try {
    const raw = sessionStorage.getItem(STACK_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw) as { name?: string; params?: Record<string, unknown> };
    if (!parsed?.name || !RESTORE_OK.has(parsed.name)) return null;
    return { name: parsed.name, params: parsed.params };
  } catch {
    return null;
  }
}

/** Drop ?login=1 / ?auth from the address bar so refresh does not force login */
export function clearLoginQuery() {
  try {
    const url = new URL(window.location.href);
    if (!url.searchParams.has('login') && !url.searchParams.has('auth')) return;
    url.searchParams.delete('login');
    url.searchParams.delete('auth');
    const qs = url.searchParams.toString();
    window.history.replaceState({}, '', url.pathname + (qs ? `?${qs}` : '') + url.hash);
  } catch { /* ignore */ }
}

export function StackProvider({
  initial,
  initialParams,
  children,
}: {
  initial: string;
  initialParams?: Record<string, unknown>;
  children: ReactNode;
}) {
  const [stack, setStack] = useState<StackScreen[]>([
    { id: newId(), name: initial, params: initialParams },
  ]);
  const animating = useRef(false);

  useEffect(() => {
    const top = stack[stack.length - 1];
    if (top) saveStackScreen(top.name, top.params);
  }, [stack]);

  const push = useCallback((name: string, params?: Record<string, unknown>) => {
    if (animating.current) return;
    animating.current = true;
    setStack(s => [...s, { id: newId(), name, params }]);
    setTimeout(() => { animating.current = false; }, 320);
  }, []);

  const pop = useCallback(() => {
    if (animating.current) return;
    setStack(s => (s.length > 1 ? s.slice(0, -1) : s));
  }, []);

  const popToRoot = useCallback(() => {
    setStack(s => [s[0]]);
  }, []);

  const resetTo = useCallback((name: string, params?: Record<string, unknown>) => {
    setStack([{ id: newId(), name, params }]);
  }, []);

  const replace = useCallback((name: string, params?: Record<string, unknown>) => {
    setStack(s => {
      const root = s.slice(0, -1);
      return [...root, { id: newId(), name, params }];
    });
  }, []);

  const current = stack[stack.length - 1];

  return (
    <StackContext.Provider value={{ stack, push, pop, popToRoot, resetTo, replace, current, canGoBack: stack.length > 1 }}>
      {children}
    </StackContext.Provider>
  );
}

export function useStack() {
  const ctx = useContext(StackContext);
  if (!ctx) throw new Error('useStack must be used within StackProvider');
  return ctx;
}

/** Stackflow-style layered screens with push/pop animation */
export function StackNavigator({
  screens,
}: {
  screens: Record<string, (params?: Record<string, unknown>) => ReactNode>;
}) {
  const { stack } = useStack();
  const depth = stack.length;
  const prevDepth = useRef(depth);
  const [enterId, setEnterId] = useState<string | null>(null);

  useEffect(() => {
    const grew = depth > prevDepth.current;
    prevDepth.current = depth;
    if (!grew || depth < 2) {
      setEnterId(null);
      return;
    }
    const top = stack[depth - 1];
    setEnterId(top.id);
    const timer = window.setTimeout(() => setEnterId(null), 340);
    return () => window.clearTimeout(timer);
  }, [depth, stack]);

  return (
    <div className="stack-root">
      {stack.map((screen, index) => {
        const isTop = index === depth - 1;
        const isPrev = index === depth - 2;
        const Render = screens[screen.name];
        if (!Render) return null;

        return (
          <div
            key={screen.id}
            className={`stack-layer${isTop && enterId === screen.id ? ' enter' : ''}`}
            style={{
              zIndex: isTop ? depth + 10 : index + 1,
              display: isTop || isPrev ? 'flex' : 'none',
              pointerEvents: isTop ? 'auto' : 'none',
              boxShadow: isTop && index > 0 ? '-4px 0 20px rgba(0,0,0,0.08)' : undefined,
            }}
            aria-hidden={!isTop}
          >
            {Render(screen.params)}
          </div>
        );
      })}
    </div>
  );
}
