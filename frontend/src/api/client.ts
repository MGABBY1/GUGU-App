/**
 * GUGU API Client — PHP backend (XAMPP)
 */
const API = '/gugu-app/api';

function headers(json = true): HeadersInit {
  const h: HeadersInit = {};
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
  let data: { error?: string; success?: boolean } & T;
  try {
    data = text ? JSON.parse(text) : ({} as typeof data);
  } catch {
    throw new Error(res.ok ? 'Invalid server response' : `Server error (${res.status})`);
  }
  if (!res.ok) throw new Error(data.error || 'Ikosa ryabaye');
  return data;
}

export const api = {
  sendOtp: (phone: string) =>
    request<{ phone: string; expires_in: number; dev_otp?: string; message: string }>(
      `${API}/auth.php?action=send-otp`,
      { method: 'POST', body: JSON.stringify({ phone }) },
    ),

  verifyOtp: (phone: string, code: string) =>
    request<{
      token: string; user: User; is_new?: boolean;
      needs_profile?: boolean; needs_location?: boolean; needs_id_verification?: boolean; message: string;
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
      token: string; user: User; needs_profile?: boolean; needs_location?: boolean; needs_id_verification?: boolean;
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
    request<{ token: string; user: User; needs_location?: boolean; needs_id_verification?: boolean }>(
      `${API}/auth.php?action=register`,
      { method: 'POST', body: JSON.stringify(body) },
    ),

  submitId: (form: FormData) => {
    const token = localStorage.getItem('gugu_token');
    if (token && !form.has('token')) form.append('token', token);
    return request<{ user: User; message?: string; needs_id_verification?: boolean }>(
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
    }>(`${API}/listings.php`, {
      method: 'POST',
      body: form,
    });
  },

  announceFee: () =>
    request<{ fee_rwf: number; momo_number: string; momo_name: string; message: string }>(
      `${API}/users.php?action=announce-fee`,
    ),

  updateListing: (id: number, body: object) =>
    request(`${API}/listings.php?id=${id}`, { method: 'PUT', body: JSON.stringify(body) }),

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

  user: (id: number) =>
    request<{ user: User; listings: Listing[] }>(`${API}/users.php?action=user&id=${id}`),

  chatRooms: () =>
    request<{ rooms: ChatRoom[] }>(`${API}/chat.php?action=rooms`),

  messages: (roomId: number) =>
    request<{ messages: Message[]; room?: ChatRoom }>(`${API}/chat.php?action=messages&room_id=${roomId}`),

  sendMessage: (roomId: number, content: string) =>
    request(`${API}/chat.php?action=messages&room_id=${roomId}`, {
      method: 'POST', body: JSON.stringify({ content }),
    }),

  createRoom: (listingId: number) =>
    request<{ room_id: number }>(`${API}/chat.php?action=rooms`, {
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
  role_name?: string;
  role_label?: string;
  account_kind?: 'management' | 'member';
  account_status?: string;
  admin_district?: string;
  is_staff?: boolean;
  is_management?: boolean;
  is_member?: boolean;
  can_moderate?: boolean;
  can_manage_staff?: boolean;
  is_verified_member?: boolean;
  home_path?: string;
  needs_profile?: boolean;
  needs_location?: boolean;
  needs_id_verification?: boolean;
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
  /** System Administrator — System Control Center */
  SUPER_ADMIN: 1,
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
    case ROLE.SYSTEM_ADMIN: return 'System Administrator';
    case ROLE.DISTRICT_MANAGER: return 'District Manager';
    case ROLE.MODERATOR_SUPPORT: return 'Moderator / Support';
    case ROLE.MEMBER: return 'Member';
    default: return 'Guest';
  }
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
  time_ago: string;
  unread_count: number;
  my_deal_role?: 'buyer' | 'seller';
  my_deal_label?: string;
  price_formatted?: string;
}

export interface Message {
  id: number;
  content: string;
  is_mine: boolean;
  time_ago: string;
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
