import { useEffect, useState } from 'react';
import { useStack } from '../stack/Stackflow';
import { useAuth } from '../components/AuthContext';
import { Header } from '../components/BottomNav';
import { LocationSheet } from '../components/LocationSheet';
import { useLang } from '../i18n/LanguageContext';
import { formatTrustScore } from '../i18n/format';
import { setHomeFilter, getRecentViews, clearRecentViews, RecentItem } from '../data/services';
import type { TranslationKey } from '../i18n/translations';
import { BRAND_NAME } from '../i18n/translations';

/** Category ids from schema */
export const CAT = {
  all: 0,
  electronics: 2,
  furniture: 3,
  fashion: 4,
  vehicles: 5,
  property: 6,
  sports: 7,
  food: 8,
  appliances: 9,
  others: 10,
  jobs: 11,
} as const;

export type ServiceDef = {
  ico: string;
  labelKey: TranslationKey;
  tone: string;
  hintKey: TranslationKey;
  run: (nav: { resetTo: (n: string) => void; push: (n: string, p?: Record<string, unknown>) => void }) => void;
};

export function getGuguServices(t: (k: TranslationKey) => string): ServiceDef[] {
  return [
    {
      ico: '?��',
      labelKey: 'svc_items',
      tone: 'c-orange',
      hintKey: 'svc_items_hint',
      run: ({ resetTo }) => {
        setHomeFilter({ category: 0, search: '', title: t('svc_items') });
        resetTo('items');
      },
    },
    {
      ico: '?��',
      labelKey: 'part_time_jobs',
      tone: 'c-orange',
      hintKey: 'svc_jobs_hint',
      run: ({ push }) => push('jobs'),
    },
    {
      ico: '?��',
      labelKey: 'property',
      tone: 'c-pink',
      hintKey: 'svc_property_hint',
      run: ({ resetTo }) => {
        setHomeFilter({ category: CAT.property, title: t('property') });
        resetTo('items');
      },
    },
    {
      ico: '?��',
      labelKey: 'used_cars',
      tone: 'c-blue',
      hintKey: 'svc_cars_hint',
      run: ({ resetTo }) => {
        setHomeFilter({ category: CAT.vehicles, title: t('used_cars') });
        resetTo('items');
      },
    },
    {
      ico: '?��',
      labelKey: 'store',
      tone: 'c-yellow',
      hintKey: 'svc_store_hint',
      run: ({ push }) => push('dashboard'),
    },
    {
      ico: '?��',
      labelKey: 'neighborhood_walks',
      tone: 'c-orange',
      hintKey: 'svc_neighborhood_hint',
      run: ({ resetTo }) => resetTo('neighborhood'),
    },
    {
      ico: '?��',
      labelKey: 'laundry_pickup',
      tone: 'c-teal',
      hintKey: 'svc_laundry_hint',
      run: ({ resetTo }) => {
        setHomeFilter({
          category: CAT.fashion,
          search: 'laundry',
          title: t('laundry_pickup'),
          nearMe: true,
        });
        resetTo('items');
      },
    },
    {
      ico: '?��?��',
      labelKey: 'svc_gugu_home',
      tone: 'c-green',
      hintKey: 'svc_gugu_hint',
      run: ({ resetTo }) => {
        setHomeFilter({ category: 0, search: '', title: BRAND_NAME, nearMe: true });
        resetTo('items');
      },
    },
  ];
}

export function ServicesHubPage() {
  const { resetTo, push } = useStack();
  const { t } = useLang();
  const services = getGuguServices(t);

  return (
    <>
      <Header title={t('services_section')} back />
      <div className="stack-content svc-hub">
        <p className="svc-hub-intro">{t('svc_hub_intro')}</p>
        <div className="svc-hub-list">
          {services.map(s => (
            <button
              key={s.labelKey}
              type="button"
              className="svc-hub-row"
              onClick={() => s.run({ resetTo, push })}
            >
              <span className={`mygugu-service-ico ${s.tone}`}>{s.ico}</span>
              <div className="svc-hub-text">
                <strong>{t(s.labelKey)}</strong>
                <span>{t(s.hintKey)}</span>
              </div>
              <span className="mygugu-row-chev">›</span>
            </button>
          ))}
        </div>
      </div>
    </>
  );
}

export function RecentlyViewedPage() {
  const { push, resetTo } = useStack();
  const { t, price } = useLang();
  const [items, setItems] = useState<RecentItem[]>([]);

  useEffect(() => {
    setItems(getRecentViews());
  }, []);

  return (
    <>
      <Header title={t('recently_viewed')} back />
      <div className="stack-content">
        {items.length === 0 ? (
          <div className="chat-empty">
            <div className="chat-empty-ico">📦</div>
            <h2>{t('recent_empty')}</h2>
            <p>{t('recent_empty_hint')}</p>
            <button type="button" className="seed-btn seed-btn-carrot" onClick={() => resetTo('items')}>
              {t('browse_nearby')}
            </button>
          </div>
        ) : (
          <>
            <div className="svc-recent-tools">
              <button
                type="button"
                className="seed-btn seed-btn-outline"
                onClick={() => {
                  clearRecentViews();
                  setItems([]);
                }}
              >
                {t('clear_recent')}
              </button>
            </div>
            <div className="product-grid">
              {items.map(l => (
                <article key={l.id} className="product-card" onClick={() => push('detail', { id: l.id })}>
                  <div className="card-img">
                    {l.primary_image
                      ? <img src={l.primary_image} alt="" />
                      : (
                        <div style={{ width: '100%', height: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: '2rem' }}>
                          ?��
                        </div>
                      )}
                  </div>
                  <div className="card-title">{l.title}</div>
                  <div className={`card-price${l.is_free ? ' free' : ''}`}>
                    {l.price_formatted || price(l.price, !!l.is_free)}
                  </div>
                  <div className="card-meta">
                    {l.sector ? `${l.sector}, ${l.district}` : (l.district || 'Rwanda')}
                  </div>
                </article>
              ))}
            </div>
          </>
        )}
      </div>
    </>
  );
}

export function BenefitsPage() {
  const { push, resetTo } = useStack();
  const { user, isAuthed } = useAuth();
  const { t } = useLang();
  const [locOpen, setLocOpen] = useState(false);
  const trust = formatTrustScore(user?.manner_score);
  const place = [user?.sector, user?.district].filter(Boolean).join(', ');

  const perks = [
    { ico: '⭐', title: t('benefit_trust'), desc: t('benefit_trust_desc') },
    { ico: '📍', title: t('benefit_gps'), desc: t('benefit_gps_desc') },
    { ico: '💬', title: t('benefit_chat'), desc: t('benefit_chat_desc') },
    { ico: '🛡️', title: t('benefit_safe'), desc: t('benefit_safe_desc') },
  ];

  return (
    <>
      <Header title={t('benefits')} back />
      <div className="stack-content svc-benefits">
        <div className="svc-benefits-hero">
          <div className="svc-benefits-temp">{trust}</div>
          <h2>{user?.nickname || BRAND_NAME}</h2>
          <p>{place || t('set_neighbourhood')}</p>
          {user?.location_ok
            ? <span className="svc-pill ok">{t('gps_ok')}</span>
            : <span className="svc-pill">{t('gps_tap_verify')}</span>}
        </div>

        {perks.map(p => (
          <div key={p.title} className="svc-perk">
            <span className="svc-perk-ico">{p.ico}</span>
            <div>
              <strong>{p.title}</strong>
              <p>{p.desc}</p>
            </div>
          </div>
        ))}

        {isAuthed ? (
          <button type="button" className="seed-btn seed-btn-carrot seed-btn-block" onClick={() => setLocOpen(true)}>
            {t('verify_location')}
          </button>
        ) : (
          <button type="button" className="seed-btn seed-btn-carrot seed-btn-block" onClick={() => resetTo('auth')}>
            {t('login')}
          </button>
        )}
        <button type="button" className="seed-btn seed-btn-outline seed-btn-block" style={{ marginTop: 10 }} onClick={() => push('sell')}>
          {t('start_selling')}
        </button>
        <button type="button" className="seed-btn seed-btn-outline seed-btn-block" style={{ marginTop: 10 }} onClick={() => push('favorites')}>
          {t('favourites_section')}
        </button>
      </div>
      <LocationSheet open={locOpen} onClose={() => setLocOpen(false)} />
    </>
  );
}
