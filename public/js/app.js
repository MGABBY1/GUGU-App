/**
 * GUGU App — Desktop Web UI
 */

const API_BASE = '../api';
const CATEGORY_ICONS = {
    home: '🏠', phone: '📱', couch: '🛋️', shirt: '👕', car: '🚗',
    house: '🏡', ball: '⚽', food: '🍎', plug: '🔌', box: '📦'
};

const App = {
    token: localStorage.getItem('gugu_token') || null,
    user: JSON.parse(localStorage.getItem('gugu_user') || 'null'),
    currentPage: 'home',
    selectedDistrict: localStorage.getItem('gugu_district') || '',
    selectedCategory: 0,
    currentFilter: 'all',
    selectedListing: null,
    selectedRoom: null,
    categories: [],
    locations: {},
    imageFiles: [],
    detailImages: null,

    /* ─── API ─── */
    async api(endpoint, options = {}) {
        const headers = { ...options.headers };
        if (!(options.body instanceof FormData)) headers['Content-Type'] = 'application/json';
        if (this.token) headers['Authorization'] = `Bearer ${this.token}`;
        const res = await fetch(`${API_BASE}/${endpoint}`, { ...options, headers });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Ikosa ryabaye');
        return data;
    },

    setAuth(token, user) {
        this.token = token;
        this.user = user;
        localStorage.setItem('gugu_token', token);
        localStorage.setItem('gugu_user', JSON.stringify(user));
        if (user.district) {
            this.selectedDistrict = user.district;
            localStorage.setItem('gugu_district', user.district);
        }
        this.updateHeader();
    },

    logout() {
        this.api('auth.php?action=logout', { method: 'POST' }).catch(() => {});
        this.token = null;
        this.user = null;
        localStorage.removeItem('gugu_token');
        localStorage.removeItem('gugu_user');
        this.updateHeader();
        this.showPage('home');
    },

    updateHeader() {
        const guest = document.getElementById('hdr-guest');
        const userEl = document.getElementById('hdr-user');
        const memberOnly = ['hdr-purchases', 'hdr-bell'];
        if (this.user) {
            guest.style.display = 'none';
            userEl.style.display = 'flex';
            document.getElementById('hdr-avatar').textContent = this.user.full_name.charAt(0).toUpperCase();
            document.getElementById('hdr-name').textContent = this.user.full_name.split(' ')[0];
            memberOnly.forEach(id => { const el = document.getElementById(id); if (el) el.style.display = 'inline-flex'; });
            this.refreshBell();
        } else {
            guest.style.display = 'flex';
            userEl.style.display = 'none';
            memberOnly.forEach(id => { const el = document.getElementById(id); if (el) el.style.display = 'none'; });
        }
    },

    toast(msg, type = 'info') {
        const wrap = document.getElementById('toast-wrap');
        const t = document.createElement('div');
        t.className = `toast ${type === 'success' ? 'ok' : type === 'error' ? 'err' : ''}`;
        t.textContent = msg;
        wrap.appendChild(t);
        setTimeout(() => t.remove(), 3000);
    },

    /* ─── Navigation ─── */
    showPage(page, data = null) {
        document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
        document.getElementById(`page-${page}`)?.classList.add('active');
        this.currentPage = page;

        const loaders = {
            home: () => this.loadHome(),
            detail: () => this.loadDetail(data),
            sell: () => this.initSellForm(),
            chat: () => this.loadChats(),
            profile: () => this.loadProfile(),
            favorites: () => this.loadFavorites(),
            'my-listings': () => this.loadMyListings('active'),
            'user-profile': () => this.loadUserProfile(data),
            register: () => this.initRegisterForm(),
            purchases: () => this.loadPurchases(),
            inventory: () => this.loadInventory(),
            sales: () => this.loadSalesAnalytics(),
            notifications: () => this.loadNotifications(),
        };
        loaders[page]?.();
        window.scrollTo(0, 0);
    },

    goBack() {
        const map = {
            detail: 'home', sell: 'home', register: 'auth',
            favorites: 'home', 'my-listings': 'profile', 'user-profile': 'detail',
            purchases: 'profile', inventory: 'profile', sales: 'profile', notifications: 'home'
        };
        this.showPage(map[this.currentPage] || 'home');
    },

    /* ─── HOME (grid) ─── */
    async loadHome() {
        this.updateHomeTitle();
        await this.loadCategories();
        await this.populateDistrictSelect();

        const grid = document.getElementById('listings-grid');
        grid.innerHTML = '<div class="loading-wrap"><div class="spinner"></div></div>';

        const params = new URLSearchParams();
        if (this.selectedCategory) params.set('category', this.selectedCategory);
        if (this.selectedDistrict) params.set('district', this.selectedDistrict);
        const search = document.getElementById('search-input')?.value;
        if (search) params.set('search', search);
        if (this.currentFilter === 'free') params.set('free', '1');

        try {
            const data = await this.api(`listings.php?${params}`);
            this.renderGrid(data.listings, grid);
        } catch {
            grid.innerHTML = this.emptyHTML('📦', 'Nta gicuruzwa', 'Ba uwambere wo gushyira igicuruzwa!');
        }
    },

    updateHomeTitle() {
        const el = document.getElementById('home-title');
        if (!el) return;
        const loc = this.selectedDistrict || 'Rwanda';
        el.innerHTML = `${loc} <span>· Ibicuruzwa bya kabiri</span>`;
    },

    renderGrid(listings, container) {
        if (!listings.length) {
            container.innerHTML = this.emptyHTML('🔍', 'Nta gicuruzwa', 'Gerageza ahantu handi cyangwa shyira igicuruzwa cyawe.');
            return;
        }
        container.innerHTML = listings.map(l => `
            <article class="product-card" onclick="App.showPage('detail', ${l.id})">
                <div class="card-img">
                    ${l.primary_image
                        ? `<img src="${l.primary_image}" alt="" loading="lazy">`
                        : '<div class="placeholder">📦</div>'}
                </div>
                <div class="card-title">${this.esc(l.title)}</div>
                <div class="card-price ${l.is_free == 1 ? 'free' : ''}">${l.price_formatted}</div>
                <div class="card-meta">${this.esc(l.district)} · ${l.time_ago}</div>
            </article>
        `).join('');
    },

    emptyHTML(ico, title, msg) {
        return `<div class="empty-state"><div class="ico">${ico}</div><h3>${title}</h3><p>${msg}</p>
            <button class="btn btn-carrot" onclick="App.showPage('sell')">+ Shyira igicuruzwa</button></div>`;
    },

    async loadCategories() {
        if (!this.categories.length) {
            try {
                const data = await this.api('users.php?action=categories');
                this.categories = data.categories;
            } catch {}
        }
        const sidebar = document.getElementById('sidebar-cats');
        if (!sidebar) return;
        sidebar.innerHTML = this.categories.map(c => `
            <li class="${this.selectedCategory == c.id ? 'active' : ''}" onclick="App.selectCategory(${c.id})">
                ${CATEGORY_ICONS[c.icon] || '📦'} ${c.name_rw}
            </li>
        `).join('');
    },

    selectCategory(id) {
        this.selectedCategory = this.selectedCategory == id ? 0 : id;
        this.currentFilter = 'all';
        this.loadCategories();
        this.loadHome();
    },

    setFilter(filter) {
        this.currentFilter = filter;
        this.selectedCategory = 0;
        this.loadCategories();
        this.loadHome();
    },

    resetFilters() {
        this.selectedCategory = 0;
        this.selectedDistrict = '';
        this.currentFilter = 'all';
        localStorage.removeItem('gugu_district');
        const sel = document.getElementById('sidebar-district');
        if (sel) sel.value = '';
        this.loadCategories();
        this.loadHome();
    },

    selectDistrict(d) {
        this.selectedDistrict = d;
        localStorage.setItem('gugu_district', d);
        this.loadHome();
    },

    async populateDistrictSelect() {
        await this.loadLocations();
        const sel = document.getElementById('sidebar-district');
        if (!sel || sel.options.length > 1) return;
        Object.entries(this.locations).forEach(([prov, dists]) => {
            Object.keys(dists).forEach(d => {
                sel.innerHTML += `<option value="${d}" ${d === this.selectedDistrict ? 'selected' : ''}>${d}, ${prov}</option>`;
            });
        });
    },

    /* ─── DETAIL ─── */
    async loadDetail(id) {
        const content = document.getElementById('detail-content');
        content.innerHTML = '<div class="loading-wrap"><div class="spinner"></div></div>';

        try {
            const { listing: l } = await this.api(`listings.php?id=${id}`);
            const images = l.images || [];

            document.getElementById('detail-images').innerHTML = images.length
                ? `<img src="${images[0].url}" alt="" id="detail-main-img">`
                : '<div class="no-img">📦</div>';

            document.getElementById('detail-thumbs').innerHTML = images.length > 1
                ? images.map((img, i) => `<img src="${img.url}" class="${i === 0 ? 'active' : ''}" onclick="App.switchDetailImage(${i})">`).join('')
                : '';
            this.detailImages = images;

            content.innerHTML = `
                <h1>${this.esc(l.title)}</h1>
                <div class="price ${l.is_free == 1 ? 'free' : ''}">${l.price_formatted}</div>
                <div class="info">${CATEGORY_ICONS[l.category_icon] || '📦'} ${l.category_name} · ${l.district} · ${l.time_ago} · 👁 ${l.view_count}</div>
                <div class="seller-box" onclick="App.showPage('user-profile', ${l.user_id})">
                    <div class="seller-av">${l.seller_name.charAt(0)}</div>
                    <div class="seller-info">
                        <div class="name">${this.esc(l.seller_name)}</div>
                        <div class="loc">${this.esc(l.seller_district)}, ${this.esc(l.seller_province)}</div>
                    </div>
                    <div class="manner">⭐ ${Math.min(100, Math.max(1, Math.round((parseFloat(l.seller_manner) - 20) * 2.5)))}</div>
                </div>
                <div class="detail-desc">
                    <h3>Ibisobanuro</h3>
                    <p>${this.esc(l.description)}</p>
                </div>
                <div class="detail-actions">
                    ${l.is_owner ? `
                        <button class="btn btn-outline btn-block" onclick="App.updateListingStatus(${l.id},'sold')">✅ Byagurishijwe</button>
                        <button class="btn btn-carrot btn-block" onclick="App.updateListingStatus(${l.id},'reserved')">🔒 Byafashwe</button>
                    ` : `
                        ${l.is_free == 1 ? '' : `<button class="btn btn-carrot btn-block" onclick="App.buyNow(${l.id})">🛒 Gura ubu (escrow + MoMo)</button>`}
                        <button class="btn btn-outline btn-block" onclick="App.startChat(${l.id})">💬 Vugana n'umugurisha</button>
                        <button class="btn btn-outline btn-block" onclick="App.toggleFavorite(${l.id})">${l.is_favorited ? '❤️ Byakunzwe' : '🤍 Ongeramo mu byakunzwe'}</button>
                        <button class="link-btn" onclick="App.reportListing(${l.id})">🚩 Tanga raporo kuri iki gicuruzwa</button>
                    `}
                </div>
            `;
        } catch {
            content.innerHTML = this.emptyHTML('😔', 'Ntibibonetse', '');
        }
    },

    switchDetailImage(i) {
        if (!this.detailImages?.[i]) return;
        document.getElementById('detail-main-img').src = this.detailImages[i].url;
        document.querySelectorAll('.gallery-thumbs img').forEach((img, j) => img.classList.toggle('active', j === i));
    },

    async toggleFavorite(id) {
        if (!this.token) { this.showPage('auth'); return; }
        try {
            const data = await this.api('users.php?action=favorites', { method: 'POST', body: JSON.stringify({ listing_id: id }) });
            this.toast(data.favorited ? 'Byongewe mu byakunzwe ❤️' : 'Byakuwe mu byakunzwe', 'success');
            this.loadDetail(id);
        } catch (e) { this.toast(e.message, 'error'); }
    },

    async startChat(id) {
        if (!this.token) { this.showPage('auth'); return; }
        try {
            const data = await this.api('chat.php?action=rooms', { method: 'POST', body: JSON.stringify({ listing_id: id }) });
            this.selectedRoom = data.room_id;
            this.showPage('chat');
            this.openChatRoom(data.room_id);
        } catch (e) { this.toast(e.message, 'error'); }
    },

    async updateListingStatus(id, status) {
        try {
            await this.api(`listings.php?id=${id}`, { method: 'PUT', body: JSON.stringify({ status }) });
            this.toast('Byahinduwe neza ✅', 'success');
            this.loadDetail(id);
        } catch (e) { this.toast(e.message, 'error'); }
    },

    /* ─── SELL / UPLOAD ─── */
    initSellForm() {
        if (!this.token) { this.toast('Nyamuneka winjire mbere', 'error'); this.showPage('auth'); return; }
        this.imageFiles = [];
        document.getElementById('sell-form').reset();
        document.getElementById('sell-is-free').classList.remove('on');
        document.getElementById('sell-price-group').style.display = 'block';
        this.renderPhotoGrid();
        this.populateLocationSelects('sell');
        this.populateCategorySelect('sell-category');
    },

    renderPhotoGrid() {
        const grid = document.getElementById('photo-grid');
        let html = this.imageFiles.map((f, i) => `
            <div class="photo-slot">
                <img src="${URL.createObjectURL(f)}" alt="">
                ${i === 0 ? '<span class="cover-tag">Cover</span>' : ''}
                <button type="button" class="remove" onclick="App.removeImage(${i})">✕</button>
            </div>
        `).join('');
        if (this.imageFiles.length < 10) {
            html += `<div class="photo-slot photo-add" onclick="document.getElementById('sell-images').click()">
                <span class="icon">+</span><span>Ongeramo</span></div>`;
        }
        grid.innerHTML = html;
    },

    handleImageSelect(e) {
        Array.from(e.target.files).forEach(f => { if (this.imageFiles.length < 10) this.imageFiles.push(f); });
        e.target.value = '';
        this.renderPhotoGrid();
    },

    removeImage(i) {
        this.imageFiles.splice(i, 1);
        this.renderPhotoGrid();
    },

    toggleFree() {
        const t = document.getElementById('sell-is-free');
        t.classList.toggle('on');
        document.getElementById('sell-price-group').style.display = t.classList.contains('on') ? 'none' : 'block';
    },

    async submitListing(e) {
        e.preventDefault();
        const btn = document.getElementById('sell-submit-btn');
        btn.disabled = true;
        const fd = new FormData(e.target);
        fd.set('is_free', document.getElementById('sell-is-free').classList.contains('on') ? '1' : '0');
        this.imageFiles.forEach(f => fd.append('images[]', f));
        try {
            await this.api('listings.php', { method: 'POST', body: fd });
            this.toast('Igicuruzwa cyashyizweho! 🎉', 'success');
            this.showPage('home');
        } catch (err) { this.toast(err.message, 'error'); }
        finally { btn.disabled = false; }
    },

    /* ─── MY LISTINGS ─── */
    async loadMyListings(status = 'active', tabEl) {
        if (!this.token) { this.showPage('auth'); return; }
        if (tabEl) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('on'));
            tabEl.classList.add('on');
        }
        const grid = document.getElementById('my-listings-grid');
        grid.innerHTML = '<div class="loading-wrap"><div class="spinner"></div></div>';
        try {
            const params = new URLSearchParams({ user_id: this.user.id });
            if (status === 'sold') params.set('status', 'sold');
            const data = await this.api(`listings.php?${params}`);
            const listings = status === 'active'
                ? data.listings.filter(l => l.status === 'active' || l.status === 'reserved')
                : data.listings;
            this.renderGrid(listings, grid);
        } catch { grid.innerHTML = this.emptyHTML('📦', 'Nta gicuruzwa', ''); }
    },

    /* ─── CHAT ─── */
    async loadChats() {
        if (!this.token) { this.showPage('auth'); return; }
        const list = document.getElementById('chat-list');
        list.innerHTML = '<div style="padding:20px;text-align:center"><div class="spinner"></div></div>';
        try {
            const { rooms } = await this.api('chat.php?action=rooms');
            if (!rooms.length) {
                list.innerHTML = '<div style="padding:24px;text-align:center;color:#868B94;font-size:0.875rem">Nta butumwa</div>';
                return;
            }
            list.innerHTML = rooms.map(r => `
                <div class="chat-item ${this.selectedRoom == r.id ? 'active' : ''}" onclick="App.openChatRoom(${r.id})">
                    <div class="chat-thumb">${r.listing_image ? `<img src="${r.listing_image}">` : '📦'}</div>
                    <div class="chat-body">
                        <div class="name">${this.esc(r.other_name)}</div>
                        <div class="preview">${this.esc(r.last_message || r.listing_title)}</div>
                    </div>
                </div>
            `).join('');
            if (this.selectedRoom) this.openChatRoom(this.selectedRoom);
        } catch { list.innerHTML = '<div style="padding:24px">Ikosa</div>'; }
    },

    async openChatRoom(roomId) {
        this.selectedRoom = roomId;
        document.querySelectorAll('.chat-item').forEach(el => el.classList.remove('active'));
        const main = document.getElementById('chat-main');
        main.innerHTML = '<div style="padding:40px;text-align:center"><div class="spinner"></div></div>';
        try {
            const { messages } = await this.api(`chat.php?action=messages&room_id=${roomId}`);
            main.innerHTML = `
                <div class="chat-msgs" id="chat-msgs">${messages.length ? messages.map(m => `
                    <div class="bubble ${m.is_mine ? 'mine' : 'theirs'}">${this.esc(m.content)}</div>
                `).join('') : '<div style="text-align:center;color:#868B94;padding:40px">Tangira ikiganiro 👋</div>'}</div>
                <div class="chat-input-row">
                    <input type="text" id="chat-input" placeholder="Andika ubutumwa..." onkeypress="if(event.key==='Enter')App.sendMessage()">
                    <button class="chat-send" onclick="App.sendMessage()">➤</button>
                </div>
            `;
            const msgs = document.getElementById('chat-msgs');
            if (msgs) msgs.scrollTop = msgs.scrollHeight;
        } catch {}
    },

    async sendMessage() {
        const input = document.getElementById('chat-input');
        const content = input?.value.trim();
        if (!content) return;
        try {
            await this.api(`chat.php?action=messages&room_id=${this.selectedRoom}`, { method: 'POST', body: JSON.stringify({ content }) });
            this.openChatRoom(this.selectedRoom);
        } catch (e) { this.toast(e.message, 'error'); }
    },

    /* ─── PROFILE ─── */
    async loadProfile() {
        if (!this.token) { this.showPage('auth'); return; }
        try {
            const { user: u } = await this.api('users.php?action=profile');
            document.getElementById('profile-name').textContent = u.full_name;
            document.getElementById('profile-location').textContent = `${u.district}, ${u.province}`;
            document.getElementById('profile-avatar').textContent = u.full_name.charAt(0).toUpperCase();
            document.getElementById('stat-listings').textContent = u.active_listings;
            document.getElementById('stat-sold').textContent = u.sold_listings;
            document.getElementById('stat-favorites').textContent = u.favorites_count;

            const adminLink = document.getElementById('admin-portal-link');
            if (adminLink) {
                const staffRoles = ['moderator', 'district_manager', 'super_admin'];
                adminLink.style.display = staffRoles.includes(u.role) ? 'block' : 'none';
            }
        } catch {}
    },

    async loadFavorites() {
        if (!this.token) { this.showPage('auth'); return; }
        const grid = document.getElementById('favorites-grid');
        grid.innerHTML = '<div class="loading-wrap"><div class="spinner"></div></div>';
        try {
            const { favorites } = await this.api('users.php?action=favorites');
            this.renderGrid(favorites, grid);
        } catch {}
    },

    async loadUserProfile(userId) {
        const el = document.getElementById('user-profile-content');
        el.innerHTML = '<div class="loading-wrap"><div class="spinner"></div></div>';
        try {
            const data = await this.api(`users.php?action=user&id=${userId}`);
            const u = data.user;
            el.innerHTML = `
                <div class="profile-banner">
                    <div class="profile-av">${u.full_name.charAt(0)}</div>
                    <div class="profile-info">
                        <h2>${this.esc(u.full_name)}</h2>
                        <p>${u.district}, ${u.province} · ⭐ ${Math.min(100, Math.max(1, Math.round((parseFloat(u.manner_score) - 20) * 2.5)))} Icyizere</p>
                    </div>
                </div>
                <h2 class="page-title" style="margin-top:24px">Ibicuruzwa (${data.listings.length})</h2>
            `;
            const grid = document.createElement('div');
            grid.className = 'product-grid';
            grid.id = 'user-listings-grid';
            el.appendChild(grid);
            this.renderGrid(data.listings, grid);
        } catch {}
    },

    /* ─── MEMBER PORTAL: MY PURCHASES ─── */
    loadPurchases() {
        if (!this.token) { this.showPage('auth'); return; }
        this.purchasesTab(this.purchasesView || 'history');
    },

    purchasesTab(name, el) {
        this.purchasesView = name;
        ['history', 'track', 'wallet'].forEach(v => {
            const pane = document.getElementById(`purchases-${v}`);
            if (pane) pane.style.display = v === name ? 'block' : 'none';
        });
        if (el) {
            el.parentElement.querySelectorAll('.tab').forEach(t => t.classList.remove('on'));
            el.classList.add('on');
        }
        if (name === 'history') this.loadOrderHistory();
        if (name === 'track') this.loadTrackPicker();
        if (name === 'wallet') this.loadWallet();
    },

    async loadOrderHistory() {
        const wrap = document.getElementById('orders-list');
        wrap.innerHTML = '<div class="loading-wrap"><div class="spinner"></div></div>';
        try {
            const { orders } = await this.api('orders.php?action=purchases');
            if (!orders.length) {
                wrap.innerHTML = `<div class="empty-state"><div class="ico">🛍️</div><h3>Nta byo waguze</h3>
                    <p>Shakisha igicuruzwa maze ukande "Gura ubu"</p>
                    <button class="btn btn-carrot" onclick="App.showPage('home')">Reba ibicuruzwa</button></div>`;
                return;
            }
            wrap.innerHTML = orders.map(o => this.orderCardHTML(o)).join('');
        } catch (e) { wrap.innerHTML = `<div class="empty-state"><p>${this.esc(e.message)}</p></div>`; }
    },

    orderCardHTML(o) {
        const escrowLabel = {
            unpaid: '⏳ Ntibyishyuwe', held: '🔐 Muri escrow',
            released: '✅ Yasohotse', refunded: '↩️ Yasubijwe'
        }[o.escrow_status] || o.escrow_status;

        const actions = [];
        const closed = ['completed', 'cancelled', 'refunded'];
        if (o.escrow_status === 'unpaid' && !closed.includes(o.status)) {
            actions.push(`<button class="btn btn-carrot btn-sm" onclick="App.openCheckout(${o.id})">Ishyura na MoMo</button>`);
        }
        if (o.escrow_status === 'held' && o.status !== 'completed') {
            actions.push(`<button class="btn btn-carrot btn-sm" onclick="App.confirmOrder(${o.id})">Nakiriye igicuruzwa</button>`);
        }
        if (!closed.includes(o.status)) {
            actions.push(`<button class="btn btn-outline btn-sm" onclick="App.meetupOrder(${o.id})">Twateganije guhura</button>`);
            actions.push(`<button class="btn btn-outline btn-sm" onclick="App.disputeOrder(${o.id})">Tanga ikibazo</button>`);
            actions.push(`<button class="btn btn-outline btn-sm" onclick="App.cancelOrder(${o.id})">Hagarika</button>`);
        }

        return `
            <div class="order-card">
                <div class="order-thumb">${o.primary_image ? `<img src="${o.primary_image}" alt="">` : '📦'}</div>
                <div class="order-body">
                    <div class="order-title">${this.esc(o.listing_title)}</div>
                    <div class="order-meta">#${o.id} · ${this.esc(o.seller_name)} · ${o.time_ago}</div>
                    <div class="order-tags">
                        <span class="pill status-${o.status}">${o.status_label}</span>
                        <span class="pill">${escrowLabel}</span>
                    </div>
                </div>
                <div class="order-side">
                    <div class="order-amount">${o.amount_formatted}</div>
                    <div class="order-actions">${actions.join('')}</div>
                    <button class="link-btn" onclick="App.openTrack(${o.id})">📍 Kurikirana</button>
                </div>
            </div>`;
    },

    async loadTrackPicker() {
        const picker = document.getElementById('track-picker');
        picker.innerHTML = '<div class="loading-wrap"><div class="spinner"></div></div>';
        try {
            const { orders } = await this.api('orders.php?action=purchases');
            picker.innerHTML = orders.length
                ? orders.map(o => `
                    <div class="track-item" onclick="App.openTrack(${o.id})">
                        <div class="track-item-title">${this.esc(o.listing_title)}</div>
                        <div class="track-item-meta">#${o.id} · ${o.status_label}</div>
                    </div>`).join('')
                : '<div style="padding:20px;color:#868B94">Nta tumizwa</div>';
        } catch { picker.innerHTML = '<div style="padding:20px">Ikosa</div>'; }
    },

    async openTrack(orderId) {
        this.purchasesView = 'track';
        this.showPage('purchases');
        this.purchasesTab('track');
        document.querySelectorAll('#page-purchases .tabs .tab').forEach((t, i) => t.classList.toggle('on', i === 1));

        const box = document.getElementById('track-detail');
        box.innerHTML = '<div class="loading-wrap"><div class="spinner"></div></div>';
        try {
            const { order, events } = await this.api(`orders.php?action=track&id=${orderId}`);
            box.innerHTML = `
                <div class="track-head">
                    <h3>${this.esc(order.listing_title)}</h3>
                    <div class="order-meta">Itumizwa #${order.id} · ${order.amount_formatted}</div>
                    <div class="order-tags">
                        <span class="pill status-${order.status}">${order.status_label}</span>
                        ${order.track_code ? `<span class="pill">${this.esc(order.track_code)}</span>` : ''}
                    </div>
                </div>
                <div class="timeline">
                    ${events.map(ev => `
                        <div class="timeline-row">
                            <div class="dot"></div>
                            <div>
                                <div class="tl-status">${this.esc(ev.status_label)}</div>
                                <div class="tl-note">${this.esc(ev.note || '')}</div>
                                <div class="tl-time">${ev.time_ago}${ev.actor_name ? ' · ' + this.esc(ev.actor_name) : ''}</div>
                            </div>
                        </div>`).join('')}
                </div>`;
        } catch (e) { box.innerHTML = `<div class="empty-state"><p>${this.esc(e.message)}</p></div>`; }
    },

    async loadWallet() {
        const cards = document.getElementById('wallet-cards');
        const tx = document.getElementById('wallet-tx');
        cards.innerHTML = '<div class="loading-wrap"><div class="spinner"></div></div>';
        try {
            const { wallet, transactions } = await this.api('orders.php?action=wallet');
            cards.innerHTML = [
                ['🔐 Ari muri escrow', wallet.held_formatted, 'orange'],
                ['✅ Nakoresheje', wallet.spent_formatted, ''],
                ['💰 Nakuye mu kugurisha', wallet.earned_formatted, ''],
                ['↩️ Nasubijwe', wallet.refunded_formatted, ''],
            ].map(([label, val, cls]) => `
                <div class="metric-card ${cls}">
                    <div class="metric-label">${label}</div>
                    <div class="metric-value">${val}</div>
                </div>`).join('');

            tx.innerHTML = transactions.length ? transactions.map(t => `
                <tr>
                    <td>${t.time_ago}</td>
                    <td><span class="pill">${t.type}</span></td>
                    <td>${this.esc(t.listing_title || '—')}</td>
                    <td class="${t.flow === 'in' ? 'amt-in' : 'amt-out'}">${t.flow === 'in' ? '+' : '−'} ${t.amount_formatted}</td>
                </tr>`).join('') : '<tr><td colspan="4">Nta bikorwa bya escrow</td></tr>';
        } catch (e) {
            cards.innerHTML = `<div class="empty-state"><p>${this.esc(e.message)}</p></div>`;
        }
    },

    /* ─── MEMBER PORTAL: BUY & MOBILE MONEY ─── */
    async buyNow(listingId) {
        if (!this.token) { this.showPage('auth'); return; }
        try {
            const data = await this.api('orders.php?action=create', {
                method: 'POST', body: JSON.stringify({ listing_id: listingId })
            });
            this.openCheckout(data.order_id);
        } catch (e) { this.toast(e.message, 'error'); }
    },

    openCheckout(orderId) {
        this.checkoutOrder = orderId;
        document.getElementById('checkout-body').innerHTML = `
            <p class="modal-sub">Amafaranga abikwa muri <strong>escrow</strong> kugeza wemeje ko wakiriye igicuruzwa.</p>
            <div class="field">
                <label>Uburyo bwo kwishyura</label>
                <select id="checkout-method">
                    <option value="mtn_momo">MTN Mobile Money</option>
                    <option value="airtel_money">Airtel Money</option>
                    <option value="cash">Amafaranga mu ntoki (meetup)</option>
                </select>
            </div>
            <div class="field">
                <label>Nomero ya telefoni</label>
                <input type="tel" id="checkout-phone" value="${this.user?.phone || ''}" placeholder="0789999999">
            </div>
            <button class="btn btn-carrot btn-block" onclick="App.submitCheckout()">Emeza kwishyura</button>`;
        document.getElementById('checkout-modal').style.display = 'flex';
    },

    closeCheckout() {
        document.getElementById('checkout-modal').style.display = 'none';
    },

    async submitCheckout() {
        try {
            const data = await this.api('orders.php?action=pay', {
                method: 'POST',
                body: JSON.stringify({
                    order_id: this.checkoutOrder,
                    payment_method: document.getElementById('checkout-method').value,
                    phone: document.getElementById('checkout-phone').value
                })
            });
            this.closeCheckout();
            this.toast(`${data.message} (${data.payment_ref})`, 'success');
            this.showPage('purchases');
        } catch (e) { this.toast(e.message, 'error'); }
    },

    async orderAction(action, orderId, body = {}) {
        try {
            const data = await this.api(`orders.php?action=${action}`, {
                method: 'POST', body: JSON.stringify({ order_id: orderId, ...body })
            });
            this.toast(data.message, 'success');
            this.loadOrderHistory();
            this.refreshBell();
        } catch (e) { this.toast(e.message, 'error'); }
    },

    confirmOrder(id) {
        if (!confirm('Wemeza ko wakiriye igicuruzwa? Amafaranga azajya ku mugurisha.')) return;
        this.orderAction('confirm', id);
    },

    cancelOrder(id) {
        const reason = prompt('Impamvu yo guhagarika:');
        if (reason === null) return;
        this.orderAction('cancel', id, { reason });
    },

    meetupOrder(id) {
        const note = prompt('Andika aho muzahurira n\'igihe:');
        if (note === null) return;
        this.orderAction('meetup', id, { note });
    },

    disputeOrder(id) {
        const reason = prompt('Sobanura ikibazo (bizajya ku bayobozi):');
        if (!reason) return;
        this.orderAction('dispute', id, { reason });
    },

    async reportListing(listingId) {
        if (!this.token) { this.showPage('auth'); return; }
        const reason = prompt('Impamvu yo gutanga raporo kuri iki gicuruzwa:');
        if (!reason) return;
        try {
            const data = await this.api('users.php?action=report-listing', {
                method: 'POST', body: JSON.stringify({ listing_id: listingId, reason })
            });
            this.toast(data.message, 'success');
        } catch (e) { this.toast(e.message, 'error'); }
    },

    /* ─── MEMBER PORTAL: INVENTORY ─── */
    async loadInventory() {
        if (!this.token) { this.showPage('auth'); return; }
        const tbody = document.getElementById('inventory-table');
        tbody.innerHTML = '<tr><td colspan="6">Loading...</td></tr>';
        try {
            const data = await this.api(`listings.php?user_id=${this.user.id}&limit=50`);
            tbody.innerHTML = data.listings.length ? data.listings.map(l => `
                <tr>
                    <td>${this.esc(l.title)}</td>
                    <td>${l.price_formatted}</td>
                    <td>
                        <input type="number" min="0" value="${l.quantity ?? 1}" class="qty-input" id="qty-${l.id}">
                        <button class="btn-mini" onclick="App.saveQuantity(${l.id})">Bika</button>
                    </td>
                    <td><span class="pill status-${l.status}">${l.status}</span></td>
                    <td><span class="pill">${l.approval_status || 'approved'}</span></td>
                    <td>
                        <button class="btn-mini" onclick="App.setListingStatus(${l.id},'active')">Active</button>
                        <button class="btn-mini" onclick="App.setListingStatus(${l.id},'reserved')">Reserved</button>
                        <button class="btn-mini" onclick="App.setListingStatus(${l.id},'sold')">Sold</button>
                    </td>
                </tr>`).join('') : '<tr><td colspan="6">Nta gicuruzwa</td></tr>';
        } catch (e) { tbody.innerHTML = `<tr><td colspan="6">${this.esc(e.message)}</td></tr>`; }
    },

    async saveQuantity(id) {
        const quantity = parseInt(document.getElementById(`qty-${id}`).value, 10) || 0;
        try {
            await this.api(`listings.php?id=${id}`, { method: 'PUT', body: JSON.stringify({ quantity }) });
            this.toast('Ububiko bwavuguruwe ✅', 'success');
        } catch (e) { this.toast(e.message, 'error'); }
    },

    async setListingStatus(id, status) {
        try {
            await this.api(`listings.php?id=${id}`, { method: 'PUT', body: JSON.stringify({ status }) });
            this.toast('Byahinduwe ✅', 'success');
            this.loadInventory();
        } catch (e) { this.toast(e.message, 'error'); }
    },

    /* ─── MEMBER PORTAL: SALES ANALYTICS ─── */
    async loadSalesAnalytics() {
        if (!this.token) { this.showPage('auth'); return; }
        const cards = document.getElementById('sales-cards');
        cards.innerHTML = '<div class="loading-wrap"><div class="spinner"></div></div>';
        try {
            const { totals, monthly_orders, top_listings } = await this.api('orders.php?action=analytics');
            cards.innerHTML = [
                ['Ibicuruzwa byose', totals.listings, ''],
                ['Biragurishwa', totals.active, ''],
                ['Byagurishijwe', totals.sold, ''],
                ['Amatumizwa', totals.orders, ''],
                ['Kurebwa', totals.views, ''],
                ['Injiza', totals.revenue_formatted, 'orange'],
            ].map(([label, val, cls]) => `
                <div class="metric-card ${cls}">
                    <div class="metric-label">${label}</div>
                    <div class="metric-value">${val}</div>
                </div>`).join('');

            this.renderBars('sales-month-chart', monthly_orders);
            this.renderBars('sales-top-chart', top_listings);
        } catch (e) { cards.innerHTML = `<div class="empty-state"><p>${this.esc(e.message)}</p></div>`; }
    },

    renderBars(elementId, rows) {
        const el = document.getElementById(elementId);
        if (!el) return;
        if (!rows || !rows.length) { el.innerHTML = '<div class="chart-empty">Nta makuru arahari</div>'; return; }
        const max = Math.max(...rows.map(r => Number(r.value) || 0), 1);
        el.innerHTML = rows.map(r => `
            <div class="bar-row">
                <div class="bar-label" title="${this.esc(r.label)}">${this.esc(r.label)}</div>
                <div class="bar-track"><div class="bar-fill" style="width:${(Number(r.value) / max) * 100}%"></div></div>
                <div class="bar-value">${r.value}</div>
            </div>`).join('');
    },

    /* ─── MEMBER PORTAL: NOTIFICATIONS ─── */
    async loadNotifications() {
        if (!this.token) { this.showPage('auth'); return; }
        const list = document.getElementById('notif-list');
        list.innerHTML = '<div class="loading-wrap"><div class="spinner"></div></div>';
        try {
            const { notifications } = await this.api('notifications.php?action=list');
            list.innerHTML = notifications.length ? notifications.map(n => `
                <div class="notif-item ${n.is_read == 0 ? 'unread' : ''}" onclick="App.openNotification(${n.id}, '${n.link_type || ''}', ${n.link_id || 0})">
                    <div class="notif-title">${this.esc(n.title)}</div>
                    <div class="notif-body">${this.esc(n.body || '')}</div>
                    <div class="notif-time">${n.time_ago}</div>
                </div>`).join('')
                : '<div class="empty-state"><div class="ico">🔔</div><h3>Nta matangazo</h3><p>Uzabona amakuru y\'amatumizwa hano</p></div>';
        } catch (e) { list.innerHTML = `<div class="empty-state"><p>${this.esc(e.message)}</p></div>`; }
    },

    async openNotification(id, linkType, linkId) {
        try { await this.api('notifications.php?action=read', { method: 'POST', body: JSON.stringify({ id }) }); } catch {}
        this.refreshBell();
        if (linkType === 'order' && linkId) this.openTrack(linkId);
        else if (linkType === 'listing' && linkId) this.showPage('detail', linkId);
        else this.loadNotifications();
    },

    async markAllNotificationsRead() {
        try {
            await this.api('notifications.php?action=read-all', { method: 'POST' });
            this.loadNotifications();
            this.refreshBell();
        } catch (e) { this.toast(e.message, 'error'); }
    },

    async refreshBell() {
        if (!this.token) return;
        try {
            const { unread } = await this.api('notifications.php?action=count');
            const badge = document.getElementById('bell-badge');
            if (!badge) return;
            badge.textContent = unread;
            badge.style.display = unread > 0 ? 'inline-flex' : 'none';
        } catch {}
    },

    /* ─── AUTH ─── */
    async handleLogin(e) {
        e.preventDefault();
        const form = e.target;
        const btn = form.querySelector('[type=submit]');
        btn.disabled = true;
        try {
            const data = await this.api('auth.php?action=login', {
                method: 'POST', body: JSON.stringify({ phone: form.phone.value, password: form.password.value })
            });
            this.setAuth(data.token, data.user);
            this.toast('Murakaza neza! 👋', 'success');
            this.showPage('home');
        } catch (err) { this.toast(err.message, 'error'); }
        finally { btn.disabled = false; }
    },

    async handleRegister(e) {
        e.preventDefault();
        const form = e.target;
        const btn = form.querySelector('[type=submit]');
        btn.disabled = true;
        try {
            const data = await this.api('auth.php?action=register', {
                method: 'POST', body: JSON.stringify({
                    phone: form.phone.value, password: form.password.value,
                    full_name: form.full_name.value, province: form.province.value,
                    district: form.district.value
                })
            });
            this.setAuth(data.token, data.user);
            this.toast('Kwiyandikisha byagenze neza! 🎉', 'success');
            this.showPage('home');
        } catch (err) { this.toast(err.message, 'error'); }
        finally { btn.disabled = false; }
    },

    initRegisterForm() { this.populateLocationSelects('reg'); },

    /* ─── LOCATIONS ─── */
    async loadLocations() {
        if (!Object.keys(this.locations).length) {
            const data = await this.api('users.php?action=locations');
            this.locations = data.locations;
        }
    },

    populateLocationSelects(prefix) {
        this.loadLocations().then(() => {
            const prov = document.getElementById(`${prefix}-province`);
            if (!prov) return;
            prov.innerHTML = '<option value="">Hitamo intara</option>';
            Object.keys(this.locations).forEach(p => { prov.innerHTML += `<option value="${p}">${p}</option>`; });
            if (this.user) {
                prov.value = this.user.province;
                this.updateDistrictSelect(prefix, this.user.province, this.user.district);
            }
        });
    },

    updateDistrictSelect(prefix, province, selected) {
        const dist = document.getElementById(`${prefix}-district`);
        if (!dist) return;
        dist.innerHTML = '<option value="">Hitamo akarere</option>';
        if (province && this.locations[province]) {
            Object.keys(this.locations[province]).forEach(d => {
                dist.innerHTML += `<option value="${d}" ${d === selected ? 'selected' : ''}>${d}</option>`;
            });
        }
    },

    updateSectorSelect(prefix, province, district) {
        const sec = document.getElementById(`${prefix}-sector`);
        if (!sec) return;
        sec.innerHTML = '<option value="">Hitamo umurenge</option>';
        this.locations[province]?.[district]?.forEach(s => { sec.innerHTML += `<option value="${s}">${s}</option>`; });
    },

    populateCategorySelect(id) {
        this.loadCategories().then(() => {
            const sel = document.getElementById(id);
            if (!sel) return;
            sel.innerHTML = this.categories.filter(c => c.id > 0).map(c =>
                `<option value="${c.id}">${CATEGORY_ICONS[c.icon] || '📦'} ${c.name_rw}</option>`
            ).join('');
        });
    },

    esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; },

    init() {
        this.updateHeader();
        this.showPage(this.token && this.user ? 'home' : 'home');
        let t;
        document.getElementById('search-input')?.addEventListener('input', () => {
            clearTimeout(t); t = setTimeout(() => this.loadHome(), 400);
        });
        setInterval(() => this.refreshBell(), 60000);
    }
};

document.addEventListener('DOMContentLoaded', () => App.init());
