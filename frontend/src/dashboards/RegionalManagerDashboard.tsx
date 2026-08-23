import { useEffect, useState } from 'react';
import {
  api, AdminOverview, AdminUserRow, AdminListingRow, AdminReportRow, User,
} from '../api/client';
import { toast } from '../components/AuthContext';
import { useLang } from '../i18n/LanguageContext';
import { DashStatCards, DashSection, DashEmpty, RoleBadge, StatusPill, RoleDuties } from './shared/DashWidgets';

/** District Manager — District Operations Hub */
export default function RegionalManagerDashboard({ user }: { user: User }) {
  const { t } = useLang();
  const district = user.admin_district || user.district;
  const [overview, setOverview] = useState<AdminOverview | null>(null);
  const [users, setUsers] = useState<AdminUserRow[]>([]);
  const [listings, setListings] = useState<AdminListingRow[]>([]);
  const [reports, setReports] = useState<AdminReportRow[]>([]);
  const [tab, setTab] = useState<'overview' | 'users' | 'listings' | 'reports'>('overview');
  const [loading, setLoading] = useState(true);

  const reload = async () => {
    setLoading(true);
    try {
      const [o, u, l, r] = await Promise.all([
        api.adminOverview(),
        api.adminUsers(),
        api.adminListings({ needs_review: '1' }),
        api.adminReports('open'),
      ]);
      setOverview(o.overview);
      setUsers(u.users || []);
      setListings(l.listings || []);
      setReports(r.reports || []);
    } catch (e) {
      toast((e as Error).message, 'error');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { reload(); }, []);

  const setStatus = async (userId: number, account_status: string) => {
    try {
      await api.adminSetStatus({ user_id: userId, account_status });
      toast('Status updated', 'success');
      reload();
    } catch (e) {
      toast((e as Error).message, 'error');
    }
  };

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

  if (loading && !overview) return <div className="dash-loading">{t('loading')}</div>;

  return (
    <div className="dash-body">
      <div className="dash-banner dash-banner-regional">
        <div>
          <div className="dash-banner-role">District Manager</div>
          <h2>{t('dash_district_title')} · {district}</h2>
          <p>Local users, listings &amp; reports · Akarere only</p>
        </div>
      </div>

      <DashStatCards
        extra={[
          { label: 'Local users', value: overview?.users_total ?? '—' },
          { label: 'Active items', value: overview?.listings_active ?? '—' },
          { label: 'Needs review', value: overview?.listings_needs_review ?? '—' },
          { label: 'Local reports', value: overview?.reports_open ?? '—' },
        ]}
      />
      <RoleDuties roleId={2} />

      <div className="dash-tabs">
        {([
          ['overview', 'Overview'],
          ['users', 'Local users'],
          ['listings', 'Review items'],
          ['reports', 'Local reports'],
        ] as const).map(([k, label]) => (
          <button key={k} type="button" className={tab === k ? 'active' : ''} onClick={() => setTab(k)}>
            {label}
          </button>
        ))}
      </div>

      {tab === 'overview' && (
        <DashSection title={`Scope: ${overview?.scope_district || district}`}>
          <p className="dash-hint">You only see data for your assigned district. Escalate fraud bans to Trust &amp; Safety.</p>
        </DashSection>
      )}

      {tab === 'users' && (
        <DashSection title={`Users in ${district}`}>
          {users.length === 0 ? <DashEmpty text="No users in this region" /> : (
            <div className="dash-list">
              {users.map(u => (
                <div key={u.id} className="dash-row">
                  <div className="dash-row-main">
                    <strong>{u.nickname || 'User'}</strong>
                    <span>{u.district}{u.sector ? ` / ${u.sector}` : ''}</span>
                    <div style={{ display: 'flex', gap: 6, marginTop: 4 }}>
                      <RoleBadge roleId={u.role_id} />
                      <StatusPill status={u.account_status} />
                    </div>
                  </div>
                  {u.role_id >= 3 && u.id !== user.id && (
                    <div className="dash-row-actions">
                      <button type="button" onClick={() => setStatus(u.id, 'active')}>Activate</button>
                      <button type="button" className="danger" onClick={() => setStatus(u.id, 'suspended')}>Suspend</button>
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}
        </DashSection>
      )}

      {tab === 'listings' && (
        <DashSection title="Items to review">
          {listings.length === 0 ? <DashEmpty text="Nothing to review" /> : (
            <div className="dash-list">
              {listings.map(l => (
                <div key={l.id} className="dash-row">
                  <div className="dash-row-main">
                    <strong>{l.title}</strong>
                    <span>{l.sector || l.district} · <StatusPill status={l.moderation_status} /></span>
                  </div>
                  <div className="dash-row-actions">
                    <button type="button" onClick={() => moderate(l.id, 'approved')}>Approve</button>
                    <button type="button" className="danger" onClick={() => moderate(l.id, 'rejected')}>Reject</button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </DashSection>
      )}

      {tab === 'reports' && (
        <DashSection title="Local reports">
          {reports.length === 0 ? <DashEmpty text="No local open reports" /> : (
            <div className="dash-list">
              {reports.map(r => (
                <div key={r.id} className="dash-row">
                  <div className="dash-row-main">
                    <strong>{r.target_type} #{r.target_id}</strong>
                    <span>{r.reason}</span>
                  </div>
                  <div className="dash-row-actions">
                    <button type="button" onClick={() => resolve(r.id, 'resolved')}>Resolve</button>
                    <button type="button" onClick={() => resolve(r.id, 'dismissed')}>Dismiss</button>
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
