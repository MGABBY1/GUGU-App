import { createContext, useContext, useState, useCallback, ReactNode, useEffect } from 'react';
import { User, api, ProfileSetupBody } from '../api/client';
import { syncHomeLocationFilter } from '../data/geo';

interface AuthCtx {
  user: User | null;
  token: string | null;
  login: (phone: string, password: string) => Promise<{ needs_profile?: boolean; needs_location?: boolean; needs_id_verification?: boolean; user: User }>;
  loginWithOtp: (phone: string, code: string) => Promise<{ needs_profile?: boolean; needs_location?: boolean; needs_id_verification?: boolean; is_new?: boolean; user: User }>;
  register: (body: Parameters<typeof api.register>[0]) => Promise<{ needs_location?: boolean; needs_id_verification?: boolean; user: User }>;
  completeProfile: (body: ProfileSetupBody) => Promise<{ needs_location?: boolean }>;
  verifyLocation: (lat: number, lng: number, place?: { district?: string; sector?: string; province?: string }) => Promise<void>;
  submitId: (form: FormData) => Promise<{ user: User; message?: string }>;
  refreshUser: () => Promise<void>;
  logout: () => void;
  isAuthed: boolean;
}

const AuthContext = createContext<AuthCtx | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(() => {
    const s = localStorage.getItem('gugu_user');
    return s ? JSON.parse(s) : null;
  });
  const [token, setToken] = useState<string | null>(() => localStorage.getItem('gugu_token'));

  const setAuth = (t: string, u: User) => {
    setToken(t); setUser(u);
    localStorage.setItem('gugu_token', t);
    localStorage.setItem('gugu_user', JSON.stringify(u));
  };

  const updateUser = (u: User) => {
    setUser(u);
    localStorage.setItem('gugu_user', JSON.stringify(u));
  };

  const login = async (phone: string, password: string) => {
    const data = await api.login(phone, password);
    setAuth(data.token, data.user);
    return {
      needs_profile: data.needs_profile,
      needs_location: data.needs_location,
      needs_id_verification: data.needs_id_verification ?? data.user.needs_id_verification,
      user: data.user,
    };
  };

  const loginWithOtp = async (phone: string, code: string) => {
    const data = await api.verifyOtp(phone, code);
    setAuth(data.token, data.user);
    return {
      needs_profile: data.needs_profile || data.is_new,
      needs_location: data.needs_location,
      needs_id_verification: data.needs_id_verification ?? data.user.needs_id_verification,
      is_new: data.is_new,
      user: data.user,
    };
  };

  const register = async (body: Parameters<typeof api.register>[0]) => {
    const data = await api.register(body);
    setAuth(data.token, data.user);
    return {
      needs_location: data.needs_location,
      needs_id_verification: data.needs_id_verification ?? data.user.needs_id_verification,
      user: data.user,
    };
  };

  const completeProfile = async (body: ProfileSetupBody) => {
    const data = await api.completeProfile(body);
    updateUser(data.user);
    return { needs_location: data.needs_location };
  };

  const verifyLocation = async (
    lat: number,
    lng: number,
    place?: { district?: string; sector?: string; province?: string },
  ) => {
    const data = await api.verifyLocation(lat, lng, place);
    updateUser(data.user);
    const d = place?.district || data.user?.district || '';
    const s = place?.sector || data.user?.sector || '';
    if (d) syncHomeLocationFilter(d, s);
  };

  const submitId = async (form: FormData) => {
    const data = await api.submitId(form);
    updateUser(data.user);
    return { user: data.user, message: data.message };
  };

  const logout = () => {
    setToken(null); setUser(null);
    localStorage.removeItem('gugu_token');
    localStorage.removeItem('gugu_user');
  };

  const refreshUser = async () => {
    if (!localStorage.getItem('gugu_token')) return;
    try {
      const data = await api.me();
      updateUser(data.user);
    } catch { /* keep existing session on network blip — do not force logout */ }
  };

  // Soft-validate session on load; only clear if server says unauthorized
  useEffect(() => {
    const t = localStorage.getItem('gugu_token');
    if (!t) return;
    api.me()
      .then(data => updateUser(data.user))
      .catch((err: Error) => {
        const msg = (err?.message || '').toLowerCase();
        if (msg.includes('unauthorized') || msg.includes('401') || msg.includes('winjire') || msg.includes('login')) {
          logout();
        }
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <AuthContext.Provider value={{
      user, token, login, loginWithOtp, register, completeProfile, verifyLocation, submitId, refreshUser, logout,
      isAuthed: !!token,
    }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth required');
  return ctx;
}

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<{ id: number; msg: string; type: string }[]>([]);

  const toast = useCallback((msg: string, type = 'info') => {
    const id = Date.now();
    setToasts(t => [...t, { id, msg, type }]);
    setTimeout(() => setToasts(t => t.filter(x => x.id !== id)), 3000);
  }, []);

  useEffect(() => {
    (window as unknown as { guguToast: typeof toast }).guguToast = toast;
  }, [toast]);

  return (
    <>
      <div className="toast-wrap">
        {toasts.map(t => (
          <div key={t.id} className={`toast ${t.type === 'success' ? 'ok' : t.type === 'error' ? 'err' : ''}`}>{t.msg}</div>
        ))}
      </div>
      {children}
    </>
  );
}

export function toast(msg: string, type = 'info') {
  (window as unknown as { guguToast?: (m: string, t: string) => void }).guguToast?.(msg, type);
}
