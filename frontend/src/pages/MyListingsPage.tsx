import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { api, Listing, CATEGORY_ICONS } from '../api/client';
import { useStack } from '../stack/Stackflow';
import { useAuth, toast } from '../components/AuthContext';
import { Header } from '../components/BottomNav';
import { useLang } from '../i18n/LanguageContext';
import { prepareListingImages } from '../utils/prepareImages';

type TrackFilter = 'all' | 'waiting' | 'live' | 'sold' | 'rejected';

function postTrack(listing: Listing): TrackFilter {
  if (listing.status === 'sold') return 'sold';
  const mod = listing.moderation_status || 'approved';
  if (mod === 'rejected') return 'rejected';
  if (mod === 'pending' || mod === 'flagged') return 'waiting';
  return 'live';
}

export default function MyListingsPage() {
  const { push, resetTo } = useStack();
  const { isAuthed } = useAuth();
  const { t, price, timeAgo, category } = useLang();
  const [listings, setListings] = useState<Listing[]>([]);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState<TrackFilter>('all');
  const [uploadingId, setUploadingId] = useState<number | null>(null);
  const [actionId, setActionId] = useState<number | null>(null);
  const fileRef = useRef<HTMLInputElement | null>(null);
  const pendingIdRef = useRef<number | null>(null);

  const load = useCallback(() => {
    if (!isAuthed) return;
    setLoading(true);
    api.listings({ mine: '1', limit: '50' })
      .then(d => setListings(d.listings || []))
      .catch(err => {
        setListings([]);
        toast((err as Error).message, 'error');
      })
      .finally(() => setLoading(false));
  }, [isAuthed]);

  useEffect(() => {
    if (!isAuthed) {
      toast(t('login_first'), 'error');
      resetTo('auth');
      return;
    }
    load();
    const timer = window.setInterval(load, 12000);
    const onFocus = () => load();
    window.addEventListener('focus', onFocus);
    return () => {
      window.clearInterval(timer);
      window.removeEventListener('focus', onFocus);
    };
  }, [isAuthed, load, resetTo, t]);

  const counts = useMemo(() => {
    const c = { all: listings.length, waiting: 0, live: 0, sold: 0, rejected: 0 };
    for (const l of listings) {
      c[postTrack(l)] += 1;
    }
    return c;
  }, [listings]);

  const visible = useMemo(
    () => (filter === 'all' ? listings : listings.filter(l => postTrack(l) === filter)),
    [listings, filter],
  );

  const statusCopy = (listing: Listing) => {
    const track = postTrack(listing);
    if (track === 'waiting') {
      return {
        badge: t('post_status_waiting'),
        hint: listing.payment_status === 'paid' ? t('post_hint_paid_waiting') : t('post_hint_waiting'),
        cls: 'waiting',
      };
    }
    if (track === 'live') {
      return { badge: t('post_status_live'), hint: t('post_hint_live'), cls: 'live' };
    }
    if (track === 'sold') {
      return { badge: t('post_status_sold'), hint: t('post_hint_sold'), cls: 'sold' };
    }
    return { badge: t('post_status_rejected'), hint: t('post_hint_rejected'), cls: 'rejected' };
  };

  const pickPhotos = (listingId: number) => {
    pendingIdRef.current = listingId;
    fileRef.current?.click();
  };

  const onPhotosPicked = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const listingId = pendingIdRef.current;
    const files = Array.from(e.target.files || []);
    e.target.value = '';
    pendingIdRef.current = null;
    if (!listingId || files.length === 0) return;

    setUploadingId(listingId);
    try {
      const prepared = await prepareListingImages(files);
      const fd = new FormData();
      prepared.forEach((f, i) => fd.append('images[]', f, f.name || `photo_${i + 1}.jpg`));
      const res = await api.addListingImages(listingId, fd);
      toast(res.message || t('photos_saved'), 'success');
      load();
    } catch (err) {
      toast((err as Error).message, 'error');
    } finally {
      setUploadingId(null);
    }
  };

  const markSold = async (listingId: number) => {
    if (!window.confirm(t('mark_sold_confirm'))) return;
    setActionId(listingId);
    try {
      const res = await api.updateListing(listingId, { status: 'sold' });
      toast(res.message || t('marked_sold'), 'success');
      load();
    } catch (err) {
      toast((err as Error).message, 'error');
    } finally {
      setActionId(null);
    }
  };

  const relist = async (listingId: number) => {
    setActionId(listingId);
    try {
      await api.updateListing(listingId, { status: 'active' });
      toast(t('relist_for_sale'), 'success');
      load();
    } catch (err) {
      toast((err as Error).message, 'error');
    } finally {
      setActionId(null);
    }
  };

  const removePost = async (listingId: number) => {
    if (!window.confirm(t('delete_post_confirm'))) return;
    setActionId(listingId);
    try {
      const res = await api.deleteListing(listingId);
      toast(res.message || t('post_deleted'), 'success');
      load();
    } catch (err) {
      toast((err as Error).message, 'error');
    } finally {
      setActionId(null);
    }
  };

  const tabs: { id: TrackFilter; label: string }[] = [
    { id: 'all', label: `${t('post_filter_all')} (${counts.all})` },
    { id: 'waiting', label: `${t('post_filter_waiting')} (${counts.waiting})` },
    { id: 'live', label: `${t('post_filter_live')} (${counts.live})` },
    { id: 'sold', label: `${t('post_filter_sold')} (${counts.sold})` },
    { id: 'rejected', label: `${t('post_filter_rejected')} (${counts.rejected})` },
  ];

  return (
    <>
      <Header title={t('my_posts_title')} back />
      <input
        ref={fileRef}
        type="file"
        accept="image/*,.jpg,.jpeg,.png,.webp,.gif,.heic,.heif"
        multiple
        hidden
        onChange={e => { void onPhotosPicked(e); }}
      />
      <div className="stack-content my-posts-page">
        <div className="my-posts-hero">
          <h2>{t('my_posts_title')}</h2>
          <p>{t('my_posts_hint')}</p>
          <div className="my-posts-kpis">
            <div className="my-posts-kpi is-wait">
              <strong>{counts.waiting}</strong>
              <span>{t('post_filter_waiting')}</span>
            </div>
            <div className="my-posts-kpi is-live">
              <strong>{counts.live}</strong>
              <span>{t('post_filter_live')}</span>
            </div>
            <div className="my-posts-kpi">
              <strong>{counts.sold}</strong>
              <span>{t('post_filter_sold')}</span>
            </div>
          </div>
          <button type="button" className="seed-btn seed-btn-carrot seed-btn-block" onClick={() => push('sell')}>
            {t('sell_title')}
          </button>
        </div>

        <div className="my-posts-filters" role="tablist">
          {tabs.map(tab => (
            <button
              key={tab.id}
              type="button"
              role="tab"
              aria-selected={filter === tab.id}
              className={`my-posts-filter${filter === tab.id ? ' active' : ''}`}
              onClick={() => setFilter(tab.id)}
            >
              {tab.label}
            </button>
          ))}
        </div>

        {loading ? (
          <div className="chat-loading">{t('loading')}</div>
        ) : visible.length === 0 ? (
          <div className="chat-empty">
            <div className="chat-empty-ico">🏷️</div>
            <h2>{t('my_posts_empty')}</h2>
            <p>{t('my_posts_empty_hint')}</p>
            <button type="button" className="seed-btn seed-btn-carrot" onClick={() => push('sell')}>
              {t('sell_title')}
            </button>
          </div>
        ) : (
          <div className="my-posts-list">
            {visible.map(l => {
              const st = statusCopy(l);
              const missingPhoto = !l.primary_image;
              const isSold = l.status === 'sold';
              const canMarkSold = !isSold && (l.moderation_status === 'approved' || !l.moderation_status);
              return (
                <div key={l.id} className={`my-posts-card status-${st.cls}`}>
                  <button
                    type="button"
                    className="my-posts-card-main"
                    onClick={() => push('detail', { id: l.id })}
                  >
                    <div className={`my-posts-thumb${isSold ? ' is-sold' : ''}`}>
                      {l.primary_image
                        ? <img src={l.primary_image} alt="" />
                        : <span>{CATEGORY_ICONS[l.category_icon || 'box'] || '📦'}</span>}
                      {isSold && <span className="sold-badge-mini">{t('sold_badge')}</span>}
                    </div>
                    <div className="my-posts-body">
                      <div className="my-posts-top">
                        <strong>{l.title}</strong>
                        <span className={`my-posts-badge ${st.cls}`}>{st.badge}</span>
                      </div>
                      <div className="my-posts-price">{price(l.price, l.is_free)}</div>
                      <div className="my-posts-meta">
                        {CATEGORY_ICONS[l.category_icon || 'box']}{' '}
                        {category(l.category_name_rw, l.category_name_en, l.category_name)}
                        {' · '}
                        {l.sector ? `${l.sector}, ${l.district}` : l.district}
                        {' · '}
                        {timeAgo(l.created_at) || l.time_ago}
                      </div>
                      <p className="my-posts-hint-line">
                        {missingPhoto ? t('post_missing_photo') : st.hint}
                      </p>
                    </div>
                    <span className="my-posts-chev">›</span>
                  </button>
                  <div className="my-posts-actions">
                    {missingPhoto && (
                      <button
                        type="button"
                        className="my-posts-add-photo"
                        disabled={uploadingId === l.id || actionId === l.id}
                        onClick={() => pickPhotos(l.id)}
                      >
                        {uploadingId === l.id ? t('loading') : `📷 ${t('add_photos_now')}`}
                      </button>
                    )}
                    {canMarkSold && (
                      <button
                        type="button"
                        className="my-posts-action sold"
                        disabled={actionId === l.id}
                        onClick={() => { void markSold(l.id); }}
                      >
                        {t('mark_as_sold')}
                      </button>
                    )}
                    {isSold && (
                      <button
                        type="button"
                        className="my-posts-action"
                        disabled={actionId === l.id}
                        onClick={() => { void relist(l.id); }}
                      >
                        {t('relist_for_sale')}
                      </button>
                    )}
                    <button
                      type="button"
                      className="my-posts-action danger"
                      disabled={actionId === l.id}
                      onClick={() => { void removePost(l.id); }}
                    >
                      {t('delete_post')}
                    </button>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>
    </>
  );
}
