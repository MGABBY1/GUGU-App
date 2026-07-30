const Admin = {
    token: localStorage.getItem('gugu_token'),
    user: JSON.parse(localStorage.getItem('gugu_user') || 'null'),

    async api(endpoint, opts = {}) {
        const headers = { 'Content-Type': 'application/json', Authorization: `Bearer ${this.token}` };
        const res = await fetch(`../api/${endpoint}`, { ...opts, headers: { ...headers, ...opts.headers } });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Error');
        return data;
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

    showDashboard() {
        document.getElementById('login-screen').style.display = 'none';
        document.getElementById('dashboard').style.display = 'flex';
        document.getElementById('admin-name').textContent = this.user.full_name;
        this.loadStats();
    },

    tab(name, el) {
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.nav-link').forEach(n => n.classList.remove('active'));
        document.getElementById(`tab-${name}`).classList.add('active');
        el?.classList.add('active');
        const titles = { stats: 'Dashboard', listings: 'All Listings', users: 'All Users' };
        document.getElementById('page-title').textContent = titles[name];
        if (name === 'listings') this.loadListings();
        if (name === 'users') this.loadUsers();
    },

    async loadStats() {
        try {
            const { stats } = await this.api('admin.php?action=stats');
            document.getElementById('stat-grid').innerHTML = [
                ['Users', stats.users, ''],
                ['Total Listings', stats.listings, 'orange'],
                ['Active', stats.active_listings, ''],
                ['Sold', stats.sold_listings, ''],
                ['Messages', stats.messages, ''],
                ['Chat Rooms', stats.chat_rooms, ''],
            ].map(([label, val, cls]) => `
                <div class="stat-card ${cls}">
                    <div class="label">${label}</div>
                    <div class="value">${val}</div>
                </div>
            `).join('');
        } catch (err) {
            document.getElementById('login-screen').style.display = 'flex';
            document.getElementById('dashboard').style.display = 'none';
            alert('Admin access denied: ' + err.message);
        }
    },

    async loadListings() {
        const tbody = document.getElementById('listings-table');
        tbody.innerHTML = '<tr><td colspan="7">Loading...</td></tr>';
        try {
            const { listings } = await this.api('admin.php?action=listings');
            tbody.innerHTML = listings.map(l => `
                <tr>
                    <td>#${l.id}</td>
                    <td>${esc(l.title)}</td>
                    <td>${l.price_formatted}</td>
                    <td>${esc(l.seller_name)}<br><small>${l.seller_phone}</small></td>
                    <td><span class="status ${l.status}">${l.status}</span></td>
                    <td>${l.created_at?.slice(0, 10)}</td>
                    <td><button class="btn-del" onclick="Admin.deleteListing(${l.id})">Delete</button></td>
                </tr>
            `).join('') || '<tr><td colspan="7">No listings</td></tr>';
        } catch (err) { tbody.innerHTML = `<tr><td colspan="7">${err.message}</td></tr>`; }
    },

    async loadUsers() {
        const tbody = document.getElementById('users-table');
        tbody.innerHTML = '<tr><td colspan="7">Loading...</td></tr>';
        try {
            const { users } = await this.api('admin.php?action=users');
            tbody.innerHTML = users.map(u => `
                <tr>
                    <td>#${u.id}</td>
                    <td>${esc(u.full_name)}</td>
                    <td>${u.phone}</td>
                    <td>${u.district}, ${u.province}</td>
                    <td>⭐ ${Math.min(100, Math.max(1, Math.round((parseFloat(u.manner_score) - 20) * 2.5)))}</td>
                    <td>${u.listing_count}</td>
                    <td>${u.created_at?.slice(0, 10)}</td>
                </tr>
            `).join('') || '<tr><td colspan="7">No users</td></tr>';
        } catch (err) { tbody.innerHTML = `<tr><td colspan="7">${err.message}</td></tr>`; }
    },

    async deleteListing(id) {
        if (!confirm('Delete this listing?')) return;
        try {
            await this.api(`admin.php?action=delete-listing&id=${id}`, { method: 'DELETE' });
            this.loadListings();
            this.loadStats();
        } catch (err) { alert(err.message); }
    }
};

function esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

if (Admin.token && Admin.user) Admin.showDashboard();
