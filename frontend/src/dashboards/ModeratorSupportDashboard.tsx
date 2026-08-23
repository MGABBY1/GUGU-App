import { useEffect, useState } from 'react';
import {
  api, AdminOverview, AdminListingRow, AdminReportRow, User,
} from '../api/client';
import { toast } from '../components/AuthContext';
import { useLang } from '../i18n/LanguageContext';
import { DashStatCards, DashSection, DashEmpty, StatusPill, RoleDuties } from './shared/DashWidgets';

/** Moderator / Support — Trust & Safety portal */
export default function ModeratorSupportDashboard({ user }: { user: User }) {
  const { t } = useLang();
  const [overview, setOverview] = useState<AdminOverview | null>(null);
  const [listings, setListings] = useState<AdminListingRow[]>([]);
  const [reports, setReports] = useState<AdminReportRow[]>([]);
  const [tab, setTab] = useState<'queue' | 'reports'>('queue');
  const [loading, setLoading] = useState(true);

  const reload = async () => {
    setLoading(true);
    try {
      const [o, l, r] = await Promise.all([
        api.adminOverview(),
        api.adminListings({ needs_review: '1' }),
        api.adminReports('open'),
      ]);
      setOverview(o.overview);
      setListings(l.listings || []);
      setReports(r.reports || []);
    } catch (e) {
      toast((e as Error).message, 'error');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { reload(); }, []);

  const moderate = async (listingId: number, moderation_status: string) => {
    try {
      await api.adminModerateListing({ listing_id: listingId, moderation_status });
      toast('Listing updated', 'success');
      reload();
    } catch (e) {
      toast((e as Error).message, 'error');
    }
  };

  const resolve = async (reportId: number, status: string) => {
    try {
      await api.adminResolveReport({ report_id: reportId, status });
      toast('Report updated', 'success');
      reload();
    } catch (e) {
      toast((e as Error).message, 'error');
    }
  };

  const suspendUser = async (userId: number) => {
    try {
      await api.adminSetStatus({ user_id: userId, account_status: 'suspended' });
      toast('Seller suspended', 'success');
      reload();
    } catch (e) {
      toast((e as Error).message, 'error');
    }
  };

  const banUser = async (userId: number) => {
    try {
      await api.adminSetStatus({ user_id: userId, account_status: 'banned' });
      toast('Fraudulent account banned', 'success');
      reload();
    } catch (e) {
      toast((e as Error).message, 'error');
    }
  };

  if (loading && !overview) return <div className="dash-loading">{t('loading')}</div>;

  return (
    <div className="dash-body">
      <div className="dash-banner dash-banner-support">
        <div>
          <div className="dash-banner-role">{t('role_moderator')}</div>
          <h2>{t('dash_moderator_title')}</h2>
          <p>{user.nickname}</p>
        </div>
      </div>

      <DashStatCards
        extra={[
          { label: 'Queue', value: overview?.listings_needs_review ?? '—' },
          { label: 'Open reports', value: overview?.reports_open ?? '—' },
          { label: 'Active items', value: overview?.listings_active ?? '—' },
        ]}
      />
      <RoleDuties roleId={3} />

      <div className="dash-tabs">
        <button type="button" className={tab === 'queue' ? 'active' : ''} onClick={() => setTab('queue')}>Moderation queue</button>
        <button type="button" className={tab === 'reports' ? 'active' : ''} onClick={() => setTab('reports')}>Reports</button>
      </div>

      {tab === 'queue' && (
        <DashSection title="Flagged / pending listings">
          {listings.length === 0 ? <DashEmpty text="Queue is empty" /> : (
            <div className="dash-list">
              {listings.map(l => (
                <div key={l.id} className="dash-row">
                  <div className="dash-row-main">
                    <strong>{l.title}</strong>
                    <span>{l.district} · {l.nickname} · <StatusPill status={l.moderation_status} /></span>
                  </div>
                  <div className="dash-row-actions">
                    <button type="button" onClick={() => moderate(l.id, 'approved')}>Approve</button>
                    <button type="button" onClick={() => moderate(l.id, 'flagged')}>Flag</button>
                    <button type="button" className="danger" onClick={() => moderate(l.id, 'rejected')}>Reject</button>
                    <button type="button" className="danger" onClick={() => suspendUser(l.seller_id)}>Suspend</button>
                    <button type="button" className="danger" onClick={() => banUser(l.seller_id)}>Ban fraud</button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </DashSection>
      )}

      {tab === 'reports' && (
        <DashSection title="Community reports">
          {reports.length === 0 ? <DashEmpty text="No open reports" /> : (
            <div className="dash-list">
              {reports.map(r => (
                <div key={r.id} className="dash-row">
                  <div className="dash-row-main">
                    <strong>{r.target_type} #{r.target_id}</strong>
                    <span>{r.reason}</span>
                    {r.details && <span style={{ display: 'block', color: 'var(--seed-gray-500)' }}>{r.details}</span>}
                  </div>
                  <div className="dash-row-actions">
                    <button type="button" onClick={() => resolve(r.id, 'reviewing')}>Reviewing</button>
                    <button type="button" onClick={() => resolve(r.id, 'resolved')}>Resolve</button>
                    <button type="button" onClick={() => resolve(r.id, 'dismissed')}>Dismiss</button>
                    {r.target_type === 'user' && (
                      <button type="button" className="danger" onClick={() => banUser(r.target_id)}>Ban user</button>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </DashSection>
      )}
    </div>
  );
}
