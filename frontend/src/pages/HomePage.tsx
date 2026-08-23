import { useEffect, useState, useCallback } from 'react';
import { useStack } from '../stack/Stackflow';
import { useAuth, toast } from '../components/AuthContext';
import { api, Listing, Category, CATEGORY_ICONS, AUTH_HOME_EVENT, isMemberUser, identityTitle } from '../api/client';
import { BottomNav } from '../components/BottomNav';
import { IdentityBadge } from '../components/IdentityBadge';
import { LocationSheet } from '../components/LocationSheet';
import { RWANDA_PROVINCES, sectorsForDistrict } from '../data/rwanda';
import { useLang, LanguageSwitcher } from '../i18n/LanguageContext';
import { BRAND_NAME } from '../i18n/translations';
import { consumeHomeFilter } from '../data/services';
import { syncHomeLocationFilter } from '../data/geo';

type SortKey = 'recent' | 'price_low' | 'price_high';

/** Full Browse listings category strip (All + items + Jobs) */
const HOME_CATEGORIES_FALLBACK: Category[] = [
  { id: 1, name_rw: 'Byose', name_en: 'All', icon: 'home' },
  { id: 2, name_rw: 'Telefoni', name_en: 'Electronics', icon: 'phone' },
  { id: 3, name_rw: 'Imbaho', name_en: 'Furniture', icon: 'couch' },
  { id: 4, name_rw: 'Imyambaro', name_en: 'Fashion', icon: 'shirt' },
  { id: 5, name_rw: 'Imodoka', name_en: 'Vehicles', icon: 'car' },
  { id: 6, name_rw: 'Inzu', name_en: 'Real Estate', icon: 'house' },
  { id: 7, name_rw: 'Imikino', name_en: 'Sports', icon: 'ball' },
  { id: 8, name_rw: 'Ibiryo', name_en: 'Food', icon: 'food' },
  { id: 9, name_rw: 'Ibikoresho', name_en: 'Appliances', icon: 'plug' },
  { id: 10, name_rw: 'Ibindi', name_en: 'Others', icon: 'box' },
  { id: 11, name_rw: 'Akazi', name_en: 'Jobs', icon: 'job' },
];

function withAllHomeCategories(list: Category[]): Category[] {
  const byId = new Map<number, Category>();
  for (const c of HOME_CATEGORIES_FALLBACK) byId.set(c.id, c);
  for (const c of list) {
    const id = Number(c.id);
    if (!Number.isFinite(id)) continue;
    byId.set(id, { ...c, id });
  }
  if (!byId.has(11)) byId.set(11, HOME_CATEGORIES_FALLBACK[HOME_CATEGORIES_FALLBACK.length - 1]);
  return [...byId.values()].sort((a, b) => a.id - b.id);
}

export default function HomePage() {
  const { push, resetTo } = useStack();
  const { user, isAuthed } = useAuth();
  const { t, price, timeAgo, category, province } = useLang();
  const [listings, setListings] = useState<Listing[]>([]);
  const [categories, setCategories] = useState<Category[]>(HOME_CATEGORIES_FALLBACK);
  const [cat, setCat] = useState(0);
  const memberLocked = isAuthed && isMemberUser(user);
  // Members: locked to confirmed stay district. Guests/staff: optional filter.
  const [district, setDistrict] = useState(() => {
    if (typeof localStorage === 'undefined') return '';
    return localStorage.getItem('gugu_district') || '';
  });
  const [sector, setSector] = useState(() => {
    if (typeof localStorage === 'undefined') return '';
    return localStorage.getItem('gugu_sector') || '';
  });
  const [search, setSearch] = useState('');
  const [sort, setSort] = useState<SortKey>('recent');
  const [loading, setLoading] = useState(true);
  const [totalCount, setTotalCount] = useState(0);
  const [refreshKey, setRefreshKey] = useState(0);
  const [locOpen, setLocOpen] = useState(false);
  const [filterTitle, setFilterTitle] = useState('');

  const applyStayDistrict = useCallback((d: string, s = '') => {
    setDistrict(d);
    setSector(s);
    syncHomeLocationFilter(d, s);
    setRefreshKey(k => k + 1);
  }, []);

  // Keep member feed tied to profile stay district
  useEffect(() => {
    if (!memberLocked) return;
    const d = user?.district || '';
    const s = user?.sector || '';
    if (d) applyStayDistrict(d, s);
    else {
      setDistrict('');
      setSector('');
      setLocOpen(true);
    }
  }, [memberLocked, user?.district, user?.sector, applyStayDistrict]);

  // Prompt GPS verify when location is missing or expired (once per session)
  useEffect(() => {
    if (!isAuthed || !user?.needs_location || user?.is_staff) return;
    try {
      if (sessionStorage.getItem('gugu_loc_prompted') === '1') return;
      sessionStorage.setItem('gugu_loc_prompted', '1');
    } catch { /* ignore */ }
    setLocOpen(true);
  }, [isAuthed, user?.needs_location, user?.is_staff]);

  const applyHomeFilter = useCallback(() => {
    const filter = consumeHomeFilter();
    if (!filter) return;
    if (filter.category != null) setCat(filter.category);
    if (filter.search != null) setSearch(filter.search);
    if (filter.title) setFilterTitle(filter.title);
    if (filter.nearMe && !memberLocked) {
      const d = user?.district || localStorage.getItem('gugu_district') || '';
      const s = user?.sector || localStorage.getItem('gugu_sector') || '';
      if (d) applyStayDistrict(d, s);
    }
    setRefreshKey(k => k + 1);
  }, [user?.district, user?.sector, memberLocked, applyStayDistrict]);

  useEffect(() => {
    applyHomeFilter();
    const onFilter = () => applyHomeFilter();
    window.addEventListener('gugu:home-filter', onFilter);
    return () => window.removeEventListener('gugu:home-filter', onFilter);
  }, [applyHomeFilter]);

  useEffect(() => {
    const onLoc = (e: Event) => {
      const detail = (e as CustomEvent<{ district?: string; sector?: string }>).detail || {};
      if (detail.district) {
        setDistrict(detail.district);
        setSector(detail.sector || '');
        setRefreshKey(k => k + 1);
      }
    };
    window.addEventListener('gugu:location-updated', onLoc);
    return () => window.removeEventListener('gugu:location-updated', onLoc);
  }, []);

  const goSell = () => {
    if (!isAuthed) {
      sessionStorage.setItem('gugu_after_login', 'sell');
      toast(t('login_to_sell'), 'error');
      resetTo('auth');
    } else if (memberLocked && !user?.district) {
      toast(t('set_neighbourhood'), 'error');
      setLocOpen(true);
    } else {
      push('sell');
    }
  };

  const goMe = () => {
    if (!isAuthed) {
      toast(t('login_first'), 'error');
      resetTo('auth');
      return;
    }
    resetTo('profile');
  };

  useEffect(() => {
    const onAuthHome = () => {
      setCat(0);
      setSearch('');
      if (memberLocked && user?.district) {
        applyStayDistrict(user.district, user.sector || '');
      } else if (!memberLocked) {
        // Guests/staff may browse nationwide after login redirect
        localStorage.removeItem('gugu_district');
        localStorage.removeItem('gugu_sector');
        setDistrict('');
        setSector('');
        setRefreshKey(k => k + 1);
      }
    };
    if (sessionStorage.getItem('gugu_show_all_items')) {
      sessionStorage.removeItem('gugu_show_all_items');
      onAuthHome();
    }
    window.addEventListener(AUTH_HOME_EVENT, onAuthHome);
    return () => window.removeEventListener(AUTH_HOME_EVENT, onAuthHome);
  }, [memberLocked, user?.district, user?.sector, applyStayDistrict]);

  useEffect(() => {
    api.categories()
      .then(d => setCategories(withAllHomeCategories(d.categories || [])))
      .catch(() => setCategories(HOME_CATEGORIES_FALLBACK));
  }, []);

  const loadListings = useCallback(() => {
    setLoading(true);
    const params: Record<string, string> = { sort, limit: '50' };
    if (cat && cat !== 11) params.category = String(cat);
    if (district) params.district = district;
    if (sector) params.sector = sector;
    if (search.trim()) params.search = search.trim();
    api.listings(params)
      .then(d => {
        setListings(d.listings || []);
        setTotalCount(d.pagination?.total ?? d.listings?.length ?? 0);
      })
      .catch((err) => {
        console.error('Listings error:', err);
        setListings([]);
        setTotalCount(0);
      })
      .finally(() => setLoading(false));
  }, [cat, district, sector, search, sort, refreshKey]);

  useEffect(() => {
    const tmr = setTimeout(loadListings, search ? 300 : 0);
    return () => clearTimeout(tmr);
  }, [loadListings, isAuthed]);

  const sortTabs: { key: SortKey; label: string }[] = [
    { key: 'recent', label: t('sort_recent') },
    { key: 'price_low', label: t('sort_price_low') },
    { key: 'price_high', label: t('sort_price_high') },
  ];

  const catName = (c: Category) => category(c.name_rw, c.name_en);
  const sectorList = district ? sectorsForDistrict(district) : [];
  const placeLabel = [sector, district].filter(Boolean).join(', ') || t('set_neighbourhood');

  return (
    <>
      <div className="stack-content market-page">
        <header className="market-header">
          <div className="market-header-row">
            <button className="market-logo" onClick={() => { setCat(0); setSearch(''); }}>
              <span className="market-logo-icon">🇷🇼</span>
              <span className="market-logo-text">{BRAND_NAME}</span>
            </button>
            {memberLocked ? (
              <button
                type="button"
                className="market-location"
                onClick={() => setLocOpen(true)}
                title={t('change_stay_district')}
              >
                <span className="market-location-label">📍</span>
                <span className="market-location-select" style={{ pointerEvents: 'none' }}>
                  {placeLabel}
                </span>
                <span className={`market-gps-dot${user?.location_ok ? ' ok' : ''}`} title={user?.location_ok ? t('gps_ok') : t('gps_tap_verify')} />
              </button>
            ) : (
              <button
                type="button"
                className="market-location"
                onClick={() => {
                  if (isAuthed) setLocOpen(true);
                  else document.getElementById('district-select')?.focus();
                }}
              >
                <span className="market-location-label">📍</span>
                <select
                  id="district-select"
                  className="market-location-select"
                  value={district}
                  onChange={e => {
                    const d = e.target.value;
                    setDistrict(d);
                    setSector('');
                    if (d) localStorage.setItem('gugu_district', d);
                    else localStorage.removeItem('gugu_district');
                    localStorage.removeItem('gugu_sector');
                  }}
                  onClick={e => e.stopPropagation()}
                >
                  <option value="">{t('all_locations')}</option>
                  {Object.entries(RWANDA_PROVINCES).map(([prov, districts]) => (
                    <optgroup key={prov} label={province(prov)}>
                      {districts.map(d => (
                        <option key={d} value={d}>{d}</option>
                      ))}
                    </optgroup>
                  ))}
                </select>
                {isAuthed && (
                  <span className={`market-gps-dot${user?.location_ok ? ' ok' : ''}`} title={user?.location_ok ? t('gps_ok') : t('gps_tap_verify')} />
                )}
              </button>
            )}
            <div className="market-header-tools">
              <LanguageSwitcher compact />
              {isAuthed ? (
                <button type="button" className="market-profile-btn" onClick={goMe} aria-label={t('nav_profile')}>
                  {identityTitle(user)?.[0] || user?.nickname?.[0] || user?.full_name?.[0] || '👤'}
                </button>
              ) : (
                <button type="button" className="market-login-btn" onClick={() => resetTo('auth')}>{t('login')}</button>
              )}
            </div>
          </div>
          {isAuthed && user && (
            <div style={{ padding: '0 4px 8px' }}>
              <IdentityBadge user={user} />
            </div>
          )}
          {memberLocked && (
            <p className="market-stay-hint" style={{ margin: '0 4px 10px', fontSize: 12, color: 'var(--seed-gray-600)' }}>
              {user?.location_ok ? t('stay_district_hint') : t('verify_stay_gps')}
              {' '}
              <button type="button" className="seed-link" style={{ fontSize: 12 }} onClick={() => setLocOpen(true)}>
                {t('change_stay_district')}
              </button>
            </p>
          )}

          {district && sectorList.length > 0 && (
            <div className="market-search-wrap" style={{ marginBottom: 10 }}>
              <span className="market-search-icon">🏘️</span>
              <select
                className="market-search"
                value={sector}
                onChange={e => {
                  const s = e.target.value;
                  setSector(s);
                  if (s) localStorage.setItem('gugu_sector', s);
                  else localStorage.removeItem('gugu_sector');
                }}
                style={{ cursor: 'pointer', fontWeight: 600 }}
              >
                <option value="">{t('all_sectors')} · {district}</option>
                {sectorList.map(s => (
                  <option key={s} value={s}>{s}</option>
                ))}
              </select>
            </div>
          )}

          <div className="market-search-wrap">
            <span className="market-search-icon">🔍</span>
            <input
              className="market-search"
              placeholder={t('search_placeholder')}
              value={search}
              onChange={e => setSearch(e.target.value)}
            />
          </div>
        </header>

        {isAuthed && user ? (
          <div className="market-welcome">
            <span>👋 {t('welcome')}, <strong>{identityTitle(user) || user?.nickname || user?.full_name?.split(' ')[0]}</strong>!</span>
            <span className="market-welcome-sub">{t('welcome_sub')}</span>
          </div>
        ) : (
          <button
            type="button"
            className="market-welcome market-welcome-guest"
            onClick={() => resetTo('auth')}
          >
            <span>{t('guest_browse')}</span>
            <span className="market-welcome-sub">{t('welcome_sub')}</span>
          </button>
        )}

        <div className="market-categories" role="listbox" aria-label={t('nav_items')}>
          {categories.map(c => {
            const isJobs = Number(c.id) === 11;
            const selected = !isJobs && (cat === c.id || (cat === 0 && c.id === 1));
            return (
              <button
                key={c.id}
                type="button"
                role="option"
                aria-selected={selected}
                className={`market-cat-pill${selected ? ' active' : ''}${isJobs ? ' market-cat-jobs' : ''}`}
                onClick={() => {
                  if (isJobs) {
                    if (!isAuthed) {
                      sessionStorage.setItem('gugu_after_login', 'jobs');
                      toast(t('login_first'), 'error');
                      resetTo('auth');
                      return;
                    }
                    push('jobs');
                    return;
                  }
                  if (c.id === 1) setCat(0);
                  else setCat(cat === c.id ? 0 : c.id);
                }}
              >
                {isJobs ? (
                  <span className="market-jobs-mark" aria-hidden>
                    <span className="market-jobs-mark-bar b1" />
                    <span className="market-jobs-mark-bar b2" />
                    <span className="market-jobs-mark-bar b3" />
                    <span className="market-jobs-mark-bag">💼</span>
                  </span>
                ) : (
                  <span className="market-cat-icon">{CATEGORY_ICONS[c.icon] || '📦'}</span>
                )}
                <span className="market-cat-label">{isJobs ? t('jobs_home_pill') : catName(c)}</span>
              </button>
            );
          })}
        </div>

        <section className="market-jobs-banner" aria-label={t('available_jobs')}>
          <div className="market-jobs-banner-brand">
            <div className="market-jobs-logo" aria-hidden>
              <span className="market-jobs-logo-bars">
                <i style={{ background: '#00A1DE' }} />
                <i style={{ background: '#FAD201' }} />
                <i style={{ background: '#20603D' }} />
              </span>
              <strong>{BRAND_NAME}</strong>
              <em>{t('jobs_brand_em')}</em>
            </div>
            <div className="market-jobs-banner-copy">
              <h2>{t('jobs_home_badge')}</h2>
              <p>{t('jobs_home_sub')}</p>
            </div>
          </div>
          <div className="market-jobs-banner-actions">
            <button
              type="button"
              className="market-jobs-btn primary"
              onClick={() => {
                if (!isAuthed) {
                  sessionStorage.setItem('gugu_after_login', 'jobs');
                  toast(t('login_first'), 'error');
                  resetTo('auth');
                  return;
                }
                push('jobs');
              }}
            >
              {t('jobs_home_browse')}
            </button>
            <button
              type="button"
              className="market-jobs-btn secondary"
              onClick={() => {
                if (!isAuthed) {
                  sessionStorage.setItem('gugu_after_login', 'post-job');
                  toast(t('login_first'), 'error');
                  resetTo('auth');
                  return;
                }
                push('post-job');
              }}
            >
              {t('jobs_home_announce')}
            </button>
          </div>
        </section>

        <div className="market-sort-bar">
          {sortTabs.map(tab => (
            <button
              key={tab.key}
              className={`market-sort-tab${sort === tab.key ? ' active' : ''}`}
              onClick={() => setSort(tab.key)}
            >
              {tab.label}
            </button>
          ))}
          <span className="market-count">{totalCount} {t('items_count')}</span>
        </div>

        <h2 className="market-section-title">
          {filterTitle
            ? filterTitle
            : `${sector ? `${sector}, ${district}` : (district || 'Rwanda')} · ${t('section_items')}`}
          {filterTitle && (
            <button
              type="button"
              className="market-clear-filter"
              onClick={() => {
                setFilterTitle('');
                setCat(0);
                setSearch('');
                setRefreshKey(k => k + 1);
              }}
            >
              ✕
            </button>
          )}
        </h2>

        {loading ? (
          <div className="market-loading">
            <div className="market-spinner" />
            <p>{t('loading_items')}</p>
          </div>
        ) : listings.length === 0 ? (
          <div className="market-empty">
            <div className="market-empty-icon">📦</div>
            <p>{t('empty_title')}</p>
            <p className="market-empty-sub">{t('empty_sub')}</p>
            <button className="seed-btn seed-btn-carrot" onClick={goSell}>{t('post_item')}</button>
          </div>
        ) : (
          <div className="product-grid market-grid">
            {listings.map(l => (
              <article
                key={l.id}
                className="product-card market-card"
                onClick={() => push('detail', { id: l.id })}
              >
                <div className={`card-img market-card-img${l.status === 'sold' ? ' is-sold' : ''}`}>
                  {l.primary_image ? (
                    <img src={l.primary_image} alt={l.title} loading="lazy" />
                  ) : (
                    <div className="market-card-placeholder">📦</div>
                  )}
                  {l.status === 'sold' && (
                    <span className="sold-badge-card">{t('sold_badge')}</span>
                  )}
                  {(l.like_count ?? 0) > 0 && (
                    <span className="market-card-likes">❤️ {l.like_count}</span>
                  )}
                </div>
                <div className="market-card-body">
                  <div className="card-title">{l.title}</div>
                  <div className={`card-price market-price${l.is_free ? ' free' : ''}`}>
                    {price(l.price, l.is_free)}
                  </div>
                  <div className="card-meta">
                    {l.sector ? `${l.sector}, ${l.district}` : l.district} · {timeAgo(l.created_at) || l.time_ago}
                  </div>
                  {(l.category_name_rw || l.category_name_en || l.category_name) && (
                    <div className="market-card-cat">
                      {CATEGORY_ICONS[l.category_icon || 'box']} {category(l.category_name_rw, l.category_name_en, l.category_name)}
                    </div>
                  )}
                </div>
              </article>
            ))}
          </div>
        )}
      </div>
      <BottomNav />
      <LocationSheet
        open={locOpen}
        onClose={() => setLocOpen(false)}
        onSaved={({ district: d, sector: s }) => applyStayDistrict(d, s)}
      />
    </>
  );
}
