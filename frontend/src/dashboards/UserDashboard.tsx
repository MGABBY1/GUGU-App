import { useEffect, useState } from 'react';
import { api, User } from '../api/client';
import { useStack } from '../stack/Stackflow';
import { formatTrustScore } from '../i18n/format';
import { useLang } from '../i18n/LanguageContext';

/** In-app dashboard for Members — mirrors My GUGU quick links */
export default function UserDashboard({ user }: { user: User }) {
  const { push, resetTo } = useStack();
  const { t } = useLang();
  const [stats, setStats] = useState({ active_listings: 0, sold_listings: 0, favorites_count: 0 });

  useEffect(() => {
    api.profile()
      .then(d => setStats({
        active_listings: d.user.active_listings || 0,
        sold_listings: d.user.sold_listings || 0,
        favorites_count: d.user.favorites_count || 0,
      }))
      .catch(() => {});
  }, []);

  const trust = formatTrustScore(user.manner_score);

  return (
    <div className="dash-body">
      <div className="dash-banner dash-banner-user">
        <div>
          <div className="dash-banner-role">{t('my_gugu')}</div>
          <h2>{user.nickname || user.display_name || 'Member'}</h2>
          <p>{user.sector ? `${user.sector}, ` : ''}{user.district} · ⭐ {trust}</p>
        </div>
      </div>

      <div className="mygugu-stats" style={{ borderRadius: 12, overflow: 'hidden', marginBottom: 14 }}>
        <div><strong>{stats.active_listings}</strong><span>{t('for_sale')}</span></div>
        <div><strong>{stats.sold_listings}</strong><span>{t('sold')}</span></div>
        <div><strong>{stats.favorites_count}</strong><span>{t('favorites_title')}</span></div>
      </div>

      <section className="mygugu-section" style={{ margin: 0 }}>
        <h3 className="mygugu-section-title">{t('gugu_settings')}</h3>
        <div className="mygugu-menu" style={{ borderRadius: 12, overflow: 'hidden' }}>
          <button type="button" className="mygugu-row" onClick={() => resetTo('profile')}>
            <span className="mygugu-row-ico">⚙️</span>
            <span className="mygugu-row-label">{t('my_gugu')}</span>
            <span className="mygugu-row-chev">›</span>
          </button>
          <button type="button" className="mygugu-row" onClick={() => push('sell')}>
            <span className="mygugu-row-ico">➕</span>
            <span className="mygugu-row-label">{t('sell_title')}</span>
            <span className="mygugu-row-chev">›</span>
          </button>
          <button type="button" className="mygugu-row" onClick={() => push('favorites')}>
            <span className="mygugu-row-ico">❤️</span>
            <span className="mygugu-row-label">{t('favorites_title')}</span>
            <span className="mygugu-row-chev">›</span>
          </button>
          <button type="button" className="mygugu-row" onClick={() => resetTo('chat')}>
            <span className="mygugu-row-ico">💬</span>
            <span className="mygugu-row-label">{t('messages')}</span>
            <span className="mygugu-row-chev">›</span>
          </button>
        </div>
      </section>
    </div>
  );
}
