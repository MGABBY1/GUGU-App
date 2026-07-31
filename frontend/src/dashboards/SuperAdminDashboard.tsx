import { useEffect, useState } from 'react';
import {
  api, AdminOverview, AdminUserRow, AdminListingRow, AdminReportRow, ROLE, User,
} from '../api/client';
import { toast } from '../components/AuthContext';
import { BRAND_NAME } from '../i18n/translations';
import { DashStatCards, DashSection, DashEmpty, RoleBadge, StatusPill, RoleDuties } from './shared/DashWidgets';

export default function SuperAdminDashboard({ user }: { user: User }) {
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

  const setRole = async (userId: number, roleId: number) => {
    let admin_district = '';
    if (roleId === ROLE.REGIONAL_ADMIN) {
      admin_district = window.prompt('District (Akarere) for District Manager?', 'Gasabo') || '';
      if (!admin_district) return;
    }
    try {
      await api.adminSetRole({ user_id: userId, role_id: roleId, admin_district });
      toast('Role updated', 'success');
      reload();
    } catch (e) {
      toast((e as Error).message, 'error');
    }
  };

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

  if (loading && !overview) return <div className="dash-loading">Loading dashboard…</div>;

  return (
    <div className="dash-body">
      <div className="dash-banner dash-banner-super">
        <div>
          <div className="dash-banner-role">System Administrator</div>
          <h2>System Control Center</h2>
          <p>{BRAND_NAME} Management System · {user.display_name || user.nickname}</p>
        </div>
      </div>

      <DashStatCards overview={overview} />
      <RoleDuties roleId={1} />

      <div className="dash-tabs">
        {(['overview', 'users', 'listings', 'reports'] as const).map(k => (
          <button key={k} type="button" className={tab === k ? 'active' : ''} onClick={() => setTab(k)}>
            {k === 'overview' ? 'Overview' : k === 'users' ? 'Staff accounts' : k === 'listings' ? 'Listings' : 'Reports'}
          </button>
        ))}
      </div>

      {tab === 'overview' && (
        <DashSection title="Users by role">
          <div className="dash-role-grid">
            {(overview?.by_role || []).map(r => (
              <div key={r.role_id} className="dash-role-card">
                <RoleBadge roleId={r.role_id} />
                <strong>{r.count}</strong>
              </div>
            ))}
          </div>
        </DashSection>
      )}

      {tab === 'users' && (
        <DashSection title="Manage users">
          {users.length === 0 ? <DashEmpty text="No users" /> : (
            <div className="dash-list">
              {users.map(u => (
                <div key={u.id} className="dash-row">
                  <div className="dash-row-main">
                    <strong>{u.nickname || u.full_name || 'User'}</strong>
                    <span>{u.phone} · {u.district}</span>
                    <div style={{ display: 'flex', gap: 6, marginTop: 4, flexWrap: 'wrap' }}>
                      <RoleBadge roleId={u.role_id} roleName={u.role_name} />
                      <StatusPill status={u.account_status} />
                    </div>
                  </div>
                  <div className="dash-row-actions">
                    <select
                      value={u.role_id}
                      onChange={e => setRole(u.id, Number(e.target.value))}
                      disabled={u.id === user.id}
                    >
                      <option value={1}>System Administrator</option>
                      <option value={2}>District Manager</option>
                      <option value={3}>Moderator / Support</option>
                      <option value={4}>Member</option>
                    </select>
                    <select
                      value={u.account_status}
                      onChange={e => setStatus(u.id, e.target.value)}
                      disabled={u.id === user.id}
                    >
                      <option value="active">active</option>
                      <option value="suspended">suspended</option>
                      <option value="banned">banned</option>
                    </select>
                  </div>
                </div>
              ))}
            </div>
          )}
          <p className="dash-hint">Assign District Manager — pick their Akarere (Gasabo, Huye, —. Create staff from Members here.</p>
        </DashSection>
      )}

      {tab === 'listings' && (
        <DashSection title="Needs review">
          {listings.length === 0 ? <DashEmpty text="Queue clear" /> : (
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
                  </div>
                </div>
              ))}
            </div>
          )}
        </DashSection>
      )}

      {tab === 'reports' && (
        <DashSection title="Open reports">
          {reports.length === 0 ? <DashEmpty text="No open reports" /> : (
            <div className="dash-list">
              {reports.map(r => (
                <div key={r.id} className="dash-row">
                  <div className="dash-row-main">
                    <strong>{r.target_type} #{r.target_id}</strong>
                    <span>{r.reason}{r.details ? ` —${r.details}` : ''}</span>
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
