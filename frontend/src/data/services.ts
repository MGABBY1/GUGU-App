/** Marketplace navigation helpers (Karrot-style Services → filtered home) */

export type HomeFilter = {
  category?: number;
  search?: string;
  title?: string;
  nearMe?: boolean;
};

const FILTER_KEY = 'gugu_home_filter';
const RECENT_KEY = 'gugu_recent_views';
export const MAX_RECENT = 30;

/** Jobs category id (Akazi) — Karrot-style part-time jobs */
export const JOBS_CATEGORY_ID = 11;

export function setHomeFilter(filter: HomeFilter) {
  sessionStorage.setItem(FILTER_KEY, JSON.stringify(filter));
  window.dispatchEvent(new CustomEvent('gugu:home-filter', { detail: filter }));
}

export function consumeHomeFilter(): HomeFilter | null {
  const raw = sessionStorage.getItem(FILTER_KEY);
  if (!raw) return null;
  sessionStorage.removeItem(FILTER_KEY);
  try {
    return JSON.parse(raw) as HomeFilter;
  } catch {
    return null;
  }
}

export type RecentItem = {
  id: number;
  title: string;
  price: number;
  price_formatted?: string;
  is_free?: number | boolean;
  district?: string;
  sector?: string;
  primary_image?: string;
  viewed_at: number;
};

export function trackRecentView(item: Omit<RecentItem, 'viewed_at'>) {
  try {
    const prev = getRecentViews().filter(x => x.id !== item.id);
    const next: RecentItem[] = [{ ...item, viewed_at: Date.now() }, ...prev].slice(0, MAX_RECENT);
    localStorage.setItem(RECENT_KEY, JSON.stringify(next));
  } catch { /* ignore quota */ }
}

export function getRecentViews(): RecentItem[] {
  try {
    const raw = localStorage.getItem(RECENT_KEY);
    if (!raw) return [];
    const list = JSON.parse(raw) as RecentItem[];
    return Array.isArray(list) ? list : [];
  } catch {
    return [];
  }
}

export function clearRecentViews() {
  localStorage.removeItem(RECENT_KEY);
}
