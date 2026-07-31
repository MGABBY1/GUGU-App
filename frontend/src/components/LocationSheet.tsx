import { useEffect, useState } from 'react';
import { useAuth, toast } from './AuthContext';
import { useLang } from '../i18n/LanguageContext';
import {
  getBrowserPosition,
  resolveRwandaLocation,
  manualFromDistrict,
  gpsErrorKind,
  GeoSuggestion,
  sectorsForDistrict,
  syncHomeLocationFilter,
} from '../data/geo';
import { RWANDA_PROVINCES, provinceForDistrict } from '../data/rwanda';

type Props = {
  open: boolean;
  onClose: () => void;
  onSaved?: (place: { district: string; sector: string }) => void;
};

/**
 * Confirm stay district (Akarere/Umurenge) with GPS — updates marketplace scope.
 */
export function LocationSheet({ open, onClose, onSaved }: Props) {
  const { isAuthed, verifyLocation, user } = useAuth();
  const { t } = useLang();
  const [loading, setLoading] = useState(false);
  const [geo, setGeo] = useState<GeoSuggestion | null>(null);
  const [district, setDistrict] = useState(user?.district || 'Gasabo');
  const [sector, setSector] = useState(user?.sector || '');

  useEffect(() => {
    if (!open) return;
    setGeo(null);
    setDistrict(user?.district || localStorage.getItem('gugu_district') || 'Gasabo');
    setSector(user?.sector || localStorage.getItem('gugu_sector') || '');
  }, [open, user?.district, user?.sector]);

  if (!open) return null;

  const sectorOptions = (() => {
    const list = sectorsForDistrict(district);
    if (sector && !list.includes(sector)) return [sector, ...list];
    return list;
  })();

  const captureGps = async () => {
    setLoading(true);
    try {
      const pos = await getBrowserPosition();
      const suggestion = await resolveRwandaLocation(
        pos.coords.latitude,
        pos.coords.longitude,
        pos.coords.accuracy,
      );
      const d = suggestion.in_rwanda
        ? suggestion.district
        : (user?.district || district || 'Gasabo');
      const secs = sectorsForDistrict(d);
      const s = suggestion.in_rwanda
        ? (suggestion.sector || secs[0] || '')
        : (user?.sector || secs[0] || '');
      setGeo({ ...suggestion, district: d, sector: s, province: provinceForDistrict(d) });
      setDistrict(d);
      setSector(s);
      if (!suggestion.in_rwanda) {
        toast(t('gps_outside_rwanda'), 'error');
      } else {
        toast(t('gps_detected'), 'success');
      }
    } catch (err) {
      const kind = gpsErrorKind(err as GeolocationPositionError);
      if (kind === 'denied') toast(t('gps_permission'), 'error');
      else if (kind === 'timeout') toast(t('gps_timeout'), 'error');
      else if (kind === 'unsupported' || kind === 'unavailable') toast(t('gps_unavailable'), 'error');
      else toast(t('gps_denied'), 'error');
      const fallback = manualFromDistrict(user?.district || district || 'Gasabo', user?.sector || '');
      setGeo(fallback);
      setDistrict(fallback.district);
      setSector(fallback.sector);
    } finally {
      setLoading(false);
    }
  };

  const openManual = () => {
    const suggestion = manualFromDistrict(district || 'Gasabo', sector);
    setGeo(suggestion);
    setDistrict(suggestion.district);
    setSector(suggestion.sector);
    toast(t('gps_manual_ok'), 'success');
  };

  const save = async () => {
    if (!isAuthed) {
      toast(t('login_first'), 'error');
      return;
    }
    const place = geo || manualFromDistrict(district, sector);
    setLoading(true);
    try {
      await verifyLocation(place.lat, place.lng, {
        district,
        sector,
        province: provinceForDistrict(district),
      });
      syncHomeLocationFilter(district, sector);
      try { sessionStorage.removeItem('gugu_loc_prompted'); } catch { /* ignore */ }
      toast(`${t('gps_ok')} — ${district}${sector ? ' / ' + sector : ''}`, 'success');
      onSaved?.({ district, sector });
      onClose();
    } catch (err) {
      toast((err as Error).message, 'error');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="loc-sheet-backdrop" role="dialog" aria-modal="true" onClick={onClose}>
      <div className="loc-sheet" onClick={e => e.stopPropagation()}>
        <div className="loc-sheet-handle" />
        <header className="loc-sheet-head">
          <h2>{t('gps_title')}</h2>
          <button type="button" className="loc-sheet-close" onClick={onClose} aria-label="Close">×</button>
        </header>
        <p className="loc-sheet-hint">{t('change_stay_hint')}</p>

        {user?.location_ok && user.district && (
          <div className="loc-sheet-current">
            <strong>📍 {t('gps_current')}</strong>
            <span>
              {user.sector ? `${user.sector}, ${user.district}` : user.district}
              {user.location_days_left != null ? ` · ${user.location_days_left}d` : ''}
            </span>
          </div>
        )}

        {!geo ? (
          <div className="loc-sheet-actions">
            <button type="button" className="seed-btn seed-btn-carrot seed-btn-block" disabled={loading} onClick={captureGps}>
              {loading ? t('waiting') : t('gps_allow')}
            </button>
            <button type="button" className="seed-btn seed-btn-outline seed-btn-block" disabled={loading} onClick={openManual}>
              {t('gps_manual')}
            </button>
          </div>
        ) : (
          <div className="loc-sheet-form">
            <div className={`loc-sheet-found${geo.in_rwanda ? '' : ' warn'}`}>
              <strong>📍 {t('gps_found')}</strong>
              <div>{geo.label}</div>
              {geo.accuracy_m != null && geo.source === 'gps' && (
                <div className="loc-sheet-acc">
                  ±{Math.round(geo.accuracy_m)}m · {geo.lat.toFixed(5)}, {geo.lng.toFixed(5)}
                </div>
              )}
            </div>

            <label>{t('district')} (Akarere)</label>
            <select
              value={district}
              onChange={e => {
                const d = e.target.value;
                setDistrict(d);
                const secs = sectorsForDistrict(d);
                const s = secs[0] || '';
                setSector(s);
                setGeo(g => g?.source === 'gps'
                  ? { ...g, district: d, sector: s, province: provinceForDistrict(d) }
                  : manualFromDistrict(d, s));
              }}
            >
              {Object.entries(RWANDA_PROVINCES).map(([prov, districts]) => (
                <optgroup key={prov} label={prov}>
                  {districts.map(d => <option key={d} value={d}>{d}</option>)}
                </optgroup>
              ))}
            </select>

            <label>{t('sector')} (Umurenge)</label>
            {sectorOptions.length > 0 ? (
              <select value={sector} onChange={e => setSector(e.target.value)}>
                {sectorOptions.map(s => <option key={s} value={s}>{s}</option>)}
              </select>
            ) : (
              <input value={sector} onChange={e => setSector(e.target.value)} placeholder="Umurenge" />
            )}

            <button type="button" className="seed-btn seed-btn-carrot seed-btn-block" disabled={loading} onClick={save}>
              {loading ? t('waiting') : t('gps_confirm')}
            </button>
            <button type="button" className="seed-btn seed-btn-outline seed-btn-block" disabled={loading} onClick={captureGps}>
              {t('gps_retry')}
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
