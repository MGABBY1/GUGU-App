import { AdminOverview } from '../../api/client';

export function DashStatCards({
  overview,
  extra,
}: {
  overview?: AdminOverview | null;
  extra?: { label: string; value: string | number }[];
}) {
  const cards = extra || [
    { label: 'Users', value: overview?.users_total ?? '—' },
    { label: 'Active items', value: overview?.listings_active ?? '—' },
    { label: 'Needs review', value: overview?.listings_needs_review ?? '—' },
    { label: 'Open reports', value: overview?.reports_open ?? '—' },
  ];

  return (
    <div className="dash-stats">
      {cards.map(c => (
        <div key={c.label} className="dash-stat">
          <div className="dash-stat-value">{c.value}</div>
          <div className="dash-stat-label">{c.label}</div>
        </div>
      ))}
    </div>
  );
}

export function DashSection({ title, children, action }: { title: string; children: React.ReactNode; action?: React.ReactNode }) {
  return (
    <section className="dash-section">
      <div className="dash-section-head">
        <h3>{title}</h3>
        {action}
      </div>
      {children}
    </section>
  );
}

export function DashEmpty({ text }: { text: string }) {
  return <div className="dash-empty">{text}</div>;
}

export function RoleBadge({ roleId, roleName }: { roleId: number; roleName?: string }) {
  const labels: Record<number, string> = {
    1: 'Admin',
    2: 'District Manager',
    3: 'Moderator / Support',
    4: 'Member',
  };
  return <span className={`dash-role dash-role-${roleId}`}>{labels[roleId] || roleName || 'User'}</span>;
}

export function StatusPill({ status }: { status: string }) {
  return <span className={`dash-pill dash-pill-${status}`}>{status}</span>;
}

export function RoleDuties({ roleId }: { roleId: number }) {
  const duties: Record<number, string[]> = {
    1: [
      'Full system control — settings, MoMo/SMS, fees, platform flags',
      'Create & manage staff (District Manager, Moderator / Support)',
      'Nationwide listings, payments, bans, and reports',
      'Assign District Manager Akarere (Gasabo, Huye, …)',
      'Preview any staff dashboard while keeping Admin power',
    ],
    2: [
      'Activate or suspend members (and Moderator / Support) in your Akarere',
      'Confirm MoMo, Mark paid, Approve good posts (Admin earns)',
      'Reject spam / fake listings in your region (Items & Jobs)',
      'Handle reports that involve local listings or users',
      'Cannot change Admin / other District Managers',
      'Cannot ban — escalate bans to Admin',
    ],
    3: [
      'Review flagged items & jobs; approve, flag, or reject spam / fakes',
      'Handle support tickets and community reports',
      'Review member ID documents (Approve / Reject)',
      'Ban or suspend fraudulent member accounts',
      'Cannot change Admin, District Managers, or staff roles',
    ],
    4: [
      'Buy items near you',
      'Sell / post listings',
      'Chat with other members',
      'Save favorites',
      'Update your profile & GPS',
    ],
  };
  const list = duties[roleId] || duties[4];
  return (
    <section className="dash-section">
      <div className="dash-section-head"><h3>What you can do</h3></div>
      <ul className="dash-duties">
        {list.map(item => <li key={item}>{item}</li>)}
      </ul>
    </section>
  );
}
