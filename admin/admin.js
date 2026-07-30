/**
 * GUGU Administrative Portal (secured access)
 * Roles: moderator -> district_manager -> super_admin
 */

const ROLE_LABELS = {
    moderator: 'Moderator',
    district_manager: 'District Manager',
    super_admin: 'Super Admin',
    member: 'Member'
};

const PERMISSION_ROWS = [
    ['view_dashboard', 'View dashboard'],
    ['view_moderation', 'Moderation queue'],
    ['approve_listing', 'Approve listing'],
    ['reject_listing', 'Reject listing'],
    ['delete_listing', 'Delete listing'],
    ['view_users', 'View users'],
    ['verify_user', 'Verify user'],
    ['ban_user', 'Ban user'],
    ['view_disputes', 'View disputes'],
    ['handle_dispute', 'Handle disputes'],
    ['view_analytics', 'District analytics'],
    ['view_regional_report', 'Regional reporting'],
    ['manage_roles', 'Manage roles'],
    ['system_controls', 'System controls'],
    ['view_audit_log', 'Audit log'],
    ['view_all_districts', 'All districts']
];

const ROLE_MATRIX = {
    moderator: ['view_dashboard', 'view_moderation', 'approve_listing', 'reject_listing', 'view_listings', 'view_users', 'view_disputes'],
    district_manager: ['view_dashboard', 'view_moderation', 'approve_listing', 'reject_listing', 'view_listings', 'view_users', 'view_disputes', 'delete_listing', 'verify_user', 'ban_user', 'handle_dispute', 'view_analytics', 'view_regional_report'],
    super_admin: PERMISSION_ROWS.map(([key]) => key)
};

const Admin = {
    token: localStorage.getItem('gugu_token'),
    user: JSON.parse(localStorage.getItem('gugu_user') || 'null'),
    profile: null,

    async api(endpoint, opts = {}) {
        const headers = { 'Content-Type': 'application/json', Authorization: `Bearer ${this.token}` };
        const res = await fetch(`../api/${endpoint}`, { ...opts, headers: { ...headers, ...opts.headers } });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Error');
        return data;
    },

    can(permission) {
        return this.profile?.permissions?.includes(permission) ?? false;
    },

    async login(e) {
        e.preventDefault();
        const phone = document.getElementById('admin-phone').value;
        const password = document.getElementById('admin-pass').value;
        try {
            const res = await fetch('../api/auth.php?action=login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ phone, password })
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error);
            this.token = data.token;
            this.user = data.user;
            localStorage.setItem('gugu_token', data.token);
            localStorage.setItem('gugu_user', JSON.stringify(data.user));
            this.showDashboard();
        } catch (err) {
            alert(err.message);
        }
    },

    logout() {
        localStorage.removeItem('gugu_token');
        localStorage.removeItem('gugu_user');
        location.reload();
    },

    showLogin(message) {
        document.getElementById('login-screen').style.display = 'flex';
        document.getElementById('dashboard').style.display = 'none';
        if (message) alert(message);
    },

    async showDashboard() {
        try {
            const { admin } = await this.api('admin.php?action=me');
            this.profile = admin;
        } catch (err) {
            this.showLogin('Admin access denied: ' + err.message);
            return;
        }

        document.getElementById('login-screen').style.display = 'none';
        document.getElementById('dashboard').style.display = 'flex';
        document.getElementById('admin-name').textContent = this.profile.full_name;

        const scope = this.profile.district_scope ? ` · ${this.profile.district_scope}` : ' · All districts';
        document.getElementById('role-badge').textContent = (ROLE_LABELS[this.profile.role] || this.profile.role) + scope;

        this.applyPermissions();
        this.loadStats();
    },

    applyPermissions() {
        const gated = {
            'nav-permissions': 'view_audit_log',
            'nav-system': 'system_controls'
        };
        Object.entries(gated).forEach(([id, permission]) => {
            const el = document.getElementById(id);
            if (el) el.style.display = this.can(permission) ? 'block' : 'none';
        });
    },

    tab(name, el) {
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.nav-link').forEach(n => n.classList.remove('active'));
        document.getElementById(`tab-${name}`)?.classList.add('active');
        el?.classList.add('active');

        const titles = {
            stats: 'Dashboard',
            moderation: 'Moderation Queue',
            listings: 'All Listings',
            users: 'All Users',
            disputes: 'Dispute Tickets',
            analytics: 'District Analytics',
            permissions: 'Permission Controls',
            system: 'System Controls'
        };
        document.getElementById('page-title').textContent = titles[name] || 'Dashboard';

        const loaders = {
            stats: () => this.loadStats(),
            moderation: () => this.loadModeration(),
            listings: () => this.loadListings(),
            users: () => this.loadUsers(),
            disputes: () => this.loadDisputes(),
            analytics: () => this.loadAnalytics(),
            permissions: () => this.loadPermissions(),
            system: () => this.loadSettings()
        };
        loaders[name]?.();
    },

    async loadStats() {
        try {
            const { stats } = await this.api('admin.php?action=stats');
            document.getElementById('stat-grid').innerHTML = [
                ['Users', stats.users, ''],
                ['Total Listings', stats.listings, 'orange'],
                ['Active', stats.active_listings, ''],
                ['Sold', stats.sold_listings, ''],
                ['Pending approval', stats.pending_listings, 'warn'],
                ['Open reports', stats.open_reports, 'warn'],
                ['Open disputes', stats.open_disputes, 'danger'],
                ['Orders', stats.orders, ''],
                ['In escrow', stats.escrow_held_formatted, 'orange'],
                ['Messages', stats.messages, ''],
            ].map(([label, val, cls]) => `
                <div class="stat-card ${cls}">
                    <div class="label">${label}</div>
                    <div class="value">${val ?? 0}</div>
                </div>
            `).join('');
        } catch (err) {
            this.showLogin('Admin access denied: ' + err.message);
        }
    },

    async loadModeration() {
        const tbody = document.getElementById('moderation-table');
        tbody.innerHTML = '<tr><td colspan="6">Loading...</td></tr>';
        try {
            const { queue } = await this.api('admin.php?action=moderation');
            tbody.innerHTML = queue.length ? queue.map(item => `
                <tr>
                    <td>#${item.id}</td>
                    <td>
                        ${esc(item.title)}
                        <br><small class="muted">${item.queue_type === 'report'
                            ? `🚩 ${esc(item.reason)} — ${esc(item.reporter_name)}`
                            : '⏳ Waiting approval'}</small>
                    </td>
                    <td>${item.price_formatted}</td>
                    <td>${esc(item.seller_name)}<br><small>${item.seller_phone}</small></td>
                    <td>${esc(item.district || '')}</td>
                    <td>
                        <button class="btn-ok" onclick="Admin.approve(${item.id})">Approve</button>
                        <button class="btn-del" onclick="Admin.reject(${item.id})">Reject</button>
                        ${item.queue_type === 'report'
                            ? `<button class="btn-mini" onclick="Admin.dismissReport(${item.report_id})">Dismiss</button>`
                            : ''}
                    </td>
                </tr>
            `).join('') : '<tr><td colspan="6">Queue is clear</td></tr>';
        } catch (err) { tbody.innerHTML = `<tr><td colspan="6">${esc(err.message)}</td></tr>`; }
    },

    async approve(listingId) {
        try {
            await this.api('admin.php?action=approve-listing', {
                method: 'POST', body: JSON.stringify({ listing_id: listingId })
            });
            this.loadModeration();
            this.loadStats();
        } catch (err) { alert(err.message); }
    },

    async reject(listingId) {
        const reason = prompt('Reason for rejection:');
        if (reason === null) return;
        try {
            await this.api('admin.php?action=reject-listing', {
                method: 'POST', body: JSON.stringify({ listing_id: listingId, reason })
            });
            this.loadModeration();
            this.loadStats();
        } catch (err) { alert(err.message); }
    },

    async dismissReport(reportId) {
        try {
            await this.api('admin.php?action=dismiss-report', {
                method: 'POST', body: JSON.stringify({ report_id: reportId })
            });
            this.loadModeration();
        } catch (err) { alert(err.message); }
    },

    async loadListings() {
        const tbody = document.getElementById('listings-table');
        tbody.innerHTML = '<tr><td colspan="8">Loading...</td></tr>';
        try {
            const { listings, can_delete } = await this.api('admin.php?action=listings');
            tbody.innerHTML = listings.length ? listings.map(l => `
                <tr>
                    <td>#${l.id}</td>
                    <td>${esc(l.title)}</td>
                    <td>${l.price_formatted}</td>
                    <td>${esc(l.seller_name)}<br><small>${l.seller_phone}</small></td>
                    <td><span class="status ${l.status}">${l.status}</span></td>
                    <td><span class="status ${l.approval_status}">${l.approval_status}</span></td>
                    <td>${l.created_at?.slice(0, 10)}</td>
                    <td>${can_delete ? `<button class="btn-del" onclick="Admin.deleteListing(${l.id})">Delete</button>` : '<span class="muted">—</span>'}</td>
                </tr>
            `).join('') : '<tr><td colspan="8">No listings</td></tr>';
        } catch (err) { tbody.innerHTML = `<tr><td colspan="8">${esc(err.message)}</td></tr>`; }
    },

    async deleteListing(id) {
        if (!confirm('Delete this listing?')) return;
        try {
            await this.api(`admin.php?action=delete-listing&id=${id}`, { method: 'DELETE' });
            this.loadListings();
            this.loadStats();
        } catch (err) { alert(err.message); }
    },

    async loadUsers() {
        const tbody = document.getElementById('users-table');
        tbody.innerHTML = '<tr><td colspan="8">Loading...</td></tr>';
        try {
            const { users, can_manage_roles, can_ban, roles } = await this.api('admin.php?action=users');
            tbody.innerHTML = users.length ? users.map(u => `
                <tr>
                    <td>#${u.id}</td>
                    <td>${esc(u.full_name)}</td>
                    <td>${u.phone}</td>
                    <td>${esc(u.district || '')}, ${esc(u.province || '')}</td>
                    <td>
                        ${can_manage_roles ? `
                            <select class="role-select" onchange="Admin.setRole(${u.id}, this.value)">
                                ${roles.map(r => `<option value="${r}" ${r === u.role ? 'selected' : ''}>${ROLE_LABELS[r] || r}</option>`).join('')}
                            </select>` : `<span class="pill-role">${ROLE_LABELS[u.role] || u.role}</span>`}
                    </td>
                    <td>
                        ${Number(u.is_verified) ? '<span class="status active">verified</span>' : ''}
                        ${Number(u.is_banned) ? '<span class="status danger">banned</span>' : ''}
                    </td>
                    <td>${u.listing_count}</td>
                    <td>
                        ${can_ban ? `
                            <button class="btn-mini" onclick="Admin.verifyUser(${u.id})">Verify</button>
                            <button class="btn-del" onclick="Admin.banUser(${u.id})">${Number(u.is_banned) ? 'Unban' : 'Ban'}</button>
                        ` : '<span class="muted">—</span>'}
                    </td>
                </tr>
            `).join('') : '<tr><td colspan="8">No users</td></tr>';
        } catch (err) { tbody.innerHTML = `<tr><td colspan="8">${esc(err.message)}</td></tr>`; }
    },

    async setRole(userId, role) {
        let managedDistrict = '';
        if (role === 'district_manager' || role === 'moderator') {
            managedDistrict = prompt('Which Akarere (district) should this staff member cover? Leave blank to use their own district:') || '';
        }
        try {
            await this.api('admin.php?action=set-role', {
                method: 'POST', body: JSON.stringify({ user_id: userId, role, managed_district: managedDistrict })
            });
            this.loadUsers();
        } catch (err) { alert(err.message); this.loadUsers(); }
    },

    async banUser(userId) {
        if (!confirm('Toggle ban for this user?')) return;
        try {
            await this.api('admin.php?action=ban-user', { method: 'POST', body: JSON.stringify({ user_id: userId }) });
            this.loadUsers();
        } catch (err) { alert(err.message); }
    },

    async verifyUser(userId) {
        try {
            await this.api('admin.php?action=verify-user', { method: 'POST', body: JSON.stringify({ user_id: userId }) });
            this.loadUsers();
        } catch (err) { alert(err.message); }
    },

    async loadDisputes() {
        const tbody = document.getElementById('disputes-table');
        tbody.innerHTML = '<tr><td colspan="7">Loading...</td></tr>';
        try {
            const { disputes, can_handle } = await this.api('admin.php?action=disputes');
            tbody.innerHTML = disputes.length ? disputes.map(d => `
                <tr>
                    <td>#${d.id}</td>
                    <td>${esc(d.raised_by_name)}<br><small class="muted">vs ${esc(d.against_name)}</small></td>
                    <td>${esc(d.listing_title)}<br><small class="muted">${esc(d.district || '')}</small></td>
                    <td>${esc(d.reason)}</td>
                    <td><span class="status ${d.status}">${d.status.replace('_', ' ')}</span></td>
                    <td>${d.amount_formatted}<br><small class="muted">${d.escrow_status}</small></td>
                    <td>
                        ${can_handle && ['open', 'in_review'].includes(d.status) ? `
                            <button class="btn-mini" onclick="Admin.resolveDispute(${d.id},'in_review')">Investigate</button>
                            <button class="btn-ok" onclick="Admin.resolveDispute(${d.id},'resolved_buyer')">Refund buyer</button>
                            <button class="btn-ok" onclick="Admin.resolveDispute(${d.id},'resolved_seller')">Pay seller</button>
                            <button class="btn-del" onclick="Admin.resolveDispute(${d.id},'closed')">Close</button>
                        ` : `<span class="muted">${d.handled_by_name ? esc(d.handled_by_name) : '—'}</span>`}
                    </td>
                </tr>
            `).join('') : '<tr><td colspan="7">No disputes</td></tr>';
        } catch (err) { tbody.innerHTML = `<tr><td colspan="7">${esc(err.message)}</td></tr>`; }
    },

    async resolveDispute(disputeId, decision) {
        const resolution = prompt('Resolution note (optional):') || '';
        try {
            await this.api('admin.php?action=resolve-dispute', {
                method: 'POST', body: JSON.stringify({ dispute_id: disputeId, decision, resolution })
            });
            this.loadDisputes();
            this.loadStats();
        } catch (err) { alert(err.message); }
    },

    async loadAnalytics() {
        try {
            const data = await this.api('admin.php?action=analytics');
            document.getElementById('analytics-summary').innerHTML = `
                <div class="stat-card orange">
                    <div class="label">Escrow revenue released</div>
                    <div class="value">${data.revenue_formatted}</div>
                </div>
                <div class="stat-card">
                    <div class="label">Region</div>
                    <div class="value" style="font-size:1.25rem">${data.district_scope || 'All districts'}</div>
                </div>`;
            bars('chart-district', data.by_district);
            bars('chart-month', data.by_month);
            bars('chart-orders', data.by_order_status);
            bars('chart-category', data.by_category);
        } catch (err) {
            document.getElementById('analytics-summary').innerHTML = `<div class="stat-card"><div class="label">${esc(err.message)}</div></div>`;
        }
    },

    loadPermissions() {
        document.getElementById('permissions-table').innerHTML = PERMISSION_ROWS.map(([key, label]) => `
            <tr>
                <td>${label}</td>
                <td>${ROLE_MATRIX.moderator.includes(key) ? '<span class="tick">✔</span>' : '<span class="muted">—</span>'}</td>
                <td>${ROLE_MATRIX.district_manager.includes(key) ? '<span class="tick">✔</span>' : '<span class="muted">—</span>'}</td>
                <td><span class="tick">✔</span></td>
            </tr>
        `).join('');
        this.loadAudit();
    },

    async loadAudit() {
        const tbody = document.getElementById('audit-table');
        if (!this.can('view_audit_log')) {
            tbody.innerHTML = '<tr><td colspan="5" class="muted">Super Admin only</td></tr>';
            return;
        }
        tbody.innerHTML = '<tr><td colspan="5">Loading...</td></tr>';
        try {
            const { entries } = await this.api('admin.php?action=audit');
            tbody.innerHTML = entries.length ? entries.map(a => `
                <tr>
                    <td>${a.time_ago}</td>
                    <td>${esc(a.admin_name)}</td>
                    <td>${esc(a.action)}</td>
                    <td>${esc(a.target_type || '')} ${a.target_id ? '#' + a.target_id : ''}</td>
                    <td>${esc(a.details || '')}</td>
                </tr>
            `).join('') : '<tr><td colspan="5">No activity yet</td></tr>';
        } catch (err) { tbody.innerHTML = `<tr><td colspan="5">${esc(err.message)}</td></tr>`; }
    },

    async loadSettings() {
        const wrap = document.getElementById('system-form');
        wrap.innerHTML = 'Loading...';
        try {
            const { settings } = await this.api('admin.php?action=settings');
            const toggles = [
                ['require_listing_approval', 'Require listing approval before listings go public'],
                ['escrow_enabled', 'Escrow wallet enabled'],
                ['momo_sandbox', 'MTN/Airtel MoMo sandbox mode'],
                ['maintenance_mode', 'Maintenance mode']
            ];
            wrap.innerHTML = toggles.map(([key, label]) => `
                <label class="setting-row">
                    <input type="checkbox" id="set-${key}" ${settings[key] === '1' ? 'checked' : ''}>
                    <span>${label}</span>
                </label>
            `).join('') + `
                <label class="setting-row">
                    <span>Platform fee (%)</span>
                    <input type="number" min="0" max="100" id="set-platform_fee_percent" value="${settings.platform_fee_percent ?? 0}" class="num-input">
                </label>`;
        } catch (err) { wrap.innerHTML = `<div class="muted">${esc(err.message)}</div>`; }
    },

    async saveSettings() {
        const payload = {
            require_listing_approval: document.getElementById('set-require_listing_approval')?.checked ? '1' : '0',
            escrow_enabled: document.getElementById('set-escrow_enabled')?.checked ? '1' : '0',
            momo_sandbox: document.getElementById('set-momo_sandbox')?.checked ? '1' : '0',
            maintenance_mode: document.getElementById('set-maintenance_mode')?.checked ? '1' : '0',
            platform_fee_percent: document.getElementById('set-platform_fee_percent')?.value || '0'
        };
        try {
            const data = await this.api('admin.php?action=save-settings', { method: 'POST', body: JSON.stringify(payload) });
            alert(data.message);
        } catch (err) { alert(err.message); }
    }
};

function esc(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }

function bars(elementId, rows) {
    const el = document.getElementById(elementId);
    if (!el) return;
    if (!rows || !rows.length) { el.innerHTML = '<div class="chart-empty">No data yet</div>'; return; }
    const max = Math.max(...rows.map(r => Number(r.value) || 0), 1);
    el.innerHTML = rows.map(r => `
        <div class="bar-row">
            <div class="bar-label" title="${esc(r.label)}">${esc(r.label)}</div>
            <div class="bar-track"><div class="bar-fill" style="width:${(Number(r.value) / max) * 100}%"></div></div>
            <div class="bar-value">${r.value}</div>
        </div>
    `).join('');
}

if (Admin.token && Admin.user) Admin.showDashboard();
