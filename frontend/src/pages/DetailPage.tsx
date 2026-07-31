import { useEffect, useState } from 'react';
import { useStack } from '../stack/Stackflow';
import { api, ListingDetail, CATEGORY_ICONS } from '../api/client';
import { useAuth, toast } from '../components/AuthContext';
import { Header } from '../components/BottomNav';
import { useLang, LanguageSwitcher } from '../i18n/LanguageContext';

export default function DetailPage({ id }: { id?: number }) {
  const listingId = id!;
  const { push } = useStack();
  const { isAuthed } = useAuth();
  const { t, price, timeAgo, category } = useLang();
  const [l, setL] = useState<ListingDetail | null>(null);

  useEffect(() => {
    api.listing(listingId).then(d => setL(d.listing)).catch(() => toast(t('not_found'), 'error'));
  }, [listingId]);

  if (!l) return <><Header title={t('item')} back /><div className="stack-content" style={{ padding: 40, textAlign: 'center' }}>{t('loading')}</div></>;

  return (
    <>
      <Header title={t('item')} back />
      <div className="stack-content" style={{ paddingBottom: 80 }}>
        {l.images && l.images.length > 1 ? (
          <div className="detail-gallery">
            {l.images.map((img, i) => (
              <img key={i} src={img.url} alt="" />
            ))}
          </div>
        ) : (
          <div style={{ aspectRatio: 1, background: '#f0f0f0' }}>
            {l.images?.[0] ? <img src={l.images[0].url} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} /> : <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', height: '100%', fontSize: '4rem' }}>📦</div>}
          </div>
        )}
        <div style={{ padding: 20 }}>
          <h1 style={{ fontSize: '1.25rem', fontWeight: 700, marginBottom: 8 }}>{l.title}</h1>
          <div style={{ fontSize: '1.5rem', fontWeight: 800, marginBottom: 8, color: l.is_free ? 'var(--seed-green)' : undefined }}>{price(l.price, l.is_free)}</div>
          <p style={{ fontSize: '0.875rem', color: 'var(--seed-gray-600)', marginBottom: 16, paddingBottom: 16, borderBottom: '1px solid var(--seed-gray-200)' }}>
            {CATEGORY_ICONS[l.category_icon]} {category(l.category_name_rw, l.category_name_en, l.category_name)} · {l.sector ? `${l.sector}, ${l.district}` : l.district} · {timeAgo(l.created_at) || l.time_ago}
          </p>
          <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: 14, background: 'var(--seed-gray-100)', borderRadius: 12, marginBottom: 20, cursor: 'pointer' }} onClick={() => push('user-profile', { id: l.user_id })}>
            <div style={{ width: 44, height: 44, borderRadius: '50%', background: 'var(--seed-carrot-light)', color: 'var(--seed-carrot)', fontWeight: 700, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>{l.seller_name[0]}</div>
            <div style={{ flex: 1 }}><div style={{ fontWeight: 600 }}>{l.seller_display || l.seller_name}</div><div style={{ fontSize: '0.8125rem', color: 'var(--seed-gray-600)' }}>{l.seller_district}</div></div>
            <div style={{ background: '#FFF8E6', color: '#B45309', padding: '5px 10px', borderRadius: 20, fontSize: '0.8125rem', fontWeight: 700 }}>🌡️ {parseFloat(String(l.seller_manner)).toFixed(1)}°C</div>
          </div>
          <h3 style={{ fontWeight: 700, marginBottom: 8 }}>{t('description')}</h3>
          <p style={{ lineHeight: 1.7, whiteSpace: 'pre-wrap', marginBottom: 24 }}>{l.description}</p>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
            {!l.is_owner && (
              <>
                <button className="seed-btn seed-btn-carrot seed-btn-block" onClick={async () => {
                  if (!isAuthed) { push('auth'); return; }
                  const d = await api.createRoom(l.id);
                  push('chat-room', { roomId: d.room_id });
                }}>{t('chat_seller')}</button>
                <button className="seed-btn seed-btn-outline seed-btn-block" onClick={async () => {
                  if (!isAuthed) { push('auth'); return; }
                  const d = await api.toggleFavorite(l.id);
                  toast(d.favorited ? t('favorited') : t('unfavorited'), 'success');
                }}>{t('favorite')}</button>
              </>
            )}
          </div>
        </div>
      </div>
    </>
  );
}

function MiniNav({ active }: { active: string }) {
  const { push } = useStack();
  const { t } = useLang();
  const tabs = [
    { name: 'items', ico: '🏠', label: t('nav_home') },
    { name: 'neighborhood', ico: '📍', label: t('nav_neighborhood') },
    { name: 'chat', ico: '💬', label: t('nav_chat') },
    { name: 'profile', ico: '👤', label: t('nav_profile') },
  ];
  return (
    <nav className="seed-bottom-nav">
      {tabs.map(tab => (
        <button key={tab.name} className={`seed-nav-item${tab.name === active ? ' active' : ''}`} onClick={() => push(tab.name)}>
          <span className="ico">{tab.ico}</span><span>{tab.label}</span>
        </button>
      ))}
    </nav>
  );
}

export function ProfilePage() {
  const { user, logout, isAuthed, verifyLocation } = useAuth();
  const { push } = useStack();
  const { t } = useLang();
  const [stats, setStats] = useState({ active_listings: 0, sold_listings: 0, favorites_count: 0 });
  const [gpsLoading, setGpsLoading] = useState(false);

  useEffect(() => {
    if (!isAuthed) { push('auth'); return; }
    api.profile().then(d => setStats(d.user)).catch(() => {});
  }, [isAuthed]);

  if (!user) return null;

  const display = user.display_name || `${user.nickname || user.full_name} • ${user.district}`;
  const manner = parseFloat(String(user.manner_score || 36.5)).toFixed(1);

  const reverifyGps = () => {
    setGpsLoading(true);
    (async () => {
      try {
        const { getBrowserPosition, resolveRwandaLocation, manualFromDistrict, gpsErrorKind } = await import('../data/geo');
        try {
          const pos = await getBrowserPosition();
          const suggestion = await resolveRwandaLocation(pos.coords.latitude, pos.coords.longitude);
          await verifyLocation(pos.coords.latitude, pos.coords.longitude, {
            district: suggestion.district,
            sector: suggestion.sector,
            province: suggestion.province,
          });
          toast(`${t('gps_ok')} — ${suggestion.district}${suggestion.sector ? ' / ' + suggestion.sector : ''}`, 'success');
        } catch (err) {
          const kind = gpsErrorKind(err as GeolocationPositionError);
          if (kind === 'denied') toast(t('gps_permission'), 'error');
          const district = user?.district || 'Gasabo';
          const suggestion = manualFromDistrict(district, user?.sector || '');
          await verifyLocation(suggestion.lat, suggestion.lng, {
            district: suggestion.district,
            sector: suggestion.sector,
            province: suggestion.province,
          });
          toast(`${t('gps_manual_ok')} — ${suggestion.district}`, 'success');
        }
      } catch (err) {
        toast((err as Error).message, 'error');
      } finally {
        setGpsLoading(false);
      }
    })();
  };

  return (
    <>
      <div className="stack-content">
        <div style={{ padding: 24, background: 'white', borderBottom: '8px solid var(--seed-gray-100)' }}>
          <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 12 }}>
            <LanguageSwitcher />
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
            <div style={{ width: 64, height: 64, borderRadius: '50%', background: 'var(--seed-carrot-light)', color: 'var(--seed-carrot)', fontSize: '1.5rem', fontWeight: 800, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
              {(user.nickname || user.full_name || '?')[0]}
            </div>
            <div style={{ flex: 1 }}>
              <h2 style={{ fontWeight: 700 }}>{display}</h2>
              <p style={{ color: 'var(--seed-gray-600)', fontSize: '0.875rem' }}>{user.district}{user.province ? `, ${user.province}` : ''}</p>
              <div style={{ display: 'inline-flex', alignItems: 'center', gap: 6, marginTop: 6, background: '#FFF8E6', color: '#B45309', padding: '4px 10px', borderRadius: 16, fontSize: '0.8125rem', fontWeight: 700 }}>
                🌡️ {manner}°C · {t('manner_temp')}
              </div>
            </div>
          </div>
          {user.needs_location && (
            <div style={{ marginTop: 14, padding: 12, background: '#FFF8E0', borderRadius: 10, fontSize: '0.8125rem' }}>
              {t('gps_hint')}
              <button type="button" className="seed-btn seed-btn-block" style={{ marginTop: 8, background: '#FAD201', color: '#1A1A1A', fontWeight: 700, padding: '10px' }} onClick={reverifyGps} disabled={gpsLoading}>
                {gpsLoading ? t('waiting') : t('reverify_gps')}
              </button>
            </div>
          )}
          {!user.needs_location && (
            <button type="button" style={{ marginTop: 12, fontSize: '0.8125rem', color: 'var(--seed-carrot)', fontWeight: 600 }} onClick={reverifyGps} disabled={gpsLoading}>
              📍 {t('reverify_gps')}{user.location_days_left != null ? ` (${user.location_days_left}d)` : ''}
            </button>
          )}
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3,1fr)', gap: 8, marginTop: 16 }}>
            {[[t('for_sale'), stats.active_listings], [t('sold'), stats.sold_listings], [t('liked'), stats.favorites_count]].map(([lbl, val]) => (
              <div key={lbl as string} style={{ textAlign: 'center', padding: 12, background: 'var(--seed-gray-100)', borderRadius: 12 }}>
                <div style={{ fontSize: '1.25rem', fontWeight: 800 }}>{val}</div>
                <div style={{ fontSize: '0.6875rem', color: 'var(--seed-gray-600)' }}>{lbl}</div>
              </div>
            ))}
          </div>
        </div>
        <div style={{ padding: 16 }}>
          <button onClick={() => push('sell')} style={{ display: 'flex', width: '100%', padding: '14px 16px', background: 'white', borderRadius: 12, marginBottom: 8, fontSize: '0.9375rem', fontWeight: 500, border: '1px solid var(--seed-gray-200)' }}>➕ {t('sell_title')} ›</button>
          <button onClick={() => push('favorites')} style={{ display: 'flex', width: '100%', padding: '14px 16px', background: 'white', borderRadius: 12, marginBottom: 8, fontSize: '0.9375rem', fontWeight: 500, border: '1px solid var(--seed-gray-200)' }}>❤️ {t('favorites_title')} ›</button>
          <button onClick={logout} style={{ display: 'flex', width: '100%', padding: '14px 16px', background: 'white', borderRadius: 12, color: 'var(--seed-red)', fontWeight: 500, border: '1px solid var(--seed-gray-200)' }}>{t('logout')}</button>
        </div>
      </div>
      <MiniNav active="profile" />
    </>
  );
}

export function NeighborhoodPage() {
  const { push } = useStack();
  const { t } = useLang();
  return (
    <>
      <div className="stack-content" style={{ padding: 16 }}>
        <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 8 }}>
          <LanguageSwitcher compact />
        </div>
        <div style={{ background: 'linear-gradient(135deg, var(--seed-carrot), var(--seed-green))', borderRadius: 16, padding: 24, color: 'white', marginBottom: 16 }}>
          <h2 style={{ fontWeight: 700, marginBottom: 6 }}>{t('neighborhood_title')}</h2>
          <p style={{ fontSize: '0.9375rem', opacity: 0.9 }}>{t('neighborhood_sub')}</p>
        </div>
        {[
          ['🛡️', t('tip_safety'), t('tip_safety_desc')],
          ['📍', t('tip_local'), t('tip_local_desc')],
          ['💬', t('tip_chat'), t('tip_chat_desc')],
        ].map(([ico, title, desc]) => (
          <div key={title} style={{ background: 'white', borderRadius: 12, padding: 16, marginBottom: 10, display: 'flex', gap: 12 }}>
            <span style={{ fontSize: '1.5rem' }}>{ico}</span>
            <div><h3 style={{ fontWeight: 600, marginBottom: 4 }}>{title}</h3><p style={{ fontSize: '0.8125rem', color: 'var(--seed-gray-600)' }}>{desc}</p></div>
          </div>
        ))}
        <button className="seed-btn seed-btn-carrot seed-btn-block" onClick={() => push('sell')}>{t('start_selling')}</button>
      </div>
      <MiniNav active="neighborhood" />
    </>
  );
}

export function ChatPage() {
  const { push } = useStack();
  const { isAuthed } = useAuth();
  const { t } = useLang();
  const [rooms, setRooms] = useState<{ id: number; other_name: string; last_message?: string; listing_title: string }[]>([]);

  useEffect(() => {
    if (!isAuthed) { push('auth'); return; }
    api.chatRooms().then(d => setRooms(d.rooms)).catch(() => {});
  }, [isAuthed]);

  return (
    <>
      <header className="seed-header"><h1 style={{ paddingRight: 0 }}>{t('messages')}</h1></header>
      <div className="stack-content">
        {rooms.length === 0 ? (
          <div style={{ textAlign: 'center', padding: 60, color: 'var(--seed-gray-600)' }}>
            <div style={{ fontSize: '3rem' }}>💬</div><p>{t('no_messages')}</p>
          </div>
        ) : rooms.map(r => (
          <div key={r.id} onClick={() => push('chat-room', { roomId: r.id })} style={{ display: 'flex', gap: 12, padding: '14px 16px', borderBottom: '1px solid var(--seed-gray-200)', cursor: 'pointer' }}>
            <div style={{ flex: 1 }}><div style={{ fontWeight: 600 }}>{r.other_name}</div><div style={{ fontSize: '0.8125rem', color: 'var(--seed-gray-600)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{r.last_message || r.listing_title}</div></div>
          </div>
        ))}
      </div>
      <MiniNav active="chat" />
    </>
  );
}

export function ChatRoomPage({ roomId }: { roomId?: number }) {
  const { t } = useLang();
  const [messages, setMessages] = useState<{ content: string; is_mine: boolean }[]>([]);
  const [text, setText] = useState('');

  useEffect(() => {
    api.messages(roomId!).then(d => setMessages(d.messages)).catch(() => {});
  }, [roomId]);

  const send = async () => {
    if (!text.trim()) return;
    await api.sendMessage(roomId!, text);
    setText('');
    api.messages(roomId!).then(d => setMessages(d.messages));
  };

  return (
    <>
      <Header title={t('messages')} back />
      <div className="stack-content" style={{ display: 'flex', flexDirection: 'column', background: 'var(--seed-gray-100)' }}>
        <div style={{ flex: 1, padding: 16, display: 'flex', flexDirection: 'column', gap: 8, overflowY: 'auto' }}>
          {messages.map((m, i) => (
            <div key={i} style={{ alignSelf: m.is_mine ? 'flex-end' : 'flex-start', maxWidth: '75%', padding: '10px 14px', borderRadius: 16, background: m.is_mine ? 'var(--seed-carrot)' : 'white', color: m.is_mine ? 'white' : 'inherit', border: m.is_mine ? 'none' : '1px solid var(--seed-gray-200)' }}>{m.content}</div>
          ))}
        </div>
        <div style={{ padding: '10px 12px', borderTop: '1px solid var(--seed-gray-200)', display: 'flex', gap: 8, background: 'white' }}>
          <input className="seed-input" style={{ flex: 1, borderRadius: 22 }} value={text} onChange={e => setText(e.target.value)} onKeyDown={e => e.key === 'Enter' && send()} placeholder={t('type_message')} />
          <button onClick={send} style={{ width: 40, height: 40, background: 'var(--seed-carrot)', color: 'white', borderRadius: '50%' }}>➤</button>
        </div>
      </div>
    </>
  );
}

export function FavoritesPage() {
  const { push } = useStack();
  const { t, price, timeAgo, category } = useLang();
  const [listings, setListings] = useState<{
    id: number; title: string; price: number; price_formatted: string; district: string; sector?: string;
    created_at?: string; time_ago?: string; primary_image?: string; is_free: number;
    category_name?: string; category_name_rw?: string; category_name_en?: string; category_icon?: string;
  }[]>([]);

  useEffect(() => {
    api.favorites().then(d => setFavorites(d.favorites)).catch(() => {});
  }, []);

  const setFavorites = (items: typeof listings) => setListings(items);

  return (
    <>
      <Header title={t('favorites_title')} back />
      <div className="stack-content">
        <div className="product-grid">
          {listings.map(l => (
            <article key={l.id} className="product-card" onClick={() => push('detail', { id: l.id })}>
              <div className="card-img">{l.primary_image ? <img src={l.primary_image} alt="" /> : <div style={{ width: '100%', height: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: '2rem' }}>📦</div>}</div>
              <div className="card-title">{l.title}</div>
              <div className={`card-price${l.is_free ? ' free' : ''}`}>{price(l.price, l.is_free)}</div>
              <div className="card-meta">{l.sector ? `${l.sector}, ${l.district}` : l.district} · {timeAgo(l.created_at) || l.time_ago}</div>
              {(l.category_name_rw || l.category_name) && (
                <div className="market-card-cat">{CATEGORY_ICONS[l.category_icon || 'box']} {category(l.category_name_rw, l.category_name_en, l.category_name)}</div>
              )}
            </article>
          ))}
        </div>
      </div>
    </>
  );
}
