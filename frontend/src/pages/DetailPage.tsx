import { useEffect, useState } from 'react';
import { useStack } from '../stack/Stackflow';
import { api, ListingDetail, CATEGORY_ICONS, isStaffUser, roleLabel } from '../api/client';
import { useAuth, toast } from '../components/AuthContext';
import { Header } from '../components/BottomNav';
import { useLang, LanguageSwitcher } from '../i18n/LanguageContext';
import { formatTrustScore } from '../i18n/format';
import { LANG_META, BRAND_NAME, type Lang } from '../i18n/translations';
import { getGuguServices } from './ServicePages';
import { LocationSheet } from '../components/LocationSheet';
import { syncHomeLocationFilter } from '../data/geo';
import { trackRecentView, JOBS_CATEGORY_ID } from '../data/services';

export default function DetailPage({ id }: { id?: number }) {
  const listingId = id!;
  const { push, resetTo } = useStack();
  const { isAuthed } = useAuth();
  const { t, price, timeAgo, category } = useLang();
  const [l, setL] = useState<ListingDetail | null>(null);
  const [busy, setBusy] = useState(false);
  const [saved, setSaved] = useState(false);
  const [contactOpen, setContactOpen] = useState(false);
  const [applyOpen, setApplyOpen] = useState(false);
  const [applyMsg, setApplyMsg] = useState('');

  useEffect(() => {
    api.listing(listingId)
      .then(d => {
        setL(d.listing);
        setSaved(!!d.listing.is_favorited);
        trackRecentView({
          id: d.listing.id,
          title: d.listing.title,
          price: d.listing.price,
          price_formatted: d.listing.price_formatted,
          is_free: d.listing.is_free,
          district: d.listing.district,
          sector: d.listing.sector,
          primary_image: d.listing.images?.[0]?.url || d.listing.primary_image,
        });
      })
      .catch(() => toast(t('not_found'), 'error'));
  }, [listingId]);

  if (!l) {
    return (
      <>
        <Header title={t('item')} back />
        <div className="stack-content" style={{ padding: 40, textAlign: 'center' }}>{t('loading')}</div>
      </>
    );
  }

  const isSeller = !!l.is_owner;
  const isJob =
    Number(l.category_id) === JOBS_CATEGORY_ID
    || l.category_icon === 'job'
    || String(l.category_name_en || '').toLowerCase() === 'jobs';
  const meetPlace = [l.sector, l.district].filter(Boolean).join(', ') || l.district || 'Rwanda';
  const sellerPlace = [l.seller_sector, l.seller_district].filter(Boolean).join(', ') || l.seller_district;
  const mapsUrl = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${meetPlace}, Rwanda`)}`;
  const posterPhone = (l.seller_phone || '').replace(/\s+/g, '');
  const telHref = posterPhone
    ? `tel:+${posterPhone.startsWith('250') ? posterPhone : posterPhone.replace(/^0/, '250')}`
    : '';

  const requireLogin = (after: string) => {
    sessionStorage.setItem('gugu_after_login', after);
    toast(t('login_first'), 'error');
    push('auth');
  };

  const openChatRoom = (roomId: number) => {
    setTimeout(() => push('chat-room', { roomId: Number(roomId) }), 50);
  };

  const startChat = async () => {
    if (!isAuthed) {
      requireLogin('detail:' + listingId);
      return;
    }
    setBusy(true);
    try {
      const d = await api.createRoom(l.id);
      openChatRoom(d.room_id);
    } catch (err) {
      toast((err as Error).message, 'error');
    } finally {
      setBusy(false);
    }
  };

  const openContact = () => {
    if (!isAuthed) {
      requireLogin('detail:' + listingId);
      return;
    }
    if (!posterPhone) {
      toast(t('job_no_phone'), 'error');
      return;
    }
    setContactOpen(true);
  };

  const copyPhone = async () => {
    if (!posterPhone) return;
    try {
      await navigator.clipboard.writeText(posterPhone);
      toast(t('job_phone_copied'), 'success');
    } catch {
      toast(posterPhone, 'success');
    }
  };

  const openApply = () => {
    if (!isAuthed) {
      requireLogin('apply:' + listingId);
      return;
    }
    if (isSeller) {
      toast(t('job_apply_own'), 'error');
      return;
    }
    setApplyMsg(t('job_apply_msg').replace('{title}', l.title));
    setApplyOpen(true);
  };

  const submitApply = async () => {
    if (!isAuthed) {
      requireLogin('apply:' + listingId);
      return;
    }
    const msg = applyMsg.trim() || t('job_apply_msg').replace('{title}', l.title);
    setBusy(true);
    try {
      const d = await api.createRoom(l.id);
      try {
        await api.sendMessage(d.room_id, msg);
      } catch {
        /* still open chat */
      }
      setApplyOpen(false);
      toast(t('job_apply_sent'), 'success');
      openChatRoom(d.room_id);
    } catch (err) {
      toast((err as Error).message, 'error');
    } finally {
      setBusy(false);
    }
  };

  const toggleSave = async () => {
    if (!isAuthed) {
      push('auth');
      return;
    }
    try {
      const d = await api.toggleFavorite(l.id);
      setSaved(d.favorited);
      toast(d.favorited ? t('favorited') : t('unfavorited'), 'success');
    } catch (err) {
      toast((err as Error).message, 'error');
    }
  };

  return (
    <>
      <Header title={isJob ? t('jobs_title') : t('item')} back />
      <div className="stack-content detail-page">
        {l.images && l.images.length > 1 ? (
          <div className="detail-gallery">
            {l.images.map((img, i) => (
              <img key={i} src={img.url} alt="" />
            ))}
          </div>
        ) : (
          <div className={`detail-hero${isJob ? ' detail-hero-job' : ''}`}>
            {l.images?.[0] ? (
              <img src={l.images[0].url} alt="" />
            ) : (
              <div className="detail-hero-empty">{isJob ? '💼' : '📦'}</div>
            )}
          </div>
        )}

        <div className="detail-body">
          <div className="detail-price-row">
            <div className={`detail-price${l.is_free ? ' free' : ''}`}>
              {isJob && l.is_free ? t('pay_negotiable') : price(l.price, l.is_free)}
            </div>
            <button type="button" className={`detail-heart${saved ? ' on' : ''}`} onClick={toggleSave} aria-label={t('favorite')}>
              {saved ? '❤️' : '♡'}
            </button>
          </div>
          <h1 className="detail-title">{l.title}</h1>
          <p className="detail-meta">
            {isJob ? (
              <><span className="jobs-announce-tag">{t('job_announcement')}</span>{' · '}</>
            ) : (
              <>{CATEGORY_ICONS[l.category_icon]} {category(l.category_name_rw, l.category_name_en, l.category_name)}{' · '}</>
            )}
            {timeAgo(l.created_at) || l.time_ago}
            {l.view_count != null ? ` · ${l.view_count} ${t('views_label')}` : ''}
          </p>

          <section className="detail-meet">
            <div className="detail-meet-ico">📍</div>
            <div className="detail-meet-body">
              <strong>{isJob ? t('district') : t('meet_location')}</strong>
              <div className="detail-meet-place">{meetPlace}</div>
              {!isJob && <p>{t('meet_hint')}</p>}
              {isJob && <p>{t('benefit_gps_desc')}</p>}
              <a className="detail-meet-maps" href={mapsUrl} target="_blank" rel="noreferrer">
                {t('open_maps')} →
              </a>
            </div>
          </section>

          <div className="detail-seller">
            <div className="detail-seller-avatar">{(l.seller_display || l.seller_name || 'G')[0]}</div>
            <div className="detail-seller-text">
              <div className="detail-seller-name">{l.seller_display || l.seller_name}</div>
              <div className="detail-seller-sub">
                {isJob ? t('job_contact_title') : t('seller_label')}
                {sellerPlace ? ` · ${sellerPlace}` : ''}
              </div>
            </div>
            <div className="detail-seller-temp">⭐ {formatTrustScore(l.seller_manner)}</div>
          </div>

          {isSeller ? (
            <div className="gugu-deal-role gugu-deal-seller">{isJob ? t('job_announcement') : t('you_are_seller')}</div>
          ) : isAuthed && !isJob ? (
            <div className="gugu-deal-role gugu-deal-buyer">{t('you_can_buy')}</div>
          ) : null}

          <h3 className="detail-section-title">{t('description')}</h3>
          <p className="detail-desc">{l.description}</p>
        </div>
      </div>

      {!isSeller && !isJob && (
        <div className="detail-cta-bar">
          <button type="button" className="detail-cta-chat" disabled={busy} onClick={startChat}>
            💬 {t('chat_seller')}
          </button>
          <button type="button" className="detail-cta-buy" disabled={busy} onClick={startChat}>
            {t('buy_now')}
          </button>
        </div>
      )}

      {!isSeller && isJob && (
        <div className="detail-cta-bar">
          <button type="button" className="detail-cta-chat" disabled={busy} onClick={openContact}>
            📞 {t('job_contact')}
          </button>
          <button type="button" className="detail-cta-buy" disabled={busy} onClick={openApply}>
            {t('job_apply')}
          </button>
        </div>
      )}

      {isSeller && (
        <div className="detail-cta-bar">
          <button type="button" className="detail-cta-buy" onClick={() => (isJob ? resetTo('jobs') : resetTo('profile'))}>
            {isJob ? t('available_jobs') : t('my_listings')}
          </button>
        </div>
      )}

      {contactOpen && (
        <div className="job-contact-backdrop" role="dialog" aria-modal="true" onClick={() => setContactOpen(false)}>
          <div className="job-contact-sheet" onClick={e => e.stopPropagation()}>
            <div className="job-contact-handle" />
            <h2>{t('job_contact_title')}</h2>
            <p className="job-contact-hint">{t('job_contact_hint')}</p>
            <div className="job-contact-phone">{posterPhone || '—'}</div>
            <div className="job-contact-actions">
              {telHref && (
                <a className="seed-btn seed-btn-carrot seed-btn-block" href={telHref}>
                  {t('job_call')}
                </a>
              )}
              <button type="button" className="seed-btn seed-btn-outline seed-btn-block" onClick={copyPhone}>
                {t('job_copy_phone')}
              </button>
              <button
                type="button"
                className="seed-btn seed-btn-outline seed-btn-block"
                onClick={() => { setContactOpen(false); openApply(); }}
              >
                {t('job_apply')}
              </button>
            </div>
            <button type="button" className="gugu-login-link" style={{ marginTop: 12 }} onClick={() => setContactOpen(false)}>
              {t('cancel')}
            </button>
          </div>
        </div>
      )}

      {applyOpen && (
        <div className="job-contact-backdrop" role="dialog" aria-modal="true" onClick={() => setApplyOpen(false)}>
          <div className="job-contact-sheet job-apply-sheet" onClick={e => e.stopPropagation()}>
            <div className="job-contact-handle" />
            <h2>{t('job_apply')}</h2>
            <p className="job-contact-hint">{t('job_apply_hint')}</p>
            <div className="job-apply-title">{l.title}</div>
            <label className="job-apply-label" htmlFor="job-apply-msg">{t('job_apply_intro')}</label>
            <textarea
              id="job-apply-msg"
              className="seed-input job-apply-text"
              value={applyMsg}
              onChange={e => setApplyMsg(e.target.value)}
              rows={4}
            />
            <div className="job-contact-actions">
              <button type="button" className="seed-btn seed-btn-carrot seed-btn-block" disabled={busy} onClick={submitApply}>
                {busy ? t('waiting') : t('job_apply_send')}
              </button>
              <button type="button" className="seed-btn seed-btn-outline seed-btn-block" onClick={() => setApplyOpen(false)}>
                {t('cancel')}
              </button>
            </div>
          </div>
        </div>
      )}
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
  const { user, logout, isAuthed, refreshUser } = useAuth();
  const { push, resetTo } = useStack();
  const { t, lang, setLang } = useLang();
  const [stats, setStats] = useState({ active_listings: 0, sold_listings: 0, favorites_count: 0 });
  const [locOpen, setLocOpen] = useState(false);
  const [openingPortal, setOpeningPortal] = useState(false);
  const [staff, setStaff] = useState<{
    id: number;
    nickname: string;
    district: string;
    sector?: string;
    role_id: number;
    role_label: string;
    account_status: string;
    admin_district?: string;
    is_you?: boolean;
  }[]>([]);
  const [staffLoading, setStaffLoading] = useState(false);

  useEffect(() => {
    if (!isAuthed) return;
    void refreshUser();
    api.profile().then(d => setStats(d.user)).catch(() => {});
  }, [isAuthed]);

  useEffect(() => {
    if (!isAuthed || !user || !isStaffUser(user)) return;
    setStaffLoading(true);
    api.staffDirectory()
      .then(d => setStaff(d.staff || []))
      .catch(() => setStaff([]))
      .finally(() => setStaffLoading(false));
  }, [isAuthed, user?.id, user?.role_id]);

  const openManagementPortal = async () => {
    setOpeningPortal(true);
    try {
      const portal = await api.openStaffPortal();
      window.location.href = portal.redirect || '/gugu-app/admin/dashboard.php';
    } catch {
      window.location.href = '/gugu-app/admin/dashboard.php';
    } finally {
      setOpeningPortal(false);
    }
  };

  const cycleLang = () => {
    const order: Lang[] = ['rw', 'en', 'fr'];
    const next = order[(order.indexOf(lang) + 1) % order.length];
    setLang(next);
    toast(LANG_META[next].label, 'success');
  };

  if (!isAuthed || !user) {
    return (
      <>
        <div className="stack-content mygugu-page">
          <header className="mygugu-top">
            <h1>{t('my_gugu')}</h1>
          </header>
          <div className="mygugu-pad">
            <p className="mygugu-hint">{t('login_first')}</p>
            <button type="button" className="seed-btn seed-btn-carrot seed-btn-block" onClick={() => resetTo('auth')}>
              {t('login')}
            </button>
            <button type="button" className="seed-btn seed-btn-outline seed-btn-block" style={{ marginTop: 10 }} onClick={() => resetTo('items')}>
              {t('nav_items')}
            </button>
          </div>
        </div>
        <MiniNav active="profile" />
      </>
    );
  }

  const display = user.nickname || user.full_name || BRAND_NAME;
  const place = [user.sector, user.district].filter(Boolean).join(', ');
  const trust = formatTrustScore(user.manner_score);
  const management = isStaffUser(user);

  type MenuRow = { icon: string; label: string; onClick: () => void; danger?: boolean; sub?: string; busy?: boolean };

  const buyingSelling: MenuRow[] = [
    { icon: '🏷️', label: t('my_listings'), onClick: () => push('dashboard'), sub: String(stats.active_listings || 0) },
    { icon: '🛍️', label: t('my_purchases'), onClick: () => resetTo('chat') },
    { icon: '📷', label: t('gugu_vision'), onClick: () => push('sell') },
    { icon: '📖', label: t('gugu_guide'), onClick: () => resetTo('neighborhood') },
  ];

  const favouritesMenu: MenuRow[] = [
    { icon: '♡', label: t('favorites_title'), onClick: () => push('favorites'), sub: String(stats.favorites_count || 0) },
    { icon: '🏷️', label: t('search_alerts'), onClick: () => resetTo('items') },
  ];

  const activityMenu: MenuRow[] = [
    { icon: '💬', label: t('messages'), onClick: () => resetTo('chat') },
    { icon: '📝', label: t('my_community_posts'), onClick: () => resetTo('neighborhood') },
  ];

  const businessMenu: MenuRow[] = management
    ? [
        { icon: '🏪', label: t('manage_business'), onClick: () => { void openManagementPortal(); }, busy: openingPortal, sub: openingPortal ? t('waiting') : roleLabel(user.role_id) },
        { icon: '📢', label: t('advertising'), onClick: () => push('dashboard') },
        { icon: '📊', label: t('open_dashboard'), onClick: () => push('dashboard') },
      ]
    : [
        { icon: '🏪', label: t('manage_business'), onClick: () => push('dashboard') },
        { icon: '📢', label: t('advertising'), onClick: () => push('sell') },
      ];

  const settingsMenu: MenuRow[] = [
    { icon: '📍', label: t('manage_neighbourhood'), onClick: () => setLocOpen(true), sub: place || undefined },
    {
      icon: '◎',
      label: t('verify_location'),
      onClick: () => setLocOpen(true),
      sub: user.location_ok
        ? (user.location_days_left != null ? `${user.location_days_left}d` : t('gps_ok'))
        : t('gps_tap_verify'),
    },
    { icon: '🌐', label: t('language'), onClick: cycleLang, sub: LANG_META[lang].short },
    { icon: '⚙', label: t('settings_label'), onClick: () => push('dashboard') },
  ];

  const contactMenu: MenuRow[] = [
    { icon: '📢', label: t('whats_new'), onClick: () => toast(t('whats_new_soon'), 'success') },
    { icon: '🎧', label: t('faqs'), onClick: () => toast(t('faqs_soon'), 'success') },
    { icon: '✉️', label: t('feedback'), onClick: () => toast(t('feedback_soon'), 'success') },
    { icon: '🚪', label: t('delete_account'), onClick: () => toast(t('delete_account_hint'), 'error'), danger: true },
    { icon: 'ℹ', label: t('about_gugu'), onClick: () => toast(`${BRAND_NAME} · Buy or Sell · Rwanda`, 'success') },
    { icon: 'ℹ', label: t('terms_policies'), onClick: () => toast(t('terms_soon'), 'success') },
    { icon: '↩', label: t('logout'), onClick: () => { logout(); resetTo('items'); }, danger: true },
  ];

  const services = getGuguServices(t);

  const MenuCard = ({ title, rows }: { title: string; rows: MenuRow[] }) => (
    <section className="mygugu-card">
      <h3 className="mygugu-card-title">{title}</h3>
      <div className="mygugu-menu">
        {rows.map(row => (
          <button
            key={row.label}
            type="button"
            className={`mygugu-row${row.danger ? ' danger' : ''}`}
            onClick={row.onClick}
            disabled={Boolean(row.busy)}
          >
            <span className="mygugu-row-ico">{row.icon}</span>
            <span className="mygugu-row-label">{row.label}</span>
            {row.sub != null && row.sub !== '' && <span className="mygugu-row-sub">{row.sub}</span>}
            <span className="mygugu-row-chev">›</span>
          </button>
        ))}
      </div>
    </section>
  );

  return (
    <>
      <div className="stack-content mygugu-page">
        <header className="mygugu-top">
          <h1>{t('my_gugu')}</h1>
          <div className="mygugu-top-actions">
            <button type="button" className="mygugu-gear" aria-label={t('gugu_settings')} onClick={() => push('dashboard')}>
              ⚙
            </button>
          </div>
        </header>

        <button type="button" className="mygugu-profile-card mygugu-profile-btn" onClick={() => setLocOpen(true)}>
          <div className="mygugu-avatar">{display[0]?.toUpperCase() || 'G'}</div>
          <div className="mygugu-profile-text">
            <div className="mygugu-name-row">
              <h2>{display}</h2>
              <span className="mygugu-temp">{trust}</span>
            </div>
            {place ? <p>{place}</p> : null}
            <p style={{ marginTop: 4, fontSize: '0.75rem', opacity: 0.75 }}>
              {user.location_ok
                ? `📍 ${t('gps_ok')}${user.location_days_left != null ? ` · ${user.location_days_left}d` : ''}`
                : `📍 ${t('gps_tap_verify')}`}
            </p>
          </div>
          <span className="mygugu-row-chev">›</span>
        </button>

        {!user.location_ok && (
          <section className="mygugu-promo">
            <div className="mygugu-promo-brand">📍 {t('verify_location')}</div>
            <p>{t('gps_hint')}</p>
            <button type="button" className="mygugu-promo-btn" onClick={() => setLocOpen(true)}>
              {t('gps_allow')}
            </button>
          </section>
        )}

        <section className="mygugu-promo">
          <div className="mygugu-promo-brand">🇷🇼 {BRAND_NAME}</div>
          <p>{t('safer_trade')}</p>
          <button type="button" className="mygugu-promo-btn" onClick={() => push('sell')}>
            {t('safer_trade_cta')}
          </button>
        </section>

        <section className="mygugu-card">
          <button type="button" className="mygugu-card-head mygugu-card-head-btn" onClick={() => push('services')}>
            <h3 className="mygugu-card-title">{t('services_section')}</h3>
            <span className="mygugu-row-chev">›</span>
          </button>
          <div className="mygugu-services">
            {services.map(s => (
              <button key={s.labelKey} type="button" className="mygugu-service" onClick={() => s.run({ resetTo, push })}>
                <span className={`mygugu-service-ico ${s.tone}`}>{s.ico}</span>
                <span>{t(s.labelKey)}</span>
              </button>
            ))}
          </div>
        </section>

        <div className="mygugu-quick">
          <button type="button" onClick={() => push('favorites')}>
            <span>♡</span>{t('favourites_section')}
          </button>
          <button type="button" onClick={() => push('recent')}>
            <span>◷</span>{t('recently_viewed')}
          </button>
          <button type="button" onClick={() => push('benefits')}>
            <span>◆</span>{t('benefits')}
          </button>
        </div>

        <MenuCard title={t('buying_selling')} rows={buyingSelling} />
        <MenuCard title={t('favourites_section')} rows={favouritesMenu} />
        <MenuCard title={t('activity_section')} rows={activityMenu} />
        <MenuCard title={t('business_section')} rows={businessMenu} />

        {management && (
          <section className="mygugu-card">
            <h3 className="mygugu-card-title">{t('management_team')}</h3>
            <div className="mygugu-menu mygugu-staff-list">
              {staffLoading && <div className="mygugu-empty">{t('waiting')}</div>}
              {!staffLoading && staff.length === 0 && (
                <div className="mygugu-empty">No management users found</div>
              )}
              {!staffLoading && staff.map(s => (
                <div key={s.id} className={`mygugu-staff-row${s.is_you ? ' is-you' : ''}`}>
                  <div className={`mygugu-staff-avatar role-${s.role_id}`}>{(s.nickname || '?')[0]}</div>
                  <div className="mygugu-staff-body">
                    <div className="mygugu-staff-name">
                      {s.nickname || 'Staff'}
                      {s.is_you && <span className="mygugu-you">{t('you_badge')}</span>}
                    </div>
                    <div className="mygugu-staff-role">{s.role_label}</div>
                    <div className="mygugu-staff-place">
                      {s.role_id === 2 && s.admin_district ? `Region · ${s.admin_district}` : (s.district || '—')}
                      {' · '}
                      {s.account_status}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </section>
        )}

        <MenuCard title={t('settings_section')} rows={settingsMenu} />
        <MenuCard title={t('contact_us')} rows={contactMenu} />
      </div>
      <MiniNav active="profile" />
      <LocationSheet
        open={locOpen}
        onClose={() => setLocOpen(false)}
        onSaved={({ district, sector }) => {
          syncHomeLocationFilter(district, sector);
          void refreshUser();
        }}
      />
    </>
  );
}

export function NeighborhoodPage() {
  const { push } = useStack();
  const { isAuthed, user, refreshUser } = useAuth();
  const { t } = useLang();
  const [locOpen, setLocOpen] = useState(false);
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
        {isAuthed && (
          <div style={{ background: 'white', borderRadius: 12, padding: 16, marginBottom: 12, border: '1px solid var(--seed-gray-200)' }}>
            <div style={{ fontWeight: 600, marginBottom: 6 }}>
              📍 {user?.location_ok ? t('gps_ok') : t('verify_location')}
              {user?.location_ok && user.location_days_left != null ? ` · ${user.location_days_left}d` : ''}
            </div>
            <p style={{ fontSize: '0.8125rem', color: 'var(--seed-gray-600)', marginBottom: 10 }}>
              {[user?.sector, user?.district].filter(Boolean).join(', ') || t('set_neighbourhood')}
            </p>
            <button type="button" className="seed-btn seed-btn-carrot seed-btn-block" onClick={() => setLocOpen(true)}>
              {user?.location_ok ? t('reverify_gps') : t('gps_allow')}
            </button>
          </div>
        )}
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
      <LocationSheet
        open={locOpen}
        onClose={() => setLocOpen(false)}
        onSaved={({ district, sector }) => {
          syncHomeLocationFilter(district, sector);
          void refreshUser();
        }}
      />
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
