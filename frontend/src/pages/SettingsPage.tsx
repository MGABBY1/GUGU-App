import { useEffect, useState } from 'react';
import { useAuth, toast } from '../components/AuthContext';
import { useStack } from '../stack/Stackflow';
import { Header } from '../components/BottomNav';
import { hasApprovedId, needsIdUpload } from '../api/client';
import { useLang } from '../i18n/LanguageContext';
import { LANG_META, BRAND_NAME, type Lang } from '../i18n/translations';
import { LocationSheet } from '../components/LocationSheet';
import { syncHomeLocationFilter } from '../data/geo';

type SettingsRow = {
  icon: string;
  label: string;
  hint?: string;
  value?: string;
  onClick: () => void;
  danger?: boolean;
  chevron?: boolean;
};

function SettingsGroup({ title, rows }: { title: string; rows: SettingsRow[] }) {
  return (
    <section className="ks-group">
      <h3 className="ks-group-title">{title}</h3>
      <div className="ks-list">
        {rows.map(row => (
          <button
            key={row.label}
            type="button"
            className={`ks-row${row.danger ? ' is-danger' : ''}`}
            onClick={row.onClick}
          >
            <span className="ks-ico" aria-hidden>{row.icon}</span>
            <span className="ks-text">
              <span className="ks-label">{row.label}</span>
              {row.hint ? <span className="ks-hint">{row.hint}</span> : null}
            </span>
            {row.value ? <span className="ks-value">{row.value}</span> : null}
            {row.chevron !== false && <span className="ks-chev" aria-hidden>›</span>}
          </button>
        ))}
      </div>
    </section>
  );
}

/** Karrot-style Settings for GUGU members (opened from My account gear). */
export default function SettingsPage() {
  const { user, logout, isAuthed, refreshUser } = useAuth();
  const { push, resetTo, pop, canGoBack } = useStack();
  const { t, lang, setLang } = useLang();
  const [locOpen, setLocOpen] = useState(false);

  useEffect(() => {
    if (!isAuthed) {
      toast(t('login_first'), 'error');
      resetTo('auth');
      return;
    }
    void refreshUser();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isAuthed]);

  if (!user) {
    return (
      <>
        <Header title={t('settings_label')} back />
        <div className="stack-content ks-page">
          <p className="ks-empty">{t('login_first')}</p>
        </div>
      </>
    );
  }

  const place = [user.sector, user.district].filter(Boolean).join(', ') || t('manage_neighbourhood');
  const phone = user.phone || '—';
  const idSt = user.id_status || 'none';
  const idValue =
    idSt === 'approved' ? t('id_status_approved')
      : idSt === 'pending' ? t('id_status_pending')
        : idSt === 'rejected' ? t('id_status_rejected')
          : t('id_status_none');

  const cycleLang = () => {
    const order: Lang[] = ['rw', 'en', 'fr'];
    const next = order[(order.indexOf(lang) + 1) % order.length];
    setLang(next);
    toast(LANG_META[next].label, 'success');
  };

  const openId = () => {
    sessionStorage.setItem('gugu_auth_step', 'id');
    push('auth');
  };

  const accountRows: SettingsRow[] = [
    {
      icon: '👤',
      label: t('settings_my_account'),
      hint: t('settings_my_account_hint'),
      onClick: () => push('account'),
    },
    {
      icon: '🪪',
      label: t('id_verify_title'),
      hint: t('settings_id_hint'),
      value: idValue,
      onClick: openId,
    },
    {
      icon: '📍',
      label: t('verify_location'),
      hint: place,
      value: user.location_ok ? t('gps_ok') : t('gps_tap_verify'),
      onClick: () => setLocOpen(true),
    },
    {
      icon: '🏘️',
      label: t('manage_neighbourhood'),
      hint: t('settings_neighbourhood_hint'),
      value: user.district || undefined,
      onClick: () => setLocOpen(true),
    },
  ];

  const tradeRows: SettingsRow[] = [
    {
      icon: '🏷️',
      label: t('my_listings'),
      hint: t('settings_listings_hint'),
      onClick: () => push('my-listings'),
    },
    {
      icon: '♡',
      label: t('favorites_title'),
      onClick: () => push('favorites'),
    },
    {
      icon: '💬',
      label: t('messages'),
      onClick: () => resetTo('chat'),
    },
    {
      icon: '➕',
      label: t('sell_title'),
      hint: hasApprovedId(user) ? t('settings_sell_ok') : t('id_required_to_sell'),
      onClick: () => {
        if (needsIdUpload(user) || !hasApprovedId(user)) {
          toast(t('id_required_to_sell'), 'error');
          openId();
          return;
        }
        push('sell');
      },
    },
  ];

  const appRows: SettingsRow[] = [
    {
      icon: '🔔',
      label: t('settings_notifications'),
      hint: t('settings_notifications_hint'),
      onClick: () => toast(t('settings_notifications_soon'), 'success'),
    },
    {
      icon: '🌐',
      label: t('language'),
      value: LANG_META[lang].label,
      onClick: cycleLang,
    },
  ];

  const otherRows: SettingsRow[] = [
    {
      icon: '✨',
      label: t('whats_new'),
      onClick: () => toast(t('whats_new_soon'), 'success'),
    },
    {
      icon: '❓',
      label: t('faqs'),
      onClick: () => toast(t('faqs_soon'), 'success'),
    },
    {
      icon: 'ℹ️',
      label: t('about_gugu'),
      hint: `${BRAND_NAME} · Rwanda`,
      onClick: () => toast(`${BRAND_NAME} · Gura & Gurisha`, 'success'),
    },
    {
      icon: '📄',
      label: t('terms_policies'),
      onClick: () => toast(t('terms_soon'), 'success'),
    },
    {
      icon: '🔢',
      label: t('settings_version'),
      value: '2.0.0',
      onClick: () => toast(`${BRAND_NAME} v2.0.0`, 'success'),
    },
  ];

  const dangerRows: SettingsRow[] = [
    {
      icon: '🚪',
      label: t('delete_account'),
      hint: t('delete_account_hint'),
      danger: true,
      onClick: () => toast(t('delete_account_hint'), 'error'),
    },
    {
      icon: '↩',
      label: t('logout'),
      danger: true,
      chevron: false,
      onClick: () => {
        logout();
        resetTo('items');
      },
    },
  ];

  return (
    <>
      <Header title={t('settings_label')} back />
      <div className="stack-content ks-page">
        <div className="ks-phone-line">
          <span className="ks-phone-label">{t('phone')}</span>
          <span className="ks-phone-value">{phone}</span>
        </div>

        <SettingsGroup title={t('account_settings')} rows={accountRows} />
        <SettingsGroup title={t('buying_selling')} rows={tradeRows} />
        <SettingsGroup title={t('settings_app_section')} rows={appRows} />
        <SettingsGroup title={t('other_settings')} rows={otherRows} />
        <SettingsGroup title={t('settings_account_actions')} rows={dangerRows} />

        <button
          type="button"
          className="ks-back-link"
          onClick={() => (canGoBack ? pop() : resetTo('profile'))}
        >
          ← {t('my_gugu')}
        </button>
      </div>

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
