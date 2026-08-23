import { useEffect, useState } from 'react';
import { useStack } from '../stack/Stackflow';
import { useAuth, toast } from '../components/AuthContext';
import { api, Listing, hasApprovedId, isMemberUser } from '../api/client';
import { Header } from '../components/BottomNav';
import { useLang } from '../i18n/LanguageContext';
import { BRAND_NAME } from '../i18n/translations';
import { JOBS_CATEGORY_ID } from '../data/services';

/** Jobs hub — announcements stay in Available jobs (not Items marketplace) */
export function JobsPage() {
  const { push, resetTo } = useStack();
  const { isAuthed, user } = useAuth();
  const { t, price, timeAgo } = useLang();
  const [jobs, setJobs] = useState<Listing[]>([]);
  const [loading, setLoading] = useState(true);
  const memberLocked = isAuthed && isMemberUser(user);
  // Members always see jobs in their stay district
  const [nearOnly, setNearOnly] = useState(true);

  const load = () => {
    if (!isAuthed) {
      setJobs([]);
      setLoading(false);
      return;
    }
    setLoading(true);
    const params: Record<string, string> = {
      category: String(JOBS_CATEGORY_ID),
      sort: 'recent',
      limit: '50',
      include_own_pending: '1',
    };
    if (memberLocked || nearOnly) {
      const d = user?.district || localStorage.getItem('gugu_district') || '';
      if (d) params.district = d;
    }
    api.listings(params)
      .then(d => setJobs(d.listings || []))
      .catch(() => setJobs([]))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    if (memberLocked) setNearOnly(true);
    load();
  }, [nearOnly, isAuthed, user?.district, user?.sector, memberLocked]);

  const announce = () => {
    if (!isAuthed) {
      sessionStorage.setItem('gugu_after_login', 'post-job');
      toast(t('login_first'), 'error');
      resetTo('auth');
      return;
    }
    if (!hasApprovedId(user)) {
      toast(t('id_required_to_sell'), 'error');
      sessionStorage.setItem('gugu_auth_step', 'id');
      sessionStorage.setItem('gugu_after_login', 'post-job');
      resetTo('auth');
      return;
    }
    push('post-job');
  };

  const goLogin = () => {
    sessionStorage.setItem('gugu_after_login', 'jobs');
    resetTo('auth');
  };

  const place = [user?.sector, user?.district].filter(Boolean).join(', ');

  return (
    <>
      <Header title={t('jobs_title')} back />
      <div className="stack-content jobs-page">
        <section className="jobs-hero">
          <div className="jobs-hero-brand">
            <div className="market-jobs-logo jobs-hero-logo" aria-hidden>
              <span className="market-jobs-logo-bars">
                <i style={{ background: '#00A1DE' }} />
                <i style={{ background: '#FAD201' }} />
                <i style={{ background: '#20603D' }} />
              </span>
              <strong>{BRAND_NAME}</strong>
              <em>{t('jobs_brand_em')}</em>
            </div>
            <div>
              <h2>{t('jobs_hero')}</h2>
              <p>{t('jobs_hero_sub')}</p>
            </div>
          </div>
          <button type="button" className="seed-btn seed-btn-carrot seed-btn-block" onClick={announce}>
            {t('announce_job')}
          </button>
        </section>

        <h3 className="jobs-section-title">{t('available_jobs')}</h3>
        <p className="jobs-section-hint">{t('jobs_stay_here')}</p>

        {!isAuthed && (
          <div className="chat-empty">
            <div className="chat-empty-ico">💼</div>
            <h2>{t('jobs_login_title')}</h2>
            <p>{t('jobs_login_hint')}</p>
            <button type="button" className="seed-btn seed-btn-carrot" onClick={goLogin}>
              {t('login')}
            </button>
          </div>
        )}

        {isAuthed && (
          <>
            {!memberLocked && (
            <div className="jobs-toolbar">
              <button
                type="button"
                className={`jobs-chip${nearOnly ? ' on' : ''}`}
                onClick={() => setNearOnly(true)}
              >
                📍 {place || t('near_me')}
              </button>
              <button
                type="button"
                className={`jobs-chip${!nearOnly ? ' on' : ''}`}
                onClick={() => setNearOnly(false)}
              >
                {t('all_locations')}
              </button>
            </div>
            )}
            {memberLocked && place && (
              <p className="jobs-section-hint">📍 {place} · {t('stay_district_hint')}</p>
            )}

            {loading && <div className="chat-loading">{t('loading')}</div>}

            {!loading && jobs.length === 0 && (
              <div className="chat-empty">
                <div className="chat-empty-ico">💼</div>
                <h2>{t('no_jobs')}</h2>
                <p>{t('no_jobs_hint')}</p>
                <button type="button" className="seed-btn seed-btn-carrot" onClick={announce}>
                  {t('announce_job')}
                </button>
              </div>
            )}

            <div className="jobs-list">
              {jobs.map(job => {
                const pending = job.moderation_status === 'pending' || job.moderation_status === 'flagged';
                return (
                  <button
                    key={job.id}
                    type="button"
                    className={`jobs-card${pending ? ' jobs-card-pending' : ''}`}
                    onClick={() => push('detail', { id: job.id })}
                  >
                    <div className="jobs-card-top">
                      <strong>{job.title}</strong>
                      <span className="jobs-pay">
                        {job.is_free ? t('pay_negotiable') : price(job.price, false)}
                      </span>
                    </div>
                    <div className="jobs-card-meta">
                      <span className="jobs-announce-tag">{t('job_announcement')}</span>
                      {' · '}
                      📍 {job.sector ? `${job.sector}, ${job.district}` : job.district}
                      {' · '}
                      {timeAgo(job.created_at) || job.time_ago}
                    </div>
                    {pending ? (
                      <div className="jobs-pending-badge">{t('job_pending_badge')}</div>
                    ) : (
                      <div className="jobs-card-cta">{t('view_job')} ›</div>
                    )}
                  </button>
                );
              })}
            </div>
          </>
        )}
      </div>
    </>
  );
}

/** Announce / post a job (Karrot-style) */
export function PostJobPage() {
  const { push, pop, resetTo, canGoBack } = useStack();
  const { isAuthed, user } = useAuth();
  const { t } = useLang();
  const [loading, setLoading] = useState(false);
  const [negotiable, setNegotiable] = useState(false);
  const [feeOk, setFeeOk] = useState(false);
  const [fee, setFee] = useState({ fee_rwf: 1000, momo_number: '0780000000', momo_name: 'Gura & Gurisha Admin' });
  const [form, setForm] = useState({
    title: '',
    description: '',
    pay: '',
    district: user?.district || localStorage.getItem('gugu_district') || 'Gasabo',
    sector: user?.sector || localStorage.getItem('gugu_sector') || '',
  });

  useEffect(() => {
    if (!isAuthed) {
      toast(t('login_first'), 'error');
      resetTo('auth');
      return;
    }
    if (!hasApprovedId(user)) {
      toast(t('id_required_to_sell'), 'error');
      sessionStorage.setItem('gugu_auth_step', 'id');
      sessionStorage.setItem('gugu_after_login', 'post-job');
      resetTo('auth');
      return;
    }
    api.announceFee().then(d => setFee({
      fee_rwf: d.fee_rwf || 1000,
      momo_number: d.momo_number || '0780000000',
      momo_name: d.momo_name || 'Gura & Gurisha Admin',
    })).catch(() => {});
  }, [isAuthed, user]);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!hasApprovedId(user)) {
      toast(t('id_required_to_sell'), 'error');
      sessionStorage.setItem('gugu_auth_step', 'id');
      resetTo('auth');
      return;
    }
    if (!form.title.trim()) {
      toast(t('fill_job_title'), 'error');
      return;
    }
    if (!form.description.trim()) {
      toast(t('fill_job_desc'), 'error');
      return;
    }
    if (!negotiable && (!form.pay || Number(form.pay) < 0)) {
      toast(t('fill_job_pay'), 'error');
      return;
    }
    if (!feeOk) {
      toast(t('fee_must_ack'), 'error');
      return;
    }

    setLoading(true);
    try {
      const fd = new FormData();
      fd.append('title', form.title.trim());
      fd.append('description', form.description.trim());
      fd.append('category_id', String(JOBS_CATEGORY_ID));
      fd.append('price', negotiable ? '0' : String(Number(form.pay) || 0));
      fd.append('is_free', negotiable ? '1' : '0');
      fd.append('province', user?.province || 'Kigali');
      fd.append('district', form.district);
      fd.append('sector', form.sector);
      fd.append('fee_acknowledged', '1');

      const res = await api.createListing(fd);
      toast(res.message || t('pending_after_pay'), 'success');
      resetTo('jobs');
    } catch (err) {
      toast((err as Error).message, 'error');
    } finally {
      setLoading(false);
    }
  };

  return (
    <>
      <Header title={t('announce_job')} back />
      <div className="stack-content jobs-post">
        <p className="jobs-post-hint">{t('announce_job_hint')}</p>
        <form className="jobs-form" onSubmit={submit}>
          <label>{t('job_title')}</label>
          <input
            value={form.title}
            onChange={e => setForm(f => ({ ...f, title: e.target.value }))}
            placeholder={t('job_title_ph')}
            required
          />

          <label>{t('job_desc')}</label>
          <textarea
            rows={5}
            value={form.description}
            onChange={e => setForm(f => ({ ...f, description: e.target.value }))}
            placeholder={t('job_desc_ph')}
            required
          />

          <label className="jobs-check">
            <input
              type="checkbox"
              checked={negotiable}
              onChange={e => setNegotiable(e.target.checked)}
            />
            {t('pay_negotiable')}
          </label>

          {!negotiable && (
            <>
              <label>{t('job_pay')}</label>
              <input
                type="number"
                min={0}
                value={form.pay}
                onChange={e => setForm(f => ({ ...f, pay: e.target.value }))}
                placeholder={t('job_pay_ph')}
              />
            </>
          )}

          <label>{t('district')} (Akarere)</label>
          <input value={form.district} readOnly required />
          <p style={{ fontSize: 12, color: 'var(--seed-gray-600)' }}>{t('post_locked_district')}</p>

          <label>{t('sector')}</label>
          <input
            value={form.sector}
            onChange={e => setForm(f => ({ ...f, sector: e.target.value }))}
            placeholder="Remera"
          />

          <div className="fee-box" style={{ marginTop: 12 }}>
            <h3>{t('announce_fee_title')}</h3>
            <p>{t('announce_fee_clear')}</p>
            <div className="fee-amount">{fee.fee_rwf.toLocaleString()} RWF</div>
            <p className="fee-momo">
              {t('pay_momo_to')}: <strong>{fee.momo_name}</strong>
              <br />
              MoMo: <strong>{fee.momo_number}</strong>
            </p>
            <ol className="fee-steps">
              <li>{t('fee_step_1')}</li>
              <li>{t('fee_step_2')}</li>
              <li>{t('fee_step_3')}</li>
            </ol>
            <label className="fee-check">
              <input type="checkbox" checked={feeOk} onChange={e => setFeeOk(e.target.checked)} />
              {t('fee_ack_label')}
            </label>
          </div>

          <button type="submit" className="seed-btn seed-btn-carrot seed-btn-block" disabled={loading || !feeOk}>
            {loading ? t('posting') : t('submit_for_approval')}
          </button>
          <button
            type="button"
            className="seed-btn seed-btn-outline seed-btn-block"
            style={{ marginTop: 10 }}
            onClick={() => (canGoBack ? pop() : resetTo('jobs'))}
          >
            {t('gps_later')}
          </button>
        </form>
      </div>
    </>
  );
}
