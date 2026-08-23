import { useEffect, useState } from 'react';
import { useAuth, toast } from '../components/AuthContext';
import { useStack } from '../stack/Stackflow';
import { api, goToItemsPage, isStaffUser, needsIdUpload, User } from '../api/client';
import { RWANDA_PROVINCES, provinceForDistrict } from '../data/rwanda';
import { resolveRwandaLocation, sectorsForDistrict, GeoSuggestion, getBrowserPosition, gpsErrorKind, manualFromDistrict } from '../data/geo';
import { useLang, LanguageSwitcher } from '../i18n/LanguageContext';
import { BRAND_NAME, roleTranslationKey } from '../i18n/translations';
import { clearLoginQuery, readSavedStackScreen } from '../stack/Stackflow';

type Step = 'login' | 'otp' | 'profile' | 'location' | 'id';

function LoginBrand({ subtitle }: { subtitle: string }) {
  return (
    <div className="gugu-login-brand">
      <div className="gugu-login-flag">🇷🇼</div>
      <div className="gugu-login-logo">{BRAND_NAME}</div>
      <p className="gugu-login-sub">{subtitle}</p>
      <div className="gugu-login-bars" aria-hidden>
        <span style={{ background: '#00A1DE' }} />
        <span style={{ background: '#FAD201' }} />
        <span style={{ background: '#20603D' }} />
      </div>
    </div>
  );
}

export default function AuthPage() {
  const { login, loginWithOtp, completeProfile, verifyLocation, submitId, user, isAuthed, logout, refreshUser } = useAuth();
  const { push, resetTo } = useStack();
  const { t } = useLang();
  const [step, setStep] = useState<Step>('login');
  const [phone, setPhone] = useState('');
  const [otp, setOtp] = useState('');
  const [devOtp, setDevOtp] = useState<string | null>(null);
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [idNumber, setIdNumber] = useState('');
  const [idFile, setIdFile] = useState<File | null>(null);
  const [geo, setGeo] = useState<GeoSuggestion | null>(null);
  const [locDistrict, setLocDistrict] = useState('Gasabo');
  const [locSector, setLocSector] = useState('');
  const [profile, setProfile] = useState({
    nickname: '',
    real_name: '',
    email: '',
    province: 'Kigali',
    district: 'Gasabo',
    sector: '',
  });

  const finishHome = async (authedUser?: User | null) => {
    const after = sessionStorage.getItem('gugu_after_login');
    sessionStorage.removeItem('gugu_after_login');
    const u = authedUser || user || (() => {
      try { return JSON.parse(localStorage.getItem('gugu_user') || 'null') as User | null; }
      catch { return null; }
    })();

    // System routes by account: management → portal · member → marketplace
    if (isStaffUser(u)) {
      try {
        const portal = await api.openStaffPortal();
        toast(t('welcome_staff', { role: t(roleTranslationKey(u?.role_id)) }), 'success');
        window.location.href = portal.redirect || '/gugu-app/admin/dashboard.php';
      } catch {
        window.location.href = '/gugu-app/admin/dashboard.php';
      }
      return;
    }

    goToItemsPage();
    clearLoginQuery();
    if (after === 'sell') {
      resetTo('items');
      setTimeout(() => push('sell'), 150);
    } else if (after === 'post-job') {
      resetTo('jobs');
      setTimeout(() => push('post-job'), 150);
    } else if (after === 'jobs') {
      resetTo('jobs');
    } else if (after?.startsWith('apply:')) {
      const id = Number(after.split(':')[1]);
      resetTo('jobs');
      if (id) {
        setTimeout(() => {
          push('detail', { id });
          sessionStorage.setItem('gugu_open_apply', String(id));
        }, 150);
      }
    } else if (after?.startsWith('detail:')) {
      const id = Number(after.split(':')[1]);
      resetTo('items');
      if (id) setTimeout(() => push('detail', { id }), 150);
    } else {
      const saved = readSavedStackScreen();
      if (saved) resetTo(saved.name, saved.params);
      else resetTo('items');
    }
  };

  // Resume forced ID step after register, or when member still must upload ID
  useEffect(() => {
    if (!isAuthed || !user) return;
    const forced = sessionStorage.getItem('gugu_auth_step');
    if (forced === 'id') {
      sessionStorage.removeItem('gugu_auth_step');
      setStep('id');
      return;
    }
    if (step === 'login') {
      clearLoginQuery();
      if (needsIdUpload(user)) {
        setStep('id');
        return;
      }
      void finishHome(user);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isAuthed, user]);

  const afterAuth = async (opts: {
    needs_profile?: boolean;
    needs_location?: boolean;
    needs_id_upload?: boolean;
    needs_id_verification?: boolean;
    user?: User;
  }) => {
    if (isStaffUser(opts.user)) {
      sessionStorage.removeItem('gugu_after_login');
      await finishHome(opts.user);
      return;
    }
    if (opts.needs_profile) {
      setStep('profile');
      return;
    }
    if (opts.needs_location) {
      setStep('location');
      return;
    }
    // Security: every member must upload national ID before using the app
    if (opts.needs_id_upload || needsIdUpload(opts.user)) {
      setStep('id');
      return;
    }
    await finishHome(opts.user);
  };

  const submitIdForm = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!/^\d{12,16}$/.test(idNumber.replace(/\s+/g, ''))) {
      toast(t('id_number_invalid'), 'error');
      return;
    }
    if (!idFile && user?.id_status !== 'pending') {
      toast(t('id_photo_required'), 'error');
      return;
    }
    setLoading(true);
    try {
      const fd = new FormData();
      fd.append('id_number', idNumber.replace(/\s+/g, ''));
      if (idFile) fd.append('id_document', idFile);
      const res = await submitId(fd);
      toast(res.message || t('id_submitted'), 'success');
      await refreshUser();
      // Uploaded — Admin reviews next; member may browse while pending
      await finishHome(res.user);
    } catch (err) {
      toast((err as Error).message, 'error');
    } finally {
      setLoading(false);
    }
  };

  const sendOtp = async (e?: React.FormEvent) => {
    e?.preventDefault();
    if (!phone.trim()) {
      toast(t('phone'), 'error');
      return;
    }
    setLoading(true);
    try {
      const data = await api.sendOtp(phone);
      setDevOtp(data.dev_otp || null);
      if (data.dev_otp) setOtp(data.dev_otp);
      toast(data.dev_otp ? `${t('otp_sent_dev')}: ${data.dev_otp}` : t('otp_sent'), 'success');
      setStep('otp');
    } catch (err) {
      toast((err as Error).message, 'error');
    } finally {
      setLoading(false);
    }
  };

  const confirmOtp = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    try {
      const result = await loginWithOtp(phone, otp);
      toast(t('welcome_toast'), 'success');
      await afterAuth(result);
    } catch (err) {
      toast((err as Error).message, 'error');
    } finally {
      setLoading(false);
    }
  };

  /** One form: credentials in → system routes management or member */
  const submitLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!phone.trim()) {
      toast(t('phone'), 'error');
      return;
    }
    setLoading(true);
    try {
      if (isAuthed && !isStaffUser(user)) {
        logout();
      }
      if (password.trim()) {
        const result = await login(phone, password);
        toast(t('welcome_toast'), 'success');
        await afterAuth(result);
        return;
      }
      // No password → OTP (typical for members)
      const data = await api.sendOtp(phone);
      setDevOtp(data.dev_otp || null);
      if (data.dev_otp) setOtp(data.dev_otp);
      toast(data.dev_otp ? `${t('otp_sent_dev')}: ${data.dev_otp}` : t('otp_sent'), 'success');
      setStep('otp');
    } catch (err) {
      toast((err as Error).message, 'error');
    } finally {
      setLoading(false);
    }
  };

  const saveProfile = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    try {
      if (!localStorage.getItem('gugu_token')) {
        toast(t('login_first'), 'error');
        setStep('login');
        return;
      }
      await completeProfile(profile);
      toast(t('profile_saved'), 'success');
      setLocDistrict(profile.district);
      setStep('location');
    } catch (err) {
      toast((err as Error).message, 'error');
    } finally {
      setLoading(false);
    }
  };

  const openManualLocation = (district?: string) => {
    const d = district || profile.district || user?.district || 'Gasabo';
    const suggestion = manualFromDistrict(d);
    setGeo(suggestion);
    setLocDistrict(suggestion.district);
    setLocSector(suggestion.sector);
    toast(t('gps_manual_ok'), 'success');
  };

  const captureGps = async () => {
    setLoading(true);
    try {
      const pos = await getBrowserPosition();
      const suggestion = await resolveRwandaLocation(
        pos.coords.latitude,
        pos.coords.longitude,
        pos.coords.accuracy,
      );
      const district = suggestion.in_rwanda
        ? (suggestion.district || profile.district || user?.district || 'Gasabo')
        : (profile.district || user?.district || 'Gasabo');
      const sectors = sectorsForDistrict(district);
      const sector = suggestion.in_rwanda
        ? (suggestion.sector || sectors[0] || '')
        : (sectors[0] || '');
      setGeo({ ...suggestion, district, sector, province: provinceForDistrict(district) });
      setLocDistrict(district);
      setLocSector(sector);
      if (!suggestion.in_rwanda) toast(t('gps_outside_rwanda'), 'error');
      else toast(t('gps_detected'), 'success');
    } catch (err) {
      const kind = gpsErrorKind(err as GeolocationPositionError);
      if (kind === 'denied') toast(t('gps_permission'), 'error');
      else if (kind === 'timeout') toast(t('gps_timeout'), 'error');
      else if (kind === 'unsupported' || kind === 'unavailable') toast(t('gps_unavailable'), 'error');
      else toast(t('gps_denied'), 'error');
      openManualLocation();
    } finally {
      setLoading(false);
    }
  };

  const confirmLocation = async (e: React.FormEvent) => {
    e.preventDefault();
    const place = geo || manualFromDistrict(locDistrict, locSector);
    setLoading(true);
    try {
      await verifyLocation(place.lat, place.lng, {
        district: locDistrict,
        sector: locSector,
        province: provinceForDistrict(locDistrict),
      });
      toast(t('gps_ok'), 'success');
      await refreshUser();
      const u = (() => {
        try { return JSON.parse(localStorage.getItem('gugu_user') || 'null') as User | null; }
        catch { return user; }
      })();
      if (needsIdUpload(u)) setStep('id');
      else await finishHome(u);
    } catch (err) {
      toast((err as Error).message, 'error');
    } finally {
      setLoading(false);
    }
  };

  const skipLocation = () => {
    if (needsIdUpload(user)) setStep('id');
    else void finishHome();
  };

  const sectorOptions = (() => {
    const list = sectorsForDistrict(locDistrict);
    if (locSector && !list.includes(locSector)) return [locSector, ...list];
    return list;
  })();

  return (
    <div className="gugu-login-body">
      <div className="gugu-login-card">
        <div className="gugu-login-lang">
          <LanguageSwitcher />
        </div>

        <LoginBrand subtitle={t('sign_in_to')} />

        {step === 'login' && (
          <form onSubmit={submitLogin} className="gugu-login-form">
            <h1>{t('login')}</h1>
            <p className="gugu-login-hint">{t('login_hint')}</p>
            <label htmlFor="gugu-phone">{t('phone')}</label>
            <input
              id="gugu-phone"
              type="tel"
              value={phone}
              onChange={e => setPhone(e.target.value)}
              placeholder="078XXXXXXX"
              required
              autoComplete="username"
            />
            <label htmlFor="gugu-pass">{t('password')}</label>
            <input
              id="gugu-pass"
              type="password"
              value={password}
              onChange={e => setPassword(e.target.value)}
              placeholder="••••••••"
              autoComplete="current-password"
            />
            <button type="submit" className="gugu-login-primary" disabled={loading}>
              {loading ? t('waiting') : t('login')}
            </button>
            <p className="gugu-login-foot">
              {t('no_account')}{' '}
              <a href="#" onClick={e => { e.preventDefault(); push('register'); }}>{t('register')}</a>
              {' · '}
              <a href="#" onClick={e => { e.preventDefault(); resetTo('items'); }}>{t('browse_marketplace')}</a>
            </p>
          </form>
        )}

        {step === 'otp' && (
          <form onSubmit={confirmOtp} className="gugu-login-form">
            <h1>{t('enter_otp')}</h1>
            <p className="gugu-login-hint">{t('otp_sent_to')} {phone}</p>
            {devOtp && (
              <div className="gugu-login-devotp">
                <strong>{t('dev_otp')}:</strong> <span>{devOtp}</span>
              </div>
            )}
            <label htmlFor="gugu-otp">{t('otp_code')}</label>
            <input
              id="gugu-otp"
              inputMode="numeric"
              maxLength={6}
              value={otp}
              onChange={e => setOtp(e.target.value.replace(/\D/g, '').slice(0, 6))}
              placeholder="123456"
              required
              className="gugu-login-otp"
            />
            <button type="submit" className="gugu-login-primary" disabled={loading || otp.length !== 6}>
              {loading ? t('waiting') : t('verify_otp')}
            </button>
            <button type="button" className="gugu-login-secondary" onClick={() => sendOtp()} disabled={loading}>
              {t('resend_otp')}
            </button>
            <button type="button" className="gugu-login-link" onClick={() => setStep('login')}>
              ← {t('change_phone')}
            </button>
          </form>
        )}

        {step === 'profile' && (
          <form onSubmit={saveProfile} className="gugu-login-form">
            <h1>{t('setup_profile')}</h1>
            <p className="gugu-login-hint">{t('setup_profile_hint')}</p>
            <label>{t('nickname')}</label>
            <input value={profile.nickname} onChange={e => setProfile(p => ({ ...p, nickname: e.target.value }))} placeholder="Gabby" required minLength={2} maxLength={50} />
            <label>{t('real_name_private')}</label>
            <input value={profile.real_name} onChange={e => setProfile(p => ({ ...p, real_name: e.target.value }))} placeholder={t('real_name_ph')} />
            <label>{t('email_backup')}</label>
            <input type="email" value={profile.email} onChange={e => setProfile(p => ({ ...p, email: e.target.value }))} placeholder="you@email.com" />
            <label>{t('district')}</label>
            <select
              value={profile.district}
              onChange={e => {
                const district = e.target.value;
                setProfile(p => ({ ...p, district, province: provinceForDistrict(district) }));
              }}
            >
              {Object.entries(RWANDA_PROVINCES).map(([prov, districts]) => (
                <optgroup key={prov} label={prov}>
                  {districts.map(d => <option key={d} value={d}>{d}</option>)}
                </optgroup>
              ))}
            </select>
            <button type="submit" className="gugu-login-primary" disabled={loading}>
              {loading ? t('waiting') : t('save_continue')}
            </button>
          </form>
        )}

        {step === 'location' && (
          <div className="gugu-login-form">
            <h1>{t('gps_title')}</h1>
            <p className="gugu-login-hint">{t('gps_hint')}</p>
            {!geo ? (
              <>
                <button type="button" className="gugu-login-primary" disabled={loading} onClick={captureGps}>
                  {loading ? t('waiting') : t('gps_allow')}
                </button>
                <button type="button" className="gugu-login-secondary" onClick={() => openManualLocation()} disabled={loading}>
                  {t('gps_manual')}
                </button>
                <button type="button" className="gugu-login-link" onClick={skipLocation}>
                  {t('gps_later')}
                </button>
              </>
            ) : (
              <form onSubmit={confirmLocation}>
                <div className="gugu-login-devotp" style={{ textAlign: 'left' }}>
                  <strong>📍 {t('gps_found')}</strong>
                  <div>{geo.label}</div>
                  {geo.accuracy_m != null && geo.source === 'gps' && (
                    <div style={{ fontSize: '0.75rem', opacity: 0.85, marginTop: 4 }}>
                      ±{Math.round(geo.accuracy_m)}m · {geo.lat.toFixed(5)}, {geo.lng.toFixed(5)}
                      {!geo.in_rwanda ? ` · ${t('gps_outside_rwanda')}` : ''}
                    </div>
                  )}
                </div>
                <label>{t('district')} (Akarere)</label>
                <select
                  value={locDistrict}
                  onChange={e => {
                    const d = e.target.value;
                    setLocDistrict(d);
                    const secs = sectorsForDistrict(d);
                    setLocSector(secs[0] || '');
                    setGeo(manualFromDistrict(d, secs[0] || ''));
                  }}
                >
                  {Object.entries(RWANDA_PROVINCES).map(([prov, districts]) => (
                    <optgroup key={prov} label={prov}>
                      {districts.map(d => <option key={d} value={d}>{d}</option>)}
                    </optgroup>
                  ))}
                </select>
                <label>{t('sector')}</label>
                {sectorOptions.length > 0 ? (
                  <select
                    value={locSector}
                    onChange={e => {
                      setLocSector(e.target.value);
                      setGeo(g => g ? { ...g, sector: e.target.value, label: `${locDistrict} / ${e.target.value} · Rwanda` } : manualFromDistrict(locDistrict, e.target.value));
                    }}
                  >
                    {sectorOptions.map(s => <option key={s} value={s}>{s}</option>)}
                  </select>
                ) : (
                  <input value={locSector} onChange={e => setLocSector(e.target.value)} placeholder={t('sector_ph')} />
                )}
                <button type="submit" className="gugu-login-primary" disabled={loading || !locDistrict}>
                  {loading ? t('waiting') : t('gps_confirm')}
                </button>
                <button type="button" className="gugu-login-secondary" onClick={captureGps} disabled={loading}>
                  {t('gps_retry')}
                </button>
                <button type="button" className="gugu-login-link" onClick={skipLocation}>
                  {t('gps_later')}
                </button>
              </form>
            )}
          </div>
        )}

        {step === 'id' && (
          <form onSubmit={submitIdForm} className="gugu-login-form">
            <h1>{t('id_verify_title')}</h1>
            <p className="gugu-login-hint">{t('id_verify_hint')}</p>
            {user?.id_status === 'pending' && (
              <div className="gugu-login-devotp">{t('id_pending_wait')}</div>
            )}
            {user?.id_status === 'rejected' && (
              <div className="gugu-login-devotp" style={{ color: '#B91C1C' }}>
                {t('id_rejected')}: {user.id_reject_reason || t('id_resubmit')}
              </div>
            )}
            {user?.id_status !== 'pending' && (
              <>
                <label>{t('id_number')}</label>
                <input
                  value={idNumber}
                  onChange={e => setIdNumber(e.target.value.replace(/[^\d\s]/g, ''))}
                  placeholder="1199xxxxxxxxxxxx"
                  required
                  inputMode="numeric"
                />
                <label>{t('id_photo')}</label>
                <input
                  type="file"
                  accept="image/jpeg,image/png,image/webp"
                  onChange={e => setIdFile(e.target.files?.[0] || null)}
                  required
                />
                <button type="submit" className="gugu-login-primary" disabled={loading}>
                  {loading ? t('waiting') : t('id_submit')}
                </button>
              </>
            )}
            {user?.id_status === 'pending' && (
              <button type="button" className="gugu-login-primary" onClick={() => { void finishHome(user); }}>
                {t('id_continue_pending')}
              </button>
            )}
            <p className="gugu-login-hint" style={{ marginTop: 12 }}>{t('id_required_note')}</p>
          </form>
        )}
      </div>
    </div>
  );
}

export function RegisterPage() {
  const { register } = useAuth();
  const { pop, resetTo } = useStack();
  const { t } = useLang();
  type RegStep = 'phone' | 'otp' | 'details';
  const [regStep, setRegStep] = useState<RegStep>('phone');
  const [phone, setPhone] = useState('');
  const [otp, setOtp] = useState('');
  const [devOtp, setDevOtp] = useState<string | null>(null);
  const [form, setForm] = useState({
    full_name: '', nickname: '', password: '', email: '',
    province: 'Kigali', district: 'Gasabo',
  });
  const [loading, setLoading] = useState(false);

  const sendRegOtp = async (e?: React.FormEvent) => {
    e?.preventDefault();
    if (!phone.trim()) {
      toast(t('phone'), 'error');
      return;
    }
    setLoading(true);
    try {
      const data = await api.sendOtp(phone, 'register');
      setDevOtp(data.dev_otp || null);
      if (data.dev_otp) setOtp(data.dev_otp);
      toast(data.dev_otp ? `${t('otp_sent_dev')}: ${data.dev_otp}` : t('otp_sent'), 'success');
      setRegStep('otp');
    } catch (err) {
      toast((err as Error).message, 'error');
    } finally {
      setLoading(false);
    }
  };

  const confirmRegOtp = async (e: React.FormEvent) => {
    e.preventDefault();
    if (otp.length !== 6) {
      toast(t('otp_code'), 'error');
      return;
    }
    setLoading(true);
    try {
      await api.confirmOtp(phone, otp);
      setRegStep('details');
    } catch (err) {
      toast((err as Error).message, 'error');
    } finally {
      setLoading(false);
    }
  };

  const submitDetails = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    try {
      await register({
        ...form,
        phone,
        otp,
        nickname: form.nickname || form.full_name.split(' ')[0],
        province: provinceForDistrict(form.district),
      });
      toast(t('register_toast'), 'success');
      // Next step required: national ID photo for security
      sessionStorage.setItem('gugu_auth_step', 'id');
      resetTo('auth');
    } catch (err) {
      toast((err as Error).message, 'error');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="gugu-login-body">
      <div className="gugu-login-card">
        <button type="button" className="gugu-login-link" style={{ alignSelf: 'flex-start' }} onClick={() => pop()}>
          {t('back')}
        </button>
        <LoginBrand subtitle={t('register')} />

        {regStep === 'phone' && (
          <form onSubmit={sendRegOtp} className="gugu-login-form">
            <h1>{t('register')}</h1>
            <p className="gugu-login-hint">{t('register_otp_required')}</p>
            <label>{t('phone')}</label>
            <input type="tel" value={phone} onChange={e => setPhone(e.target.value)} required placeholder="078XXXXXXX" />
            <button type="submit" className="gugu-login-primary" disabled={loading}>
              {loading ? t('waiting') : t('send_otp')}
            </button>
          </form>
        )}

        {regStep === 'otp' && (
          <form onSubmit={confirmRegOtp} className="gugu-login-form">
            <h1>{t('enter_otp')}</h1>
            <p className="gugu-login-hint">{t('otp_sent_to')} {phone}</p>
            {devOtp && (
              <div className="gugu-login-devotp">
                <strong>{t('dev_otp')}:</strong> <span>{devOtp}</span>
              </div>
            )}
            <label>{t('otp_code')}</label>
            <input
              inputMode="numeric"
              maxLength={6}
              value={otp}
              onChange={e => setOtp(e.target.value.replace(/\D/g, '').slice(0, 6))}
              className="gugu-login-otp"
              required
            />
            <button type="submit" className="gugu-login-primary" disabled={loading || otp.length !== 6}>
              {loading ? t('waiting') : t('verify_otp')}
            </button>
            <button type="button" className="gugu-login-secondary" onClick={() => sendRegOtp()} disabled={loading}>
              {t('resend_otp')}
            </button>
          </form>
        )}

        {regStep === 'details' && (
          <form onSubmit={submitDetails} className="gugu-login-form">
            <h1>{t('setup_profile')}</h1>
            <p className="gugu-login-hint">{t('register_after_otp')}</p>
            <label>{t('nickname')}</label>
            <input value={form.nickname} onChange={e => setForm(f => ({ ...f, nickname: e.target.value }))} required minLength={2} />
            <label>{t('real_name_private')}</label>
            <input value={form.full_name} onChange={e => setForm(f => ({ ...f, full_name: e.target.value }))} required />
            <label>{t('password')}</label>
            <input type="password" value={form.password} onChange={e => setForm(f => ({ ...f, password: e.target.value }))} required minLength={6} />
            <label>{t('email_backup')}</label>
            <input type="email" value={form.email} onChange={e => setForm(f => ({ ...f, email: e.target.value }))} />
            <label>{t('district')}</label>
            <select
              value={form.district}
              onChange={e => {
                const district = e.target.value;
                setForm(f => ({ ...f, district, province: provinceForDistrict(district) }));
              }}
            >
              {Object.entries(RWANDA_PROVINCES).map(([prov, districts]) => (
                <optgroup key={prov} label={prov}>
                  {districts.map(d => <option key={d} value={d}>{d}</option>)}
                </optgroup>
              ))}
            </select>
            <button type="submit" className="gugu-login-primary" disabled={loading}>
              {loading ? t('waiting') : t('save_continue')}
            </button>
          </form>
        )}
      </div>
    </div>
  );
}
