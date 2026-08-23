import { useEffect, useState } from 'react';
import { useStack } from '../stack/Stackflow';
import { useAuth, toast } from '../components/AuthContext';
import { useLang } from '../i18n/LanguageContext';
import { api } from '../api/client';

export function BottomNav() {
  const { resetTo, push, current } = useStack();
  const { isAuthed } = useAuth();
  const { t } = useLang();
  const [unread, setUnread] = useState(0);

  useEffect(() => {
    if (!isAuthed) {
      setUnread(0);
      return;
    }
    const load = () => {
      api.chatRooms()
        .then(d => {
          const total = (d.rooms || []).reduce((sum, r) => sum + Number(r.unread_count || 0), 0);
          setUnread(total);
        })
        .catch(() => {});
    };
    load();
    const timer = window.setInterval(load, 10000);
    return () => window.clearInterval(timer);
  }, [isAuthed]);

  const goSell = () => {
    if (!isAuthed) {
      sessionStorage.setItem('gugu_after_login', 'sell');
      toast(t('login_to_sell'), 'error');
      resetTo('auth');
      return;
    }
    push('sell');
  };

  /** Main tabs replace the stack so Me / Home never open a blank layer */
  const goTab = (name: string) => {
    if ((name === 'profile' || name === 'chat') && !isAuthed) {
      toast(t('login_first'), 'error');
      resetTo('auth');
      return;
    }
    resetTo(name);
  };

  const tabs = [
    { name: 'items', ico: '🏠', label: t('nav_items') },
    { name: 'neighborhood', ico: '📍', label: t('nav_neighborhood') },
    { name: 'sell', ico: '+', label: t('nav_sell'), fab: true },
    { name: 'chat', ico: '💬', label: t('nav_chat') },
    { name: 'profile', ico: '👤', label: t('nav_profile') },
  ];

  const mainTabs = ['items', 'neighborhood', 'chat', 'profile'];

  if (!mainTabs.includes(current.name) && current.name !== 'sell') return null;

  return (
    <nav className="seed-bottom-nav">
      {tabs.map(tItem => (
        <button
          key={tItem.name}
          type="button"
          className={`seed-nav-item${tItem.fab ? ' seed-nav-sell' : ''}${current.name === tItem.name ? ' active' : ''}`}
          onClick={() => (tItem.fab ? goSell() : goTab(tItem.name))}
        >
          {tItem.fab ? (
            <div className="fab">+</div>
          ) : (
            <span className="ico" style={{ position: 'relative' }}>
              {tItem.ico}
              {tItem.name === 'chat' && unread > 0 && (
                <span className="nav-unread-dot">{unread > 99 ? '99+' : unread}</span>
              )}
            </span>
          )}
          <span>{tItem.label}</span>
        </button>
      ))}
    </nav>
  );
}

export function Header({ title, back, variant }: { title?: string; back?: boolean; variant?: 'admin' }) {
  const { pop, canGoBack, resetTo } = useStack();
  return (
    <header className={`seed-header${variant === 'admin' ? ' seed-header-admin' : ''}`}>
      {(back || canGoBack) && (
        <button
          type="button"
          className="seed-back"
          onClick={() => (canGoBack ? pop() : resetTo('items'))}
        >
          ←
        </button>
      )}
      {title && <h1>{title}</h1>}
    </header>
  );
}
