/**
 * GUGU API Client — PHP backend (XAMPP)
 */
const API = '/gugu-app/api';

function currentApiLang(): 'rw' | 'en' | 'fr' {
  try {
    const s = localStorage.getItem('gugu_lang');
    if (s === 'rw' || s === 'en' || s === 'fr') return s;
  } catch { /* ignore */ }
  return 'rw';
}

/** Fallback map when API still returns legacy Kinyarwanda (or any language) text. */
const API_ERROR_I18N: Record<string, { rw: string; en: string; fr: string }> = {
  phone_invalid: {
    rw: 'Nomero ya telefoni ntabwo ari yo',
    en: 'Phone number is not valid',
    fr: 'Le numéro de téléphone est invalide',
  },
  phone_invalid_format: {
    rw: 'Nomero ya telefoni ntabwo ari yo (+2507XXXXXXXX)',
    en: 'Use a Rwanda phone like 078XXXXXXX',
    fr: 'Utilisez un numéro rwandais (078XXXXXXX)',
  },
  otp_invalid: {
    rw: 'OTP ntabwo ari yo',
    en: 'OTP code is incorrect',
    fr: 'Le code OTP est incorrect',
  },
  otp_expired: {
    rw: 'OTP yarangiye — saba indi',
    en: 'OTP expired — request a new one',
    fr: 'OTP expiré — demandez-en un nouveau',
  },
  login_failed: {
    rw: "Nomero cyangwa ijambo ry'ibanga ntabwo ari byo",
    en: 'Phone or password is incorrect',
    fr: 'Téléphone ou mot de passe incorrect',
  },
  password_or_otp: {
    rw: "Andika ijambo ry'ibanga cyangwa OTP",
    en: 'Enter your password or use OTP',
    fr: 'Entrez le mot de passe ou utilisez l’OTP',
  },
  account_suspended: {
    rw: 'Konti yawe yahagaritswe',
    en: 'Your account is suspended',
    fr: 'Votre compte est suspendu',
  },
  phone_taken: {
    rw: 'Iyi nomero ya telefoni isanzwe ikoreshwa',
    en: 'This phone number is already registered',
    fr: 'Ce numéro est déjà enregistré',
  },
  fill_all: {
    rw: 'Uzuza amakuru yose',
    en: 'Please fill in all required fields',
    fr: 'Veuillez remplir tous les champs obligatoires',
  },
  password_short: {
    rw: "Ijambo ry'ibanga rigomba kuba nibura inyuguti 6",
    en: 'Password must be at least 6 characters',
    fr: 'Le mot de passe doit contenir au moins 6 caractères',
  },
  login_required: {
    rw: 'Nyamuneka winjire mbere (Please login first)',
    en: 'Please log in first',
    fr: 'Veuillez vous connecter d’abord',
  },
  generic_error: {
    rw: 'Ikosa ryabaye',
    en: 'Something went wrong',
    fr: 'Une erreur s’est produite',
  },
  server_error: {
    rw: 'Ikosa rya seriveri',
    en: 'Server error',
    fr: 'Erreur serveur',
  },
};

function localizeApiError(message: string, errorKey?: string): string {
  const lang = currentApiLang();
  if (errorKey && API_ERROR_I18N[errorKey]) {
    return API_ERROR_I18N[errorKey][lang];
  }
  const trimmed = (message || '').trim();
  for (const entry of Object.values(API_ERROR_I18N)) {
    if (trimmed === entry.rw || trimmed === entry.en || trimmed === entry.fr) {
      return entry[lang];
    }
  }
  // Common legacy substrings
  if (/Nomero ya telefoni ntabwo ari yo/i.test(trimmed)) {
    return API_ERROR_I18N.phone_invalid[lang];
  }
  if (/OTP ntabwo ari yo/i.test(trimmed)) {
    return API_ERROR_I18N.otp_invalid[lang];
  }
  if (/Ikosa ryabaye/i.test(trimmed)) {
    return API_ERROR_I18N.generic_error[lang];
  }
  return trimmed || API_ERROR_I18N.generic_error[lang];
}

function headers(json = true): HeadersInit {
  const h: HeadersInit = {
    'X-Gugu-Lang': currentApiLang(),
    'Accept-Language': currentApiLang(),
  };
  if (json) h['Content-Type'] = 'application/json';
  const token = localStorage.getItem('gugu_token');
  if (token) {
    h['Authorization'] = `Bearer ${token}`;
    // XAMPP/Apache often strips Authorization — backup header
    h['X-Gugu-Token'] = token;
  }
  return h;
}

async function request<T>(url: string, opts: RequestInit = {}): Promise<T> {
  const isForm = typeof FormData !== 'undefined' && opts.body instanceof FormData;
  const res = await fetch(url, {
    ...opts,
    credentials: 'include',
    headers: { ...headers(!isForm), ...(opts.headers || {}) },
  });
  const text = await res.text();
  let data: { error?: string; error_key?: string; success?: boolean } & T;
  try {
    data = text ? JSON.parse(text) : ({} as typeof data);
  } catch {
    throw new Error(
      res.ok
        ? localizeApiError('Invalid server response')
        : `${localizeApiError('', 'server_error')} (${res.status})`,
    );
  }
  if (!res.ok) {
    throw new Error(localizeApiError(data.error || '', data.error_key || 'generic_error'));
  }
  return data;
}

export const api = {
  sendOtp: (phone: string, purpose: 'login' | 'register' | 'verify' = 'login') =>
    request<{ phone: string; expires_in: number; dev_otp?: string; message: string }>(
      `${API}/auth.php?action=send-otp`,
      { method: 'POST', body: JSON.stringify({ phone, purpose }) },
    ),

  confirmOtp: (phone: string, code: string) =>
    request<{ success: boolean; phone: string; message: string }>(
      `${API}/auth.php?action=confirm-otp`,
      { method: 'POST', body: JSON.stringify({ phone, code }) },
    ),

  verifyOtp: (phone: string, code: string) =>
    request<{
      token: string; user: User; is_new?: boolean;
      needs_profile?: boolean; needs_location?: boolean; needs_id_upload?: boolean; needs_id_verification?: boolean; message: string;
      is_staff?: boolean; redirect?: string;
    }>(`${API}/auth.php?action=verify-otp`, {
      method: 'POST', body: JSON.stringify({ phone, code }),
    }),

  completeProfile: (body: ProfileSetupBody) =>
    request<{ user: User; needs_location?: boolean }>(`${API}/auth.php?action=complete-profile`, {
      method: 'POST', body: JSON.stringify(body),
    }),

  verifyLocation: (lat: number, lng: number, place?: { district?: string; sector?: string; province?: string }) =>
    request<{ user: User; valid_days: number; in_rwanda: boolean; display_name?: string }>(
      `${API}/auth.php?action=verify-location`,
      {
        method: 'POST',
        body: JSON.stringify({
          lat,
          lng,
          district: place?.district,
          sector: place?.sector,
          province: place?.province,
        }),
      },
    ),

  login: (phone: string, password: string) =>
    request<{
      token: string; user: User; needs_profile?: boolean; needs_location?: boolean; needs_id_upload?: boolean; needs_id_verification?: boolean;
      is_staff?: boolean; redirect?: string;
    }>(
      `${API}/auth.php?action=login`,
      { method: 'POST', body: JSON.stringify({ phone, password, login: phone }) },
    ),

  openStaffPortal: () =>
    request<{ redirect: string; role_id: number; role_name: string }>(
      `${API}/auth.php?action=open-staff-portal`,
      { method: 'POST', body: JSON.stringify({}) },
    ),

  register: (body: RegisterBody & { otp: string }) =>
    request<{ token: string; user: User; needs_location?: boolean; needs_id_upload?: boolean; needs_id_verification?: boolean }>(
      `${API}/auth.php?action=register`,
      { method: 'POST', body: JSON.stringify(body) },
    ),

  submitId: (form: FormData) => {
    const token = localStorage.getItem('gugu_token');
    if (token && !form.has('token')) form.append('token', token);
    return request<{ user: User; message?: string; needs_id_upload?: boolean; needs_id_verification?: boolean }>(
      `${API}/auth.php?action=submit-id`,
      { method: 'POST', body: form },
    );
  },

  me: () => request<{ user: User }>(`${API}/auth.php?action=me`),

  listings: (params?: Record<string, string>) => {
    const q = params ? '?' + new URLSearchParams(params).toString() : '';
    return request<{ listings: Listing[]; pagination?: { total: number } }>(`${API}/listings.php${q}`);
  },

  listing: (id: number) =>
    request<{ listing: ListingDetail }>(`${API}/listings.php?id=${id}`),

  createListing: (form: FormData) => {
    const token = localStorage.getItem('gugu_token');
    if (token && !form.has('token')) form.append('token', token);
    return request<{
      listing_id: number;
      success?: boolean;
      message?: string;
      pending_approval?: boolean;
      announce_fee_rwf?: number;
      momo_number?: string;
      momo_name?: string;
      image_count?: number;
    }>(`${API}/listings.php`, {
      method: 'POST',
      body: form,
    });
  },

  addListingImages: (id: number, form: FormData) => {
    const token = localStorage.getItem('gugu_token');
    if (token && !form.has('token')) form.append('token', token);
    return request<{ success: boolean; message?: string; image_count?: number; primary_image?: string }>(
      `${API}/listings.php?id=${id}&action=add-images`,
      { method: 'POST', body: form },
    );
  },

  announceFee: () =>
    request<{ fee_rwf: number; momo_number: string; momo_name: string; message: string }>(
      `${API}/users.php?action=announce-fee`,
    ),

  updateListing: (id: number, body: object) =>
    request<{ success?: boolean; message?: string; status?: string }>(`${API}/listings.php?id=${id}`, {
      method: 'PUT', body: JSON.stringify(body),
    }),

  deleteListing: (id: number) =>
    request<{ success?: boolean; message?: string }>(`${API}/listings.php?id=${id}`, {
      method: 'DELETE',
    }),

  categories: () =>
    request<{ categories: Category[] }>(`${API}/users.php?action=categories`),

  locations: () =>
    request<{ locations: Record<string, Record<string, string[]>> }>(`${API}/users.php?action=locations`),

  favorites: () =>
    request<{ favorites: Listing[] }>(`${API}/users.php?action=favorites`),

  toggleFavorite: (listingId: number) =>
    request<{ favorited: boolean }>(`${API}/users.php?action=favorites`, {
      method: 'POST', body: JSON.stringify({ listing_id: listingId }),
    }),

  profile: () =>
    request<{ user: User & ProfileStats }>(`${API}/users.php?action=profile`),

  updateProfile: (body: {
    nickname?: string;
    full_name?: string;
    real_name?: string;
    email?: string;
    bio?: string;
    province?: string;
    district?: string;
    sector?: string;
  }) =>
    request<{ user: User; message?: string }>(`${API}/users.php?action=profile`, {
      method: 'PUT',
      body: JSON.stringify(body),
    }),

  user: (id: number) =>
    request<{ user: User; listings: Listing[] }>(`${API}/users.php?action=user&id=${id}`),

  chatRooms: () =>
    request<{ rooms: ChatRoom[] }>(`${API}/chat.php?action=rooms`),

  messages: (roomId: number) =>
    request<{ messages: Message[]; room?: ChatRoom }>(`${API}/chat.php?action=messages&room_id=${roomId}`),

  sendMessage: (roomId: number, content: string) =>
    request<{ success: boolean; message: Message }>(`${API}/chat.php?action=messages&room_id=${roomId}`, {
      method: 'POST', body: JSON.stringify({ content }),
    }),

  createRoom: (listingId: number) =>
    request<{ room_id: number; is_job?: boolean; existing?: boolean }>(`${API}/chat.php?action=rooms`, {
      method: 'POST', body: JSON.stringify({ listing_id: listingId }),
    }),

  // Admin console
  adminOverview: () =>
    request<{ overview: AdminOverview }>(`${API}/admin.php?action=overview`),
  adminUsers: (params?: Record<string, string>) => {
    const q = params ? '&' + new URLSearchParams(params).toString() : '';
    return request<{ users: AdminUserRow[] }>(`${API}/admin.php?action=users${q}`);
  },
  adminSetRole: (body: { user_id: number; role_id: number; admin_district?: string }) =>
    request(`${API}/admin.php?action=set-role`, { method: 'POST', body: JSON.stringify(body) }),
  adminSetStatus: (body: { user_id: number; account_status: string }) =>
    request(`${API}/admin.php?action=set-status`, { method: 'POST', body: JSON.stringify(body) }),
  adminListings: (params?: Record<string, string>) => {
    const q = params ? '&' + new URLSearchParams(params).toString() : '';
    return request<{ listings: AdminListingRow[] }>(`${API}/admin.php?action=listings${q}`);
  },
  adminModerateListing: (body: { listing_id: number; moderation_status: string }) =>
    request(`${API}/admin.php?action=moderate-listing`, { method: 'POST', body: JSON.stringify(body) }),
  adminReports: (status = 'open') =>
    request<{ reports: AdminReportRow[] }>(`${API}/admin.php?action=reports&status=${status}`),
  adminResolveReport: (body: { report_id: number; status: string; resolution_note?: string }) =>
    request(`${API}/admin.php?action=resolve-report`, { method: 'POST', body: JSON.stringify(body) }),
  staffDirectory: () =>
    request<{
      staff: {
        id: number;
        nickname: string;
        district: string;
        sector?: string;
        role_id: number;
        role_label: string;
        account_status: string;
        admin_district?: string;
        is_you?: boolean;
      }[];
      viewer_role_label: string;
    }>(`${API}/admin.php?action=staff-directory`),
  createReport: (body: { target_type: string; target_id: number; reason: string; details?: string }) =>
    request(`${API}/admin.php?action=create-report`, { method: 'POST', body: JSON.stringify(body) }),
};

export interface AdminOverview {
  users_total: number;
  listings_active: number;
  listings_needs_review: number;
  reports_open: number;
  by_role: { role_id: number; role_name: string; count: number }[];
  scope_district?: string | null;
  actor_role?: string;
}

export interface AdminUserRow {
  id: number;
  phone: string;
  nickname?: string;
  full_name?: string;
  district: string;
  sector?: string;
  role_id: number;
  role_name: string;
  account_status: string;
  admin_district?: string;
  manner_score: number;
}

export interface AdminListingRow {
  id: number;
  title: string;
  price: number;
  district: string;
  sector?: string;
  status: string;
  moderation_status: string;
  nickname?: string;
  phone?: string;
  seller_id: number;
  created_at: string;
}

export interface AdminReportRow {
  id: number;
  target_type: string;
  target_id: number;
  reason: string;
  details?: string;
  status: string;
  created_at: string;
}

export interface User {
  id: number;
  phone: string;
  full_name?: string;
  nickname?: string;
  display_name?: string;
  email?: string;
  province: string;
  district: string;
  sector?: string;
  manner_score: number;
  manner_count: number;
  role_id?: number;
  /** Real account role when portal view overlay is active (e.g. Admin → DM). */
  actual_role_id?: number;
  role_name?: string;
  role_label?: string;
  account_kind?: 'management' | 'member';
  account_status?: string;
  admin_district?: string;
  is_staff?: boolean;
  is_management?: boolean;
  is_member?: boolean;
  is_admin?: boolean;
  can_moderate?: boolean;
  can_manage_staff?: boolean;
  can_manage_roles?: boolean;
  can_system_controls?: boolean;
  can_view_all_districts?: boolean;
  /** True when marketplace is showing DM/Moderator portal handoff identity. */
  portal_view_active?: boolean;
  /** Sticky Admin preview from PHP session (role 2|3). */
  portal_view?: { role: number; district?: string };
  is_verified_member?: boolean;
  home_path?: string;
  needs_profile?: boolean;
  needs_location?: boolean;
  needs_id_upload?: boolean;
  needs_id_verification?: boolean;
  id_verified?: boolean;
  phone_verified?: boolean;
  id_status?: 'none' | 'pending' | 'approved' | 'rejected';
  id_ok?: boolean;
  id_number?: string;
  id_document_url?: string;
  id_reject_reason?: string;
  location_ok?: boolean;
  location_days_left?: number;
  location_lat?: number;
  location_lng?: number;
  location_verified_at?: string;
}

/** GUGU Management System Users + Member */
export const ROLE = {
  /** Admin — full system control (former Super Admin) */
  ADMIN: 1,
  SUPER_ADMIN: 1, // alias
  SYSTEM_ADMIN: 1,
  /** District Manager — District Operations Hub */
  REGIONAL_ADMIN: 2,
  REGIONAL_MANAGER: 2,
  DISTRICT_MANAGER: 2,
  DISTRICT_ADMIN: 2,
  /** Moderator / Support — Trust & Safety Desk */
  SUPPORT: 3,
  MODERATOR_SUPPORT: 3,
  MODERATOR: 3,
  VERIFIED_USER: 4,
  MEMBER: 4,
  GUEST: 5,
} as const;

export function roleLabel(roleId?: number): string {
  switch (roleId) {
    case ROLE.SYSTEM_ADMIN: return 'Admin';
    case ROLE.DISTRICT_MANAGER: return 'District Manager';
    case ROLE.MODERATOR_SUPPORT: return 'Moderator';
    case ROLE.MEMBER: return 'Member';
    default: return 'Guest';
  }
}

function currentUiLang(): 'rw' | 'en' | 'fr' {
  try {
    const s = localStorage.getItem('gugu_lang');
    if (s === 'rw' || s === 'en' || s === 'fr') return s;
  } catch { /* ignore */ }
  return 'rw';
}

/** Active marketplace role: sticky portal preview wins over DB role_id. */
export function displayRoleId(user?: User | null): number {
  const view = getPortalView();
  if (view?.role === 2 || view?.role === 3) return view.role;
  if (user?.portal_view_active && (user.role_id === 2 || user.role_id === 3)) {
    return Number(user.role_id);
  }
  return Number(user?.role_id ?? ROLE.GUEST);
}

/** Staff profiles show role title in the selected app language, not DB nickname. */
export function identityTitle(user?: User | null): string {
  if (!user) return '';
  const roleId = displayRoleId(user);
  if (isStaffUser(user) || getPortalView() || user.portal_view_active) {
    // Lazy import avoided — map via roleLabel keys kept in sync with i18n
    const lang = currentUiLang();
    const labels: Record<number, Record<'rw' | 'en' | 'fr', string>> = {
      1: { rw: 'Admin', en: 'Admin', fr: 'Admin' },
      2: { rw: "Umuyobozi w'Akarere", en: 'District Manager', fr: 'Responsable de district' },
      3: { rw: 'Moderator', en: 'Moderator', fr: 'Modérateur' },
      4: { rw: 'Umunyamuryango', en: 'Member', fr: 'Membre' },
    };
    return labels[roleId]?.[lang] || roleLabel(roleId);
  }
  return user.nickname || user.full_name || user.display_name || 'Member';
}

export function identityPlace(user?: User | null): string {
  if (!user) return '';
  const view = getPortalView();
  const district = view?.district || user.admin_district || '';
  if ((isStaffUser(user) || view || user.portal_view_active) && district) {
    return district;
  }
  return [user.sector, user.district].filter(Boolean).join(', ');
}

/** Sticky DM/Moderator view while browsing marketplace from staff portal. */
export type PortalView = { role: 2 | 3; district: string };

const PORTAL_VIEW_KEY = 'gugu_portal_view';

export function getPortalView(): PortalView | null {
  try {
    const raw = sessionStorage.getItem(PORTAL_VIEW_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw) as { role?: number; district?: string };
    const role = Number(parsed.role);
    if (role !== 2 && role !== 3) return null;
    return { role: role as 2 | 3, district: String(parsed.district || '').trim() };
  } catch {
    return null;
  }
}

export function setPortalView(view: PortalView | null): void {
  try {
    if (!view || (view.role !== 2 && view.role !== 3)) {
      sessionStorage.removeItem(PORTAL_VIEW_KEY);
      return;
    }
    sessionStorage.setItem(PORTAL_VIEW_KEY, JSON.stringify({
      role: view.role,
      district: view.district || '',
    }));
  } catch { /* ignore */ }
}

export function clearPortalView(): void {
  setPortalView(null);
}

/** Read ?as_portal=&as_district= from URL, persist, and clean query. */
export function capturePortalViewFromUrl(): PortalView | null {
  try {
    const q = new URLSearchParams(window.location.search);
    if (q.get('clear_portal_view') === '1') {
      clearPortalView();
      q.delete('clear_portal_view');
      const next = `${window.location.pathname}${q.toString() ? `?${q}` : ''}${window.location.hash}`;
      window.history.replaceState(null, '', next);
      return null;
    }
    const role = Number(q.get('as_portal') || 0);
    if (role === 2 || role === 3) {
      const district = String(q.get('as_district') || '').trim();
      const view: PortalView = { role: role as 2 | 3, district };
      setPortalView(view);
      q.delete('as_portal');
      q.delete('as_district');
      const next = `${window.location.pathname}${q.toString() ? `?${q}` : ''}${window.location.hash}`;
      window.history.replaceState(null, '', next);
      return view;
    }
  } catch { /* ignore */ }
  return getPortalView();
}

export function portalWorkspaceLabel(role: 2 | 3): string {
  return role === 2 ? 'District Operations Hub' : 'Trust & Safety Desk';
}

export function portalReturnUrl(view?: PortalView | null): string {
  const v = view ?? getPortalView();
  if (!v) return '/gugu-app/admin/dashboard.php';
  const qs = new URLSearchParams();
  qs.set('view_role', String(v.role));
  if (v.district) qs.set('view_district', v.district);
  return `/gugu-app/admin/dashboard.php?${qs.toString()}`;
}

/**
 * Display-only overlay: Admin browsing marketplace as DM/Moderator.
 * Server auth still uses real role_id from the API.
 */
export function applyPortalViewUser(user: User | null | undefined): User | null {
  if (!user) return null;
  const apiView = user.portal_view;
  if (apiView && (Number(apiView.role) === 2 || Number(apiView.role) === 3)) {
    setPortalView({
      role: Number(apiView.role) as 2 | 3,
      district: String(apiView.district || '').trim(),
    });
  }
  const view = getPortalView();
  if (!view) return user;
  const actual = Number(user.actual_role_id ?? user.role_id ?? 0);
  // Only overlay when real account is Admin (or already staff) entering via portal handoff.
  if (actual !== ROLE.ADMIN && Number(user.role_id) !== ROLE.ADMIN && !user.is_admin) {
    // Real DM/Moderator: still apply district label if handoff set it.
    if (Number(user.role_id) === view.role) {
      return {
        ...user,
        admin_district: view.district || user.admin_district,
        role_label: roleLabel(view.role),
        role_name: roleLabel(view.role),
        portal_view_active: true,
      };
    }
    return user;
  }
  return {
    ...user,
    actual_role_id: actual || ROLE.ADMIN,
    role_id: view.role,
    role_label: roleLabel(view.role),
    role_name: roleLabel(view.role),
    admin_district: view.district || user.admin_district,
    is_admin: false,
    can_manage_staff: false,
    can_manage_roles: false,
    can_system_controls: false,
    can_view_all_districts: false,
    portal_view_active: true,
  };
}

export function isStaffUser(u?: User | null): boolean {
  if (!u) return false;
  if (u.is_staff === true || u.is_management === true || u.account_kind === 'management') return true;
  const r = Number(u.role_id ?? ROLE.GUEST);
  return r >= ROLE.SUPER_ADMIN && r <= ROLE.SUPPORT;
}

/** Alias: management users (portals) */
export function isManagementUser(u?: User | null): boolean {
  return isStaffUser(u);
}

export function isMemberUser(u?: User | null): boolean {
  return u?.is_member === true || u?.account_kind === 'member' || (u?.role_id === ROLE.MEMBER);
}

/** Member must upload / re-upload national ID (none or rejected). */
export function needsIdUpload(u?: User | null): boolean {
  if (!u || isStaffUser(u)) return false;
  if (u.needs_id_upload === true) return true;
  const st = u.id_status || 'none';
  return st === 'none' || st === 'rejected';
}

/** Member ID approved by Admin — required to sell / post jobs. */
export function hasApprovedId(u?: User | null): boolean {
  if (!u) return false;
  if (isStaffUser(u)) return true;
  return u.id_verified === true || u.id_status === 'approved' || u.id_ok === true;
}

export interface ProfileSetupBody {
  nickname: string;
  real_name?: string;
  email?: string;
  province: string;
  district: string;
  sector?: string;
}

export interface ProfileStats {
  active_listings: number;
  sold_listings: number;
  favorites_count: number;
  pending_listings?: number;
  rejected_listings?: number;
}

export interface RegisterBody {
  phone: string;
  password: string;
  otp: string;
  full_name: string;
  nickname?: string;
  email?: string;
  province: string;
  district: string;
  sector?: string;
}

export interface Category {
  id: number;
  name_rw: string;
  name_en: string;
  icon: string;
}

export interface Listing {
  id: number;
  title: string;
  price: number;
  price_formatted: string;
  is_free: number;
  status: string;
  moderation_status?: string;
  payment_status?: string;
  district: string;
  sector?: string;
  created_at?: string;
  time_ago: string;
  primary_image?: string;
  is_favorited?: boolean;
  category_name?: string;
  category_name_rw?: string;
  category_name_en?: string;
  category_icon?: string;
  like_count?: number;
  chat_count?: number;
}

export interface ListingDetail extends Listing {
  description: string;
  view_count: number;
  user_id: number;
  category_id?: number;
  seller_name: string;
  seller_display?: string;
  seller_manner: number;
  seller_district: string;
  seller_sector?: string;
  seller_province: string;
  seller_phone?: string;
  is_favorited?: boolean;
  category_name: string;
  category_name_rw?: string;
  category_name_en?: string;
  category_icon: string;
  is_owner?: boolean;
  my_deal_role?: 'buyer' | 'seller' | null;
  my_deal_label?: string | null;
  images?: { url: string }[];
}

export interface ChatRoom {
  id: number;
  other_name: string;
  listing_title: string;
  listing_image?: string;
  last_message?: string;
  last_message_at?: string;
  created_at?: string;
  time_ago?: string;
  unread_count: number;
  my_deal_role?: 'buyer' | 'seller';
  my_deal_label?: string;
  price_formatted?: string;
  is_job?: boolean;
  listing_id?: number;
}

export interface Message {
  id: number;
  content: string;
  is_mine: boolean;
  is_read?: boolean | number;
  time_ago?: string;
  created_at?: string;
  sender_name?: string;
}

export const CATEGORY_ICONS: Record<string, string> = {
  home: '🏠', phone: '📱', couch: '🛋️', shirt: '👕', car: '🚗',
  house: '🏡', ball: '⚽', food: '🍎', plug: '🔌', box: '📦', job: '🔍',
};

/** Called after login/register to refresh items on home page */
export const AUTH_HOME_EVENT = 'gugu:go-home';

export function goToItemsPage() {
  // Members keep their stay-district feed; do not force nationwide
  window.dispatchEvent(new Event(AUTH_HOME_EVENT));
}
