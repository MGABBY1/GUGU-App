import { ALL_DISTRICTS, provinceForDistrict, RWANDA_PROVINCES, sectorsForDistrict } from './rwanda';
import { BRAND_NAME } from '../i18n/translations';

/** Approximate district centers (lat, lng) for nearest-match fallback */
export const DISTRICT_CENTROIDS: Record<string, [number, number]> = {
  Gasabo: [-1.9200, 30.1200],
  Kicukiro: [-1.9800, 30.1100],
  Nyarugenge: [-1.9500, 30.0600],
  Burera: [-1.4500, 29.7500],
  Gakenke: [-1.7000, 29.8000],
  Gicumbi: [-1.5800, 30.1000],
  Musanze: [-1.5000, 29.6000],
  Rulindo: [-1.7500, 30.0000],
  Gisagara: [-2.5500, 29.8500],
  Huye: [-2.6000, 29.7500],
  Kamonyi: [-2.0000, 29.9000],
  Muhanga: [-2.0800, 29.7500],
  Nyamagabe: [-2.4000, 29.5000],
  Nyanza: [-2.3500, 29.7500],
  Nyaruguru: [-2.7000, 29.5000],
  Ruhango: [-2.2000, 29.8000],
  Bugesera: [-2.2000, 30.1500],
  Gatsibo: [-1.6000, 30.4500],
  Kayonza: [-1.9000, 30.5000],
  Kirehe: [-2.2500, 30.6500],
  Ngoma: [-2.1500, 30.5500],
  Nyagatare: [-1.3000, 30.3000],
  Rwamagana: [-1.9500, 30.4500],
  Karongi: [-2.1500, 29.3500],
  Ngororero: [-1.8500, 29.5500],
  Nyabihu: [-1.6500, 29.5000],
  Nyamasheke: [-2.3500, 29.1000],
  Rubavu: [-1.7000, 29.3000],
  Rusizi: [-2.5000, 28.9000],
  Rutsiro: [-1.9500, 29.3500],
};

export { sectorsForDistrict } from './rwanda';

export type GeoSuggestion = {
  lat: number;
  lng: number;
  district: string;
  province: string;
  sector: string;
  label: string;
  source: 'gps' | 'manual';
  in_rwanda: boolean;
  accuracy_m?: number;
};

function haversineKm(lat1: number, lng1: number, lat2: number, lng2: number): number {
  const R = 6371;
  const dLat = ((lat2 - lat1) * Math.PI) / 180;
  const dLng = ((lng2 - lng1) * Math.PI) / 180;
  const a =
    Math.sin(dLat / 2) ** 2 +
    Math.cos((lat1 * Math.PI) / 180) * Math.cos((lat2 * Math.PI) / 180) * Math.sin(dLng / 2) ** 2;
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

/** Rwanda approximate bounding box (same as backend) */
export function isInRwanda(lat: number, lng: number): boolean {
  return lat >= -2.9 && lat <= -1.0 && lng >= 28.8 && lng <= 30.9;
}

export function nearestDistrict(lat: number, lng: number): string {
  let best = 'Gasabo';
  let bestDist = Infinity;
  for (const [name, [dLat, dLng]] of Object.entries(DISTRICT_CENTROIDS)) {
    const d = haversineKm(lat, lng, dLat, dLng);
    if (d < bestDist) {
      bestDist = d;
      best = name;
    }
  }
  return best;
}

function matchName(haystack: string, names: string[]): string {
  const h = haystack.toLowerCase();
  for (const n of names) {
    if (h.includes(n.toLowerCase())) return n;
  }
  return '';
}

type ReversePayload = {
  display_name?: string;
  in_rwanda?: boolean;
  address?: Record<string, string>;
};

async function reverseViaApi(lat: number, lng: number): Promise<ReversePayload | null> {
  try {
    const res = await fetch(
      `/gugu-app/api/geo.php?action=reverse&lat=${encodeURIComponent(String(lat))}&lng=${encodeURIComponent(String(lng))}`,
      { credentials: 'include' },
    );
    if (!res.ok) return null;
    return (await res.json()) as ReversePayload;
  } catch {
    return null;
  }
}

/**
 * Resolve GPS — suggested Akarere + Umurenge (Karrot neighbourhood style).
 * Uses PHP Nominatim proxy first, then centroid fallback.
 */
export async function resolveRwandaLocation(
  lat: number,
  lng: number,
  accuracyM?: number,
): Promise<GeoSuggestion> {
  const inRwanda = isInRwanda(lat, lng);
  let district = inRwanda ? nearestDistrict(lat, lng) : 'Gasabo';
  let sector = '';
  let label = '';

  const remote = await reverseViaApi(lat, lng);
  if (remote) {
    const addr = remote.address || {};
    const parts = [
      addr.suburb, addr.neighbourhood, addr.village, addr.hamlet,
      addr.city_district, addr.county, addr.city, addr.town, addr.state, addr.municipality,
      remote.display_name,
    ].filter(Boolean).join(' | ');

    label = remote.display_name || parts;

    if (inRwanda) {
      const matchedDistrict = matchName(parts, ALL_DISTRICTS);
      if (matchedDistrict) district = matchedDistrict;

      const sectors = sectorsForDistrict(district);
      const matchedSector = matchName(parts, sectors);
      if (matchedSector) sector = matchedSector;
      else if (addr.suburb || addr.neighbourhood || addr.village) {
        sector = addr.suburb || addr.neighbourhood || addr.village || '';
      }
    }
  }

  if (!inRwanda) {
    label = label
      ? `${label} · outside Rwanda — pick your ${BRAND_NAME} neighbourhood`
      : `GPS outside Rwanda — pick your ${BRAND_NAME} neighbourhood`;
  } else if (!sector) {
    const sectors = sectorsForDistrict(district);
    sector = sectors[0] || '';
  }

  if (!sector) {
    const sectors = sectorsForDistrict(district);
    sector = sectors[0] || '';
  }

  return {
    lat,
    lng,
    district,
    province: provinceForDistrict(district),
    sector,
    label: label || `${district}${sector ? ' / ' + sector : ''}, Rwanda`,
    source: 'gps',
    in_rwanda: inRwanda,
    accuracy_m: accuracyM,
  };
}

export function manualFromDistrict(district: string, sector = ''): GeoSuggestion {
  const [lat, lng] = DISTRICT_CENTROIDS[district] || DISTRICT_CENTROIDS.Gasabo;
  const sectors = sectorsForDistrict(district);
  const sec = sector || sectors[0] || '';
  return {
    lat,
    lng,
    district,
    province: provinceForDistrict(district),
    sector: sec,
    label: `${district}${sec ? ' / ' + sec : ''} · Rwanda`,
    source: 'manual',
    in_rwanda: true,
  };
}

/** Persist neighbourhood for home feed filter (like Karrot My Town) */
export function syncHomeLocationFilter(district: string, sector?: string) {
  if (district) {
    localStorage.setItem('gugu_district', district);
  } else {
    localStorage.removeItem('gugu_district');
  }
  if (sector) {
    localStorage.setItem('gugu_sector', sector);
  } else {
    localStorage.removeItem('gugu_sector');
  }
  window.dispatchEvent(new CustomEvent('gugu:location-updated', {
    detail: { district, sector: sector || '' },
  }));
}

export type GpsErrorKind = 'denied' | 'unavailable' | 'timeout' | 'unsupported' | 'unknown';

export function gpsErrorKind(err: GeolocationPositionError | null): GpsErrorKind {
  if (!err) return 'unknown';
  if (err.code === 1) return 'denied';
  if (err.code === 2) return 'unavailable';
  if (err.code === 3) return 'timeout';
  return 'unknown';
}

/**
 * Browser GPS — soft network fix first, then high-accuracy, then watchPosition.
 * Works on http://localhost (Chrome treats it as a secure context).
 */
export function getBrowserPosition(): Promise<GeolocationPosition> {
  return new Promise((resolve, reject) => {
    if (!navigator.geolocation) {
      reject({
        code: 2,
        message: 'unsupported',
        PERMISSION_DENIED: 1,
        POSITION_UNAVAILABLE: 2,
        TIMEOUT: 3,
      } as GeolocationPositionError);
      return;
    }

    let settled = false;
    const done = (pos: GeolocationPosition) => {
      if (settled) return;
      settled = true;
      resolve(pos);
    };
    const fail = (err: GeolocationPositionError) => {
      if (settled) return;
      settled = true;
      reject(err);
    };

    // 1) Fast / cached network location (desktop-friendly)
    navigator.geolocation.getCurrentPosition(
      done,
      () => {
        // 2) High-accuracy GPS
        navigator.geolocation.getCurrentPosition(
          done,
          (err2) => {
            // 3) watchPosition briefly — helps some Windows / Chrome cases
            let watchId = 0;
            const timer = window.setTimeout(() => {
              if (watchId) navigator.geolocation.clearWatch(watchId);
              fail(err2);
            }, 20000);

            watchId = navigator.geolocation.watchPosition(
              (pos) => {
                window.clearTimeout(timer);
                navigator.geolocation.clearWatch(watchId);
                done(pos);
              },
              (err3) => {
                window.clearTimeout(timer);
                navigator.geolocation.clearWatch(watchId);
                fail(err3);
              },
              { enableHighAccuracy: true, maximumAge: 0, timeout: 20000 },
            );
          },
          { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 },
        );
      },
      { enableHighAccuracy: false, timeout: 10000, maximumAge: 120000 },
    );
  });
}

export { RWANDA_PROVINCES };
