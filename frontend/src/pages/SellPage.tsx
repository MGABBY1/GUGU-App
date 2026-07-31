import { useState, useEffect } from 'react';
import { useAuth, toast } from '../components/AuthContext';
import { useStack } from '../stack/Stackflow';
import { api, Category, CATEGORY_ICONS, goToItemsPage } from '../api/client';
import { Header } from '../components/BottomNav';
import { provinceForDistrict, sectorsForDistrict } from '../data/rwanda';
import { useLang } from '../i18n/LanguageContext';

/** Item categories for Post an item (excludes All=1 and Jobs=11) */
const SELL_CATEGORIES_FALLBACK: Category[] = [
  { id: 2, name_rw: 'Telefoni', name_en: 'Electronics', icon: 'phone' },
  { id: 3, name_rw: 'Imbaho', name_en: 'Furniture', icon: 'couch' },
  { id: 4, name_rw: 'Imyambaro', name_en: 'Fashion', icon: 'shirt' },
  { id: 5, name_rw: 'Imodoka', name_en: 'Vehicles', icon: 'car' },
  { id: 6, name_rw: 'Inzu', name_en: 'Real Estate', icon: 'house' },
  { id: 7, name_rw: 'Imikino', name_en: 'Sports', icon: 'ball' },
  { id: 8, name_rw: 'Ibiryo', name_en: 'Food', icon: 'food' },
  { id: 9, name_rw: 'Ibikoresho', name_en: 'Appliances', icon: 'plug' },
  { id: 10, name_rw: 'Ibindi', name_en: 'Others', icon: 'box' },
];

function itemCategoriesOnly(list: Category[]): Category[] {
  return list.filter(c => {
    const id = Number(c.id);
    return id > 1 && id !== 11;
  });
}

function pickSector(district: string, preferred?: string) {
  const sectors = sectorsForDistrict(district);
  if (preferred && sectors.includes(preferred)) return preferred;
  return sectors[0] || '';
}

export default function SellPage() {
  const { isAuthed, user } = useAuth();
  const { push, resetTo } = useStack();
  const { t, category } = useLang();
  const [images, setImages] = useState<File[]>([]);
  const [categories, setCategories] = useState<Category[]>(SELL_CATEGORIES_FALLBACK);
  const [isFree, setIsFree] = useState(false);
  const [loading, setLoading] = useState(false);
  const [feeOk, setFeeOk] = useState(false);
  const [fee, setFee] = useState({ fee_rwf: 1000, momo_number: '0780000000', momo_name: 'Gura & Gurisha Admin' });
  const [form, setForm] = useState({
    title: '', description: '', price: '', category_id: '2',
    province: 'Kigali', district: 'Gasabo', sector: pickSector('Gasabo'),
  });

  // Always load categories (do not gate behind ID check — empty select was the bug)
  useEffect(() => {
    api.categories()
      .then(d => {
        const list = itemCategoriesOnly(d.categories || []);
        if (list.length) setCategories(list);
      })
      .catch(() => setCategories(SELL_CATEGORIES_FALLBACK));
    api.announceFee().then(d => setFee({
      fee_rwf: d.fee_rwf || 1000,
      momo_number: d.momo_number || '0780000000',
      momo_name: d.momo_name || 'Gura & Gurisha Admin',
    })).catch(() => {});
  }, []);

  useEffect(() => {
    if (!isAuthed) {
      toast(t('login_first'), 'error');
      push('auth');
      return;
    }
    if (user?.district) {
      const district = user.district;
      setForm(f => ({
        ...f,
        province: user.province || provinceForDistrict(district),
        district,
        sector: pickSector(district, user.sector),
      }));
    }
  }, [isAuthed, user?.district, user?.province, user?.sector]);

  const addImages = (e: React.ChangeEvent<HTMLInputElement>) => {
    const files = Array.from(e.target.files || []);
    setImages(prev => [...prev, ...files].slice(0, 10));
    e.target.value = '';
  };

  const validate = () => {
    if (!form.title.trim()) {
      toast(t('fill_title'), 'error');
      return false;
    }
    if (!form.description.trim()) {
      toast(t('fill_desc'), 'error');
      return false;
    }
    if (!isFree && (!form.price || Number(form.price) < 0)) {
      toast(t('fill_price'), 'error');
      return false;
    }
    const sector = form.sector || pickSector(form.district);
    if (!sector) {
      toast(t('fill_sector'), 'error');
      return false;
    }
    if (!feeOk) {
      toast(t('fee_must_ack'), 'error');
      return false;
    }
    if (!localStorage.getItem('gugu_token')) {
      toast(t('login_first'), 'error');
      push('auth');
      return false;
    }
    return true;
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (loading) return;
    if (!validate()) return;

    const sector = form.sector || pickSector(form.district);
    setLoading(true);

    const fd = new FormData();
    fd.append('title', form.title.trim());
    fd.append('description', form.description.trim());
    fd.append('price', isFree ? '0' : String(form.price || '0'));
    fd.append('category_id', form.category_id || '10');
    fd.append('is_free', isFree ? '1' : '0');
    fd.append('province', form.province || provinceForDistrict(form.district));
    fd.append('district', form.district);
    fd.append('sector', sector);
    fd.append('fee_acknowledged', '1');
    images.forEach(f => fd.append('images[]', f));

    try {
      const res = await api.createListing(fd);
      if (res.pending_approval) {
        toast(res.message || t('pending_after_pay'), 'success');
        resetTo('profile');
      } else {
        toast(t('listed_toast'), 'success');
        goToItemsPage();
        resetTo('items');
      }
    } catch (err) {
      toast((err as Error).message || t('login_first'), 'error');
    } finally {
      setLoading(false);
    }
  };

  return (
    <>
      <Header title={t('sell_title')} back />
      <div className="stack-content" style={{ paddingBottom: 100 }}>
        <div style={{ display: 'flex', gap: 6, padding: '12px 16px' }}>
          {[1, 2, 3].map(i => (
            <div key={i} style={{ flex: 1, height: 3, borderRadius: 2, background: 'var(--seed-carrot)' }} />
          ))}
        </div>

        <form id="sell-form" onSubmit={submit} style={{ padding: '0 16px' }} noValidate>
          <section style={{ background: 'white', borderRadius: 12, padding: 20, marginBottom: 12, border: '1px solid var(--seed-gray-200)' }}>
            <h3 style={{ fontWeight: 700, marginBottom: 4 }}>{t('photos')}</h3>
            <p style={{ fontSize: '0.8125rem', color: 'var(--seed-gray-600)', marginBottom: 12 }}>{t('photos_hint')}</p>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 8 }}>
              {images.map((f, i) => (
                <div key={`${f.name}-${i}`} style={{ aspectRatio: 1, borderRadius: 8, overflow: 'hidden', position: 'relative' }}>
                  <img src={URL.createObjectURL(f)} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
                  {i === 0 && (
                    <span style={{ position: 'absolute', bottom: 4, left: 4, background: 'var(--seed-carrot)', color: 'white', fontSize: '0.6rem', padding: '2px 6px', borderRadius: 4 }}>
                      Cover
                    </span>
                  )}
                  <button
                    type="button"
                    onClick={() => setImages(im => im.filter((_, j) => j !== i))}
                    style={{ position: 'absolute', top: 4, right: 4, width: 22, height: 22, background: 'rgba(0,0,0,0.55)', color: 'white', borderRadius: '50%', fontSize: '0.7rem' }}
                  >
                    ✕
                  </button>
                </div>
              ))}
              {images.length < 10 && (
                <label style={{ aspectRatio: 1, border: '2px dashed var(--seed-gray-200)', borderRadius: 8, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', color: 'var(--seed-gray-500)', fontSize: '0.75rem' }}>
                  <span style={{ fontSize: '1.5rem' }}>+</span>
                  {t('add_photo')}
                  <input type="file" accept="image/*" multiple hidden onChange={addImages} />
                </label>
              )}
            </div>
          </section>

          <section style={{ background: 'white', borderRadius: 12, padding: 20, marginBottom: 12, border: '1px solid var(--seed-gray-200)' }}>
            <h3 style={{ fontWeight: 700, marginBottom: 16 }}>{t('item_section')}</h3>
            <div className="seed-field">
              <label>{t('what_selling')}</label>
              <input
                className="seed-input"
                value={form.title}
                onChange={e => setForm(f => ({ ...f, title: e.target.value }))}
                placeholder="iPhone 13, Sofa..."
                maxLength={150}
              />
            </div>
            <div className="seed-field">
              <label>{t('category')}</label>
              <div className="sell-cat-grid" role="listbox" aria-label={t('category')}>
                {categories.map(c => {
                  const id = String(c.id);
                  const selected = form.category_id === id;
                  return (
                    <button
                      key={c.id}
                      type="button"
                      role="option"
                      aria-selected={selected}
                      className={`sell-cat-btn${selected ? ' on' : ''}`}
                      onClick={() => setForm(f => ({ ...f, category_id: id }))}
                    >
                      <span className="sell-cat-ico">{CATEGORY_ICONS[c.icon] || '📦'}</span>
                      <span className="sell-cat-name">{category(c.name_rw, c.name_en)}</span>
                    </button>
                  );
                })}
              </div>
            </div>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '12px 0', marginBottom: 12 }}>
              <div>
                <div style={{ fontWeight: 600 }}>🎁 Ubuntu</div>
                <div style={{ fontSize: '0.8125rem', color: 'var(--seed-gray-600)' }}>{t('free_give')}</div>
              </div>
              <button
                type="button"
                onClick={() => setIsFree(!isFree)}
                style={{
                  width: 48, height: 28, borderRadius: 14,
                  background: isFree ? 'var(--seed-carrot)' : 'var(--seed-gray-200)',
                  position: 'relative', transition: 'background 0.2s',
                }}
              >
                <span
                  style={{
                    position: 'absolute', top: 3, left: isFree ? 23 : 3,
                    width: 22, height: 22, background: 'white', borderRadius: '50%',
                    transition: 'left 0.2s', boxShadow: '0 1px 3px rgba(0,0,0,0.15)',
                  }}
                />
              </button>
            </div>
            {!isFree && (
              <div className="seed-field">
                <label>{t('price_frw')}</label>
                <input
                  className="seed-input"
                  type="number"
                  value={form.price}
                  onChange={e => setForm(f => ({ ...f, price: e.target.value }))}
                  placeholder="50000"
                  min={0}
                />
              </div>
            )}
          </section>

          <section style={{ background: 'white', borderRadius: 12, padding: 20, marginBottom: 12, border: '1px solid var(--seed-gray-200)' }}>
            <h3 style={{ fontWeight: 700, marginBottom: 16 }}>{t('desc_location')}</h3>
            <div className="seed-field">
              <label>{t('describe')}</label>
              <textarea
                className="seed-input"
                style={{ minHeight: 100, resize: 'vertical' }}
                value={form.description}
                onChange={e => setForm(f => ({ ...f, description: e.target.value }))}
              />
            </div>
            <div className="seed-field">
              <label>{t('district')} (Akarere)</label>
              <input
                className="seed-input"
                value={form.district}
                readOnly
                title={t('change_stay_district')}
              />
              <p style={{ fontSize: 12, color: 'var(--seed-gray-600)', marginTop: 6 }}>
                {t('post_locked_district')}{' '}
                <button type="button" className="seed-link" style={{ fontSize: 12 }} onClick={() => push('profile')}>
                  {t('change_stay_district')}
                </button>
              </p>
            </div>
            <div className="seed-field">
              <label>{t('sector')} (Umurenge)</label>
              <select
                className="seed-input"
                value={form.sector}
                onChange={e => setForm(f => ({ ...f, sector: e.target.value }))}
              >
                <option value="">{t('pick_sector')}</option>
                {sectorsForDistrict(form.district).map(s => (
                  <option key={s} value={s}>{s}</option>
                ))}
              </select>
            </div>
          </section>

          <section className="fee-box">
            <h3>{t('announce_fee_title')}</h3>
            <p>{t('announce_fee_clear')}</p>
            <div className="fee-amount">{fee.fee_rwf.toLocaleString()} RWF</div>
            <p className="fee-momo">
              {t('pay_momo_to')}: <strong>{fee.momo_name}</strong>
              <br />
              MoMo: <strong>{fee.momo_number}</strong>
            </p>
            <ol className="fee-steps">
              <li>{t('fee_step_1')}</li>
              <li>{t('fee_step_2')}</li>
              <li>{t('fee_step_3')}</li>
            </ol>
            <label className="fee-check">
              <input type="checkbox" checked={feeOk} onChange={e => setFeeOk(e.target.checked)} />
              {t('fee_ack_label')}
            </label>
          </section>
        </form>
      </div>

      <div
        style={{
          flexShrink: 0,
          padding: '12px 16px calc(12px + env(safe-area-inset-bottom, 0px))',
          borderTop: '1px solid var(--seed-gray-200)',
          background: 'var(--seed-white)',
        }}
      >
        <button
          type="submit"
          form="sell-form"
          className="seed-btn seed-btn-carrot seed-btn-block"
          disabled={loading || !feeOk}
        >
          {loading ? t('posting') : t('submit_for_approval')}
        </button>
      </div>
    </>
  );
}
