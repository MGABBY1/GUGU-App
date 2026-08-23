import { useEffect, useState } from 'react';
import { useAuth, toast } from '../components/AuthContext';
import { useStack } from '../stack/Stackflow';
import { Header } from '../components/BottomNav';
import { hasApprovedId, needsIdUpload } from '../api/client';
import { useLang } from '../i18n/LanguageContext';
import { RWANDA_PROVINCES, sectorsForDistrict, provinceForDistrict } from '../data/rwanda';

/** My account — phone, email, nickname, location, ID verification (from Settings). */
export default function AccountPage() {
  const { user, isAuthed, refreshUser, updateProfile } = useAuth();
  const { push, pop, canGoBack, resetTo } = useStack();
  const { t } = useLang();

  const [nickname, setNickname] = useState('');
  const [realName, setRealName] = useState('');
  const [email, setEmail] = useState('');
  const [district, setDistrict] = useState('Gasabo');
  const [sector, setSector] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!isAuthed) {
      toast(t('login_first'), 'error');
      resetTo('auth');
      return;
    }
    void refreshUser();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isAuthed]);

  useEffect(() => {
    if (!user) return;
    setNickname(user.nickname || user.display_name || '');
    setRealName(user.full_name || '');
    setEmail(user.email || '');
    const d = user.district || 'Gasabo';
    setDistrict(d);
    const sectors = sectorsForDistrict(d);
    const s = user.sector || '';
    setSector(s && sectors.includes(s) ? s : (sectors[0] || ''));
  }, [user]);

  if (!user) {
    return (
      <>
        <Header title={t('settings_my_account')} back />
        <div className="stack-content ks-page">
          <p className="ks-empty">{t('login_first')}</p>
        </div>
      </>
    );
  }

  const sectorOptions = sectorsForDistrict(district);
  const idSt = user.id_status || 'none';
  const idValue =
    idSt === 'approved' ? t('id_status_approved')
      : idSt === 'pending' ? t('id_status_pending')
        : idSt === 'rejected' ? t('id_status_rejected')
          : t('id_status_none');

  const idHint =
    idSt === 'approved' ? t('account_id_approved_hint')
      : idSt === 'pending' ? t('id_pending_wait')
        : idSt === 'rejected'
          ? `${t('id_rejected')}: ${user.id_reject_reason || t('id_resubmit')}`
          : t('id_verify_hint');

  const idCta =
    idSt === 'approved' ? t('account_id_view')
      : idSt === 'pending' ? t('account_id_cta_pending')
        : idSt === 'rejected' ? t('id_resubmit')
          : t('id_submit');

  const openId = () => {
    sessionStorage.setItem('gugu_auth_step', 'id');
    push('auth');
  };

  const copyPhone = async () => {
    const phone = user.phone || '';
    if (!phone) return;
    try {
      await navigator.clipboard.writeText(phone);
      toast(t('job_phone_copied'), 'success');
    } catch {
      toast(phone, 'success');
    }
  };

  const onDistrictChange = (next: string) => {
    setDistrict(next);
    const secs = sectorsForDistrict(next);
    setSector(prev => (secs.includes(prev) ? prev : (secs[0] || '')));
  };

  const saveProfile = async (e: React.FormEvent) => {
    e.preventDefault();
    const nick = nickname.trim();
    if (nick.length < 2) {
      toast(t('nickname'), 'error');
      return;
    }
    if (email.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) {
      toast(t('email_backup'), 'error');
      return;
    }
    if (!district) {
      toast(t('district'), 'error');
      return;
    }
    if (!sector) {
      toast(t('fill_sector'), 'error');
      return;
    }
    setSaving(true);
    try {
      await updateProfile({
        nickname: nick,
        real_name: realName.trim(),
        email: email.trim(),
        province: provinceForDistrict(district),
        district,
        sector,
      });
      try {
        localStorage.setItem('gugu_district', district);
        localStorage.setItem('gugu_sector', sector);
      } catch {
        /* ignore */
      }
      toast(t('profile_saved'), 'success');
      await refreshUser();
    } catch (err) {
      toast((err as Error).message, 'error');
    } finally {
      setSaving(false);
    }
  };

  const goBack = () => (canGoBack ? pop() : resetTo('settings'));

  return (
    <>
      <Header title={t('settings_my_account')} back />
      <div className="stack-content ks-page">
        <p className="ks-account-intro">{t('settings_my_account_hint')}</p>

        <section className="ks-group">
          <h3 className="ks-group-title">{t('account_contact_section')}</h3>
          <div className="ks-list">
            <div className="ks-row ks-row-static">
              <span className="ks-ico" aria-hidden>📱</span>
              <span className="ks-text">
                <span className="ks-label">{t('phone')}</span>
                <span className="ks-hint">{t('account_phone_hint')}</span>
              </span>
              <span className="ks-value">{user.phone || '—'}</span>
            </div>
            <button type="button" className="ks-row" onClick={() => void copyPhone()}>
              <span className="ks-ico" aria-hidden>📋</span>
              <span className="ks-text">
                <span className="ks-label">{t('job_copy_phone')}</span>
              </span>
              <span className="ks-chev" aria-hidden>›</span>
            </button>
          </div>
        </section>

        <section className="ks-group">
          <h3 className="ks-group-title">{t('account_profile_section')}</h3>
          <form className="ks-form" onSubmit={e => void saveProfile(e)}>
            <label className="ks-field">
              <span className="ks-field-label">{t('nickname')}</span>
              <input
                value={nickname}
                onChange={e => setNickname(e.target.value)}
                placeholder="Gabby"
                minLength={2}
                maxLength={50}
                required
              />
              <span className="ks-field-hint">{t('nickname_hint')}</span>
            </label>
            <label className="ks-field">
              <span className="ks-field-label">{t('real_name_private')}</span>
              <input
                value={realName}
                onChange={e => setRealName(e.target.value)}
                placeholder={t('real_name_ph')}
              />
            </label>
            <label className="ks-field">
              <span className="ks-field-label">{t('email_backup')}</span>
              <input
                type="email"
                value={email}
                onChange={e => setEmail(e.target.value)}
                placeholder="you@email.com"
              />
              <span className="ks-field-hint">{t('account_email_hint')}</span>
            </label>

            <h3 className="ks-group-title" style={{ marginTop: 8 }}>{t('account_location_section')}</h3>
            <p className="ks-field-hint" style={{ marginBottom: 10 }}>{t('account_location_hint')}</p>
            <label className="ks-field">
              <span className="ks-field-label">{t('district')} (Akarere)</span>
              <select value={district} onChange={e => onDistrictChange(e.target.value)} required>
                {Object.entries(RWANDA_PROVINCES).map(([prov, districts]) => (
                  <optgroup key={prov} label={prov}>
                    {districts.map(d => (
                      <option key={d} value={d}>{d}</option>
                    ))}
                  </optgroup>
                ))}
              </select>
            </label>
            <label className="ks-field">
              <span className="ks-field-label">{t('sector')}</span>
              {sectorOptions.length > 0 ? (
                <select value={sector} onChange={e => setSector(e.target.value)} required>
                  <option value="">{t('pick_sector')}</option>
                  {sectorOptions.map(s => (
                    <option key={s} value={s}>{s}</option>
                  ))}
                </select>
              ) : (
                <input value={sector} onChange={e => setSector(e.target.value)} placeholder={t('sector_ph')} required />
              )}
            </label>

            <button type="submit" className="ks-primary-btn" disabled={saving}>
              {saving ? t('waiting') : t('account_save')}
            </button>
          </form>
        </section>

        <section className="ks-group">
          <h3 className="ks-group-title">{t('account_identity_section')}</h3>
          <div className="ks-list">
            <div className="ks-row ks-row-static">
              <span className="ks-ico" aria-hidden>🪪</span>
              <span className="ks-text">
                <span className="ks-label">{t('id_verify_title')}</span>
                <span className="ks-hint">{idHint}</span>
              </span>
              <span className={`ks-value ks-id-${idSt}`}>{idValue}</span>
            </div>
            {user.id_number ? (
              <div className="ks-row ks-row-static">
                <span className="ks-ico" aria-hidden>#</span>
                <span className="ks-text">
                  <span className="ks-label">{t('id_number')}</span>
                </span>
                <span className="ks-value">{user.id_number}</span>
              </div>
            ) : null}
            <button
              type="button"
              className="ks-row"
              onClick={() => {
                if (hasApprovedId(user) && !needsIdUpload(user)) {
                  toast(t('account_id_approved_hint'), 'success');
                  return;
                }
                openId();
              }}
            >
              <span className="ks-ico" aria-hidden>⬆</span>
              <span className="ks-text">
                <span className="ks-label">{idCta}</span>
                <span className="ks-hint">
                  {hasApprovedId(user) ? t('account_id_approved_hint') : t('settings_id_hint')}
                </span>
              </span>
              <span className="ks-chev" aria-hidden>›</span>
            </button>
          </div>
        </section>

        <button type="button" className="ks-back-link" onClick={goBack}>
          ← {t('settings_label')}
        </button>
      </div>
    </>
  );
}
