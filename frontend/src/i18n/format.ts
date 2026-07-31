import type { Lang } from './translations';

/** French labels for categories (DB has name_rw + name_en only) */
const CATEGORY_FR: Record<string, string> = {
  All: 'Tous',
  Electronics: 'Électronique',
  Furniture: 'Meubles',
  Fashion: 'Mode',
  Vehicles: 'Véhicules',
  'Real Estate': 'Immobilier',
  Sports: 'Sport',
  Food: 'Alimentation',
  Appliances: 'Électroménager',
  Others: 'Autres',
  Byose: 'Tous',
  Telefoni: 'Électronique',
  Imbaho: 'Meubles',
  Imyambaro: 'Mode',
  Imodoka: 'Véhicules',
  Inzu: 'Immobilier',
  Imikino: 'Sport',
  Ibiryo: 'Alimentation',
  Ibikoresho: 'Électroménager',
  Ibindi: 'Autres',
};

const PROVINCE_LABELS: Record<string, Record<Lang, string>> = {
  Kigali: { rw: 'Kigali', en: 'Kigali', fr: 'Kigali' },
  'Northern Province': { rw: 'Amajyaruguru', en: 'Northern Province', fr: 'Province du Nord' },
  'Southern Province': { rw: 'Amajyepfo', en: 'Southern Province', fr: 'Province du Sud' },
  'Eastern Province': { rw: 'Iburasirazuba', en: 'Eastern Province', fr: 'Province de l\'Est' },
  'Western Province': { rw: 'Iburengerazuba', en: 'Western Province', fr: 'Province de l\'Ouest' },
};

export function localizeProvince(province: string, lang: Lang): string {
  return PROVINCE_LABELS[province]?.[lang] ?? province;
}

export function localizeCategory(
  lang: Lang,
  nameRw?: string | null,
  nameEn?: string | null,
  fallback?: string | null,
): string {
  const rw = nameRw || fallback || '';
  const en = nameEn || fallback || rw;
  if (lang === 'rw') return rw || en;
  if (lang === 'fr') return CATEGORY_FR[en] || CATEGORY_FR[rw] || en;
  return en || rw;
}

export function formatPriceLocalized(price: number, isFree: boolean | number, lang: Lang): string {
  if (isFree || price === 0) {
    if (lang === 'en') return 'Free';
    if (lang === 'fr') return 'Gratuit';
    return 'Ubuntu';
  }
  return `${Number(price).toLocaleString('en-US')} FRW`;
}

export function formatTimeAgoLocalized(dateInput: string | undefined | null, lang: Lang): string {
  if (!dateInput) return '';
  const time = new Date(dateInput.replace(' ', 'T')).getTime();
  if (Number.isNaN(time)) return dateInput;
  const diff = Math.max(0, Math.floor((Date.now() - time) / 1000));

  if (lang === 'en') {
    if (diff < 60) return 'Just now';
    if (diff < 3600) return `${Math.floor(diff / 60)} min ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    if (diff < 604800) return `${Math.floor(diff / 86400)}d ago`;
    return new Date(time).toLocaleDateString('en-GB');
  }

  if (lang === 'fr') {
    if (diff < 60) return "À l'instant";
    if (diff < 3600) return `Il y a ${Math.floor(diff / 60)} min`;
    if (diff < 86400) return `Il y a ${Math.floor(diff / 3600)} h`;
    if (diff < 604800) return `Il y a ${Math.floor(diff / 86400)} j`;
    return new Date(time).toLocaleDateString('fr-FR');
  }

  // Kinyarwanda
  if (diff < 60) return 'Ubu noneho';
  if (diff < 3600) return `${Math.floor(diff / 60)} iminota ishize`;
  if (diff < 86400) return `${Math.floor(diff / 3600)} amasaha ashize`;
  if (diff < 604800) return `${Math.floor(diff / 86400)} iminsi ishize`;
  return new Date(time).toLocaleDateString('en-GB');
}

/** GUGU trust points (0–100) from stored user score */
export function formatTrustScore(raw: number | string | null | undefined): number {
  const score = parseFloat(String(raw ?? 36.5));
  if (Number.isNaN(score)) return 50;
  return Math.min(100, Math.max(1, Math.round((score - 20) * 2.5)));
}
