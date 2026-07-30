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
        if (this.user) {
            guest.style.display = 'none';
            userEl.style.display = 'flex';
            document.getElementById('hdr-avatar').textContent = this.user.full_name.charAt(0).toUpperCase();
            document.getElementById('hdr-name').textContent = this.user.full_name.split(' ')[0];
        } else {
            guest.style.display = 'flex';
            userEl.style.display = 'none';
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
        };
        loaders[page]?.();
        window.scrollTo(0, 0);
    },

    goBack() {
        const map = {
            detail: 'home', sell: 'home', register: 'auth',
            favorites: 'home', 'my-listings': 'profile', 'user-profile': 'detail'
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
                        <button class="btn btn-carrot btn-block" onclick="App.startChat(${l.id})">💬 Vugana n'umugurisha</button>
                        <button class="btn btn-outline btn-block" onclick="App.toggleFavorite(${l.id})">${l.is_favorited ? '❤️ Byakunzwe' : '🤍 Ongeramo mu byakunzwe'}</button>
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
    }
};

document.addEventListener('DOMContentLoaded', () => App.init());
