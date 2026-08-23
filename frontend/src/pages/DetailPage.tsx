import { useEffect, useState, useCallback, useRef } from 'react';
import { useStack } from '../stack/Stackflow';
import {
  api,
  ListingDetail,
  ChatRoom,
  Message,
  CATEGORY_ICONS,
  isStaffUser,
  getPortalView,
  portalReturnUrl,
  identityTitle,
  identityPlace,
  displayRoleId,
} from '../api/client';
import { useAuth, toast } from '../components/AuthContext';
import { Header } from '../components/BottomNav';
import { useLang, LanguageSwitcher } from '../i18n/LanguageContext';
import { formatTrustScore } from '../i18n/format';
import { LANG_META, BRAND_NAME, roleTranslationKey, type Lang } from '../i18n/translations';
import { getGuguServices } from './ServicePages';
import { LocationSheet } from '../components/LocationSheet';
import { syncHomeLocationFilter } from '../data/geo';
import { trackRecentView, JOBS_CATEGORY_ID } from '../data/services';
import { CHAT_EMOJI_CATEGORIES } from '../data/chatEmojis';

export default function DetailPage({ id }: { id?: number }) {
  const listingId = id!;
  const { push, resetTo, replace } = useStack();
  const { isAuthed } = useAuth();
  const { t, price, timeAgo, category } = useLang();
  const [l, setL] = useState<ListingDetail | null>(null);
  const [busy, setBusy] = useState(false);
  const [saved, setSaved] = useState(false);
  const [contactOpen, setContactOpen] = useState(false);
  const [applyOpen, setApplyOpen] = useState(false);
  const [applyMsg, setApplyMsg] = useState('');
  const [managing, setManaging] = useState(false);

  const reloadListing = () => {
    api.listing(listingId)
      .then(d => {
        setL(d.listing);
        setSaved(!!d.listing.is_favorited);
      })
      .catch(() => {});
  };

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
  const isSold = l.status === 'sold';
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
    // Replace listing/detail layer so chat is a clean top page (no page peeking behind)
    setTimeout(() => replace('chat-room', { roomId: Number(roomId) }), 50);
  };

  const markSold = async () => {
    if (!window.confirm(t('mark_sold_confirm'))) return;
    setManaging(true);
    try {
      const res = await api.updateListing(listingId, { status: 'sold' });
      toast(res.message || t('marked_sold'), 'success');
      reloadListing();
    } catch (err) {
      toast((err as Error).message, 'error');
    } finally {
      setManaging(false);
    }
  };

  const relistItem = async () => {
    setManaging(true);
    try {
      await api.updateListing(listingId, { status: 'active' });
      toast(t('relist_for_sale'), 'success');
      reloadListing();
    } catch (err) {
      toast((err as Error).message, 'error');
    } finally {
      setManaging(false);
    }
  };

  const deletePost = async () => {
    if (!window.confirm(t('delete_post_confirm'))) return;
    setManaging(true);
    try {
      const res = await api.deleteListing(listingId);
      toast(res.message || t('post_deleted'), 'success');
      resetTo('my-listings');
    } catch (err) {
      toast((err as Error).message, 'error');
      setManaging(false);
    }
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
      await api.sendMessage(d.room_id, msg);
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
        <div className={`detail-media-wrap${isSold ? ' is-sold' : ''}`}>
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
          {isSold && !isJob && <div className="sold-stamp" aria-hidden="true">{t('sold_badge')}</div>}
        </div>

        <div className="detail-body">
          <div className="detail-price-row">
            <div className={`detail-price${l.is_free ? ' free' : ''}${isSold ? ' is-sold' : ''}`}>
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

          {isSold && !isJob && (
            <div className="post-track-banner sold">{t('sold_out_banner')}</div>
          )}

          {!isSold && isSeller && (() => {
            const mod = l.moderation_status || 'approved';
            if (mod === 'pending' || mod === 'flagged') {
              return (
                <div className="post-track-banner waiting">
                  {t('post_status_waiting')} — {l.payment_status === 'paid' ? t('post_hint_paid_waiting') : t('post_hint_waiting')}
                </div>
              );
            }
            if (mod === 'rejected') {
              return <div className="post-track-banner rejected">{t('post_status_rejected')} — {t('post_hint_rejected')}</div>;
            }
            if (mod === 'approved') {
              return <div className="post-track-banner live">{t('post_status_live')} — {t('post_hint_live')}</div>;
            }
            return null;
          })()}

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
          ) : isAuthed && !isJob && !isSold ? (
            <div className="gugu-deal-role gugu-deal-buyer">{t('you_can_buy')}</div>
          ) : null}

          <h3 className="detail-section-title">{t('description')}</h3>
          <p className="detail-desc">{l.description}</p>
        </div>
      </div>

      {!isSeller && !isJob && !isSold && (
        <div className="detail-cta-bar">
          <button type="button" className="detail-cta-chat" disabled={busy} onClick={startChat}>
            💬 {t('chat_seller')}
          </button>
          <button type="button" className="detail-cta-buy" disabled={busy} onClick={startChat}>
            {t('buy_now')}
          </button>
        </div>
      )}

      {!isSeller && !isJob && isSold && (
        <div className="detail-cta-bar">
          <button type="button" className="detail-cta-buy" disabled>
            {t('sold_badge')}
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

      {isSeller && !isJob && (
        <div className="detail-owner-actions">
          {!isSold && (l.moderation_status === 'approved' || !l.moderation_status) && (
            <button type="button" className="seed-btn seed-btn-carrot seed-btn-block" disabled={managing} onClick={() => { void markSold(); }}>
              {t('mark_as_sold')}
            </button>
          )}
          {isSold && (
            <button type="button" className="seed-btn seed-btn-outline seed-btn-block" disabled={managing} onClick={() => { void relistItem(); }}>
              {t('relist_for_sale')}
            </button>
          )}
          <button type="button" className="seed-btn seed-btn-outline seed-btn-block detail-btn-danger" disabled={managing} onClick={() => { void deletePost(); }}>
            {t('delete_post')}
          </button>
          <button type="button" className="gugu-login-link" onClick={() => push('my-listings')}>
            {t('my_posts_title')} ›
          </button>
        </div>
      )}

      {isSeller && isJob && (
        <div className="detail-cta-bar">
          <button type="button" className="detail-cta-buy" onClick={() => resetTo('jobs')}>
            {t('available_jobs')}
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
  const { isAuthed } = useAuth();
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
          <span className="ico" style={{ position: 'relative' }}>
            {tab.ico}
            {tab.name === 'chat' && unread > 0 && (
              <span className="nav-unread-dot">{unread > 99 ? '99+' : unread}</span>
            )}
          </span>
          <span>{tab.label}</span>
        </button>
      ))}
    </nav>
  );
}

export function ProfilePage() {
  const { user, logout, isAuthed, refreshUser } = useAuth();
  const { push, resetTo } = useStack();
  const { t, lang, setLang } = useLang();
  const [stats, setStats] = useState({
    active_listings: 0,
    sold_listings: 0,
    favorites_count: 0,
    pending_listings: 0,
  });
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
    api.profile().then(d => setStats({
      active_listings: d.user.active_listings || 0,
      sold_listings: d.user.sold_listings || 0,
      favorites_count: d.user.favorites_count || 0,
      pending_listings: d.user.pending_listings || 0,
    })).catch(() => {});
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
    const portalView = getPortalView();
    if (portalView || user?.portal_view_active) {
      window.location.href = portalReturnUrl(portalView);
      return;
    }
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

  const display = identityTitle(user);
  const place = identityPlace(user);
  const trust = formatTrustScore(user.manner_score);
  const management = isStaffUser(user);

  type MenuRow = { icon: string; label: string; onClick: () => void; danger?: boolean; sub?: string; busy?: boolean };

  const buyingSelling: MenuRow[] = [
    {
      icon: '🏷️',
      label: t('my_listings'),
      onClick: () => push('my-listings'),
      sub: stats.pending_listings
        ? `${stats.active_listings} · ${stats.pending_listings} ${t('post_filter_waiting')}`
        : String(stats.active_listings || 0),
    },
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
        { icon: '🏪', label: t('manage_business'), onClick: () => { void openManagementPortal(); }, busy: openingPortal, sub: openingPortal ? t('waiting') : t(roleTranslationKey(displayRoleId(user))) },
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
    { icon: '⚙', label: t('settings_label'), onClick: () => push('settings') },
  ];

  const contactMenu: MenuRow[] = [
    { icon: '📢', label: t('whats_new'), onClick: () => toast(t('whats_new_soon'), 'success') },
    { icon: '🎧', label: t('faqs'), onClick: () => toast(t('faqs_soon'), 'success') },
    { icon: '✉️', label: t('feedback'), onClick: () => toast(t('feedback_soon'), 'success') },
    { icon: '🚪', label: t('delete_account'), onClick: () => toast(t('delete_account_hint'), 'error'), danger: true },
    { icon: 'ℹ', label: t('about_gugu'), onClick: () => toast(t('about_brand_tagline'), 'success') },
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
            <button type="button" className="mygugu-gear" aria-label={t('gugu_settings')} onClick={() => push('settings')}>
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
                <div className="mygugu-empty">{t('no_management_users')}</div>
              )}
              {!staffLoading && staff.map(s => (
                <div key={s.id} className={`mygugu-staff-row${s.is_you ? ' is-you' : ''}`}>
                  <div className={`mygugu-staff-avatar role-${s.role_id}`}>{(s.nickname || '?')[0]}</div>
                  <div className="mygugu-staff-body">
                    <div className="mygugu-staff-name">
                      {s.nickname || t('role_staff')}
                      {s.is_you && <span className="mygugu-you">{t('you_badge')}</span>}
                    </div>
                    <div className="mygugu-staff-role">{s.role_label}</div>
                    <div className="mygugu-staff-place">
                      {s.role_id === 2 && s.admin_district ? t('region_label', { district: s.admin_district }) : (s.district || '—')}
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
  const { t, timeAgo } = useLang();
  const [rooms, setRooms] = useState<ChatRoom[]>([]);
  const [loading, setLoading] = useState(true);

  const dealLabel = (r: ChatRoom) => {
    if (r.is_job) {
      return r.my_deal_role === 'seller' ? t('chat_role_poster') : t('chat_role_applicant');
    }
    return r.my_deal_role === 'seller' ? t('chat_role_seller') : t('chat_role_buyer');
  };

  const loadRooms = useCallback(() => {
    if (!isAuthed) return;
    api.chatRooms()
      .then(d => setRooms(d.rooms || []))
      .catch(() => setRooms([]))
      .finally(() => setLoading(false));
  }, [isAuthed]);

  useEffect(() => {
    if (!isAuthed) {
      push('auth');
      return;
    }
    loadRooms();
    const timer = window.setInterval(loadRooms, 8000);
    const onFocus = () => loadRooms();
    window.addEventListener('focus', onFocus);
    document.addEventListener('visibilitychange', onFocus);
    return () => {
      window.clearInterval(timer);
      window.removeEventListener('focus', onFocus);
      document.removeEventListener('visibilitychange', onFocus);
    };
  }, [isAuthed, loadRooms, push]);

  return (
    <>
      <header className="seed-header chat-top"><h1>{t('messages')}</h1></header>
      <div className="stack-content chat-page">
        {loading ? (
          <div className="chat-loading">{t('loading')}</div>
        ) : rooms.length === 0 ? (
          <div className="chat-empty">
            <div className="chat-empty-ico">💬</div>
            <h2>{t('no_messages')}</h2>
            <p>{t('chat_empty_hint')}</p>
            <button type="button" className="seed-btn seed-btn-carrot" onClick={() => push('items')}>
              {t('browse_to_chat')}
            </button>
          </div>
        ) : (
          rooms.map(r => {
            const unread = Number(r.unread_count || 0);
            const when = timeAgo(r.last_message_at || r.created_at) || r.time_ago || '';
            return (
              <button
                key={r.id}
                type="button"
                className={`chat-row${unread > 0 ? ' unread' : ''}`}
                onClick={() => push('chat-room', { roomId: r.id })}
              >
                <div className="chat-thumb">
                  {r.listing_image
                    ? <img src={r.listing_image} alt="" />
                    : <span>{r.is_job ? '💼' : '💬'}</span>}
                </div>
                <div className="chat-body">
                  <div className="chat-row-top">
                    <span className="chat-name">{r.other_name}</span>
                    <span className="chat-time">{when}</span>
                  </div>
                  <div className="chat-listing">
                    {r.is_job ? t('chat_job_thread') : dealLabel(r)}
                    {r.listing_title ? ` · ${r.listing_title}` : ''}
                  </div>
                  <div className="chat-meta">
                    <span className="chat-preview">{r.last_message || r.listing_title}</span>
                    {unread > 0 && <span className="chat-badge">{unread > 99 ? '99+' : unread}</span>}
                  </div>
                </div>
                <span className="chat-chev">›</span>
              </button>
            );
          })
        )}
      </div>
      <MiniNav active="chat" />
    </>
  );
}

export function ChatRoomPage({ roomId }: { roomId?: number }) {
  const { t, timeAgo } = useLang();
  const { push, replace, pop, canGoBack } = useStack();
  const [messages, setMessages] = useState<Message[]>([]);
  const [room, setRoom] = useState<ChatRoom | null>(null);
  const [text, setText] = useState('');
  const [sending, setSending] = useState(false);
  const [emojiOpen, setEmojiOpen] = useState(false);
  const [emojiCat, setEmojiCat] = useState(0);
  const bubblesRef = useRef<HTMLDivElement | null>(null);
  const bottomRef = useRef<HTMLDivElement | null>(null);
  const inputRef = useRef<HTMLInputElement | null>(null);
  const composerRef = useRef<HTMLDivElement | null>(null);

  const scrollToBottom = useCallback(() => {
    requestAnimationFrame(() => {
      bottomRef.current?.scrollIntoView({ behavior: 'smooth', block: 'end' });
    });
  }, []);

  const loadMessages = useCallback(async (opts?: { quiet?: boolean }) => {
    if (!roomId) return;
    try {
      const d = await api.messages(roomId);
      setMessages(d.messages || []);
      if (d.room) setRoom(d.room as ChatRoom);
      if (!opts?.quiet) scrollToBottom();
    } catch {
      /* keep existing thread on blip */
    }
  }, [roomId, scrollToBottom]);

  useEffect(() => {
    if (!roomId) {
      replace('chat');
      return;
    }
    void loadMessages();
    const timer = window.setInterval(() => { void loadMessages({ quiet: true }); }, 3500);
    const onFocus = () => { void loadMessages({ quiet: true }); };
    window.addEventListener('focus', onFocus);
    document.addEventListener('visibilitychange', onFocus);
    return () => {
      window.clearInterval(timer);
      window.removeEventListener('focus', onFocus);
      document.removeEventListener('visibilitychange', onFocus);
    };
  }, [roomId, loadMessages, replace]);

  useEffect(() => {
    scrollToBottom();
  }, [messages.length, scrollToBottom]);

  useEffect(() => {
    if (!emojiOpen) return;
    const onDoc = (e: MouseEvent) => {
      const el = composerRef.current;
      if (el && !el.contains(e.target as Node)) setEmojiOpen(false);
    };
    document.addEventListener('mousedown', onDoc);
    return () => document.removeEventListener('mousedown', onDoc);
  }, [emojiOpen]);

  const goBack = () => {
    if (canGoBack) pop();
    else replace('chat');
  };

  const insertEmoji = (emoji: string) => {
    const input = inputRef.current;
    const start = input?.selectionStart ?? text.length;
    const end = input?.selectionEnd ?? text.length;
    const next = text.slice(0, start) + emoji + text.slice(end);
    setText(next);
    requestAnimationFrame(() => {
      if (!input) return;
      input.focus();
      const pos = start + emoji.length;
      input.setSelectionRange(pos, pos);
    });
  };

  const send = async () => {
    const body = text.trim();
    if (!body || !roomId || sending) return;
    setSending(true);
    setEmojiOpen(false);
    try {
      const d = await api.sendMessage(roomId, body);
      setText('');
      if (d.message) {
        setMessages(prev => {
          if (prev.some(m => m.id === d.message.id)) return prev;
          return [...prev, d.message];
        });
      } else {
        await loadMessages();
      }
      scrollToBottom();
    } catch (err) {
      toast((err as Error).message, 'error');
    } finally {
      setSending(false);
    }
  };

  const title = room?.other_name || t('messages');
  const sub = room?.is_job
    ? `${t('chat_job_thread')}${room.listing_title ? ` · ${room.listing_title}` : ''}`
    : (room?.listing_title || t('chat_waiting_reply'));
  const activeEmojis = CHAT_EMOJI_CATEGORIES[emojiCat]?.emojis || CHAT_EMOJI_CATEGORIES[0].emojis;

  return (
    <div className="chat-room-shell">
      <header className="seed-header chat-room-header">
        <button type="button" className="seed-back" onClick={goBack} aria-label="Back">←</button>
        <div className="chat-room-heading">
          <h1>{title}</h1>
          {room && <p>{sub}</p>}
        </div>
      </header>
      <div className="stack-content chat-room-page">
        {room && (
          <button
            type="button"
            className="chat-room-item"
            onClick={() => room.listing_id && push('detail', { id: room.listing_id })}
          >
            <div className="chat-room-item-thumb">
              {room.listing_image
                ? <img src={room.listing_image} alt="" />
                : <span>{room.is_job ? '💼' : '📦'}</span>}
            </div>
            <div className="chat-room-item-copy">
              <div className="chat-room-item-title">{room.listing_title || sub}</div>
              {room.price_formatted && !room.is_job && (
                <div className="chat-room-item-price">{room.price_formatted}</div>
              )}
              {room.is_job && <div className="chat-room-item-tag">{t('chat_job_thread')}</div>}
            </div>
          </button>
        )}

        <div className="chat-bubbles" ref={bubblesRef} onClick={() => setEmojiOpen(false)}>
          {messages.length === 0 ? (
            <div className="chat-empty-inline">{t('chat_empty_thread')}</div>
          ) : (
            messages.map(m => {
              const seen = m.is_mine && Number(m.is_read) === 1;
              const when = timeAgo(m.created_at) || m.time_ago || '';
              return (
                <div key={m.id} className={`chat-bubble${m.is_mine ? ' mine' : ''}`}>
                  <div className="chat-bubble-text">{m.content}</div>
                  <div className="chat-bubble-time">
                    {when}
                    {m.is_mine && (
                      <span className={`chat-read-status${seen ? ' is-seen' : ''}`}>
                        {' · '}{seen ? t('chat_seen') : t('chat_sent')}
                      </span>
                    )}
                  </div>
                </div>
              );
            })
          )}
          <div ref={bottomRef} />
        </div>

        <div className="chat-composer-wrap" ref={composerRef}>
          {emojiOpen && (
            <div className="chat-emoji-panel" role="dialog" aria-label={t('chat_emoji')}>
              <div className="chat-emoji-cats">
                {CHAT_EMOJI_CATEGORIES.map((cat, i) => (
                  <button
                    key={cat.id}
                    type="button"
                    className={`chat-emoji-cat${i === emojiCat ? ' active' : ''}`}
                    onClick={() => setEmojiCat(i)}
                    aria-label={cat.id}
                  >
                    {cat.icon}
                  </button>
                ))}
              </div>
              <div className="chat-emoji-grid">
                {activeEmojis.map((emo, i) => (
                  <button
                    key={`${emojiCat}-${i}-${emo}`}
                    type="button"
                    className="chat-emoji-btn"
                    onClick={() => insertEmoji(emo)}
                  >
                    {emo}
                  </button>
                ))}
              </div>
            </div>
          )}
          <div className="chat-composer">
            <button
              type="button"
              className={`chat-emoji-toggle${emojiOpen ? ' open' : ''}`}
              onClick={() => setEmojiOpen(v => !v)}
              aria-label={t('chat_emoji')}
              aria-expanded={emojiOpen}
            >
              😊
            </button>
            <input
              ref={inputRef}
              className="seed-input chat-input"
              value={text}
              onChange={e => setText(e.target.value)}
              onFocus={() => setEmojiOpen(false)}
              onKeyDown={e => {
                if (e.key === 'Enter') {
                  e.preventDefault();
                  void send();
                }
              }}
              placeholder={t('type_message')}
            />
            <button type="button" className="chat-send" onClick={() => { void send(); }} disabled={sending || !text.trim()}>
              ➤
            </button>
          </div>
        </div>
      </div>
    </div>
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
