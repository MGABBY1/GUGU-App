import express from 'express';
import cors from 'cors';
import { query, formatPrice, timeAgo, UPLOAD_URL } from '../../shared/src/db.js';
import { getRwandaLocations } from './locations.js';

const app = express();
app.use(cors());
app.use(express.json());

async function getUser(req: express.Request) {
  const token = (req.headers.authorization || '').replace('Bearer ', '');
  const rows = await query<Record<string, unknown>[]>('SELECT u.* FROM users u JOIN sessions s ON s.user_id = u.id WHERE s.id = ? AND s.expires_at > NOW()', [token]);
  return rows[0] || null;
}

app.get('/categories', async (_req, res) => {
  const categories = await query('SELECT * FROM categories ORDER BY sort_order');
  res.json({ success: true, categories });
});

app.get('/locations', (_req, res) => {
  res.json({ success: true, locations: getRwandaLocations() });
});

app.get('/profile', async (req, res) => {
  const user = await getUser(req);
  if (!user) return res.status(401).json({ error: 'Login required' });
  const stats = await query<{ c: number }[]>('SELECT COUNT(*) as c FROM listings WHERE user_id = ? AND status = "active"', [user.id]);
  user.active_listings = stats[0]?.c || 0;
  res.json({ success: true, user });
});

app.get('/favorites', async (req, res) => {
  const user = await getUser(req);
  if (!user) return res.status(401).json({ error: 'Login required' });
  const favorites = await query<Record<string, unknown>[]>(`
    SELECT l.*, (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) as primary_image
    FROM favorites f JOIN listings l ON l.id = f.listing_id WHERE f.user_id = ? ORDER BY f.created_at DESC`, [user.id]);
  for (const f of favorites) {
    f.price_formatted = formatPrice(f.price as number);
    f.time_ago = timeAgo(f.created_at as string);
    if (f.primary_image) f.primary_image = UPLOAD_URL + f.primary_image;
  }
  res.json({ success: true, favorites });
});

app.post('/favorites', async (req, res) => {
  const user = await getUser(req);
  if (!user) return res.status(401).json({ error: 'Login required' });
  const existing = await query('SELECT id FROM favorites WHERE user_id = ? AND listing_id = ?', [user.id, req.body.listing_id]);
  if ((existing as unknown[]).length) {
    await query('DELETE FROM favorites WHERE user_id = ? AND listing_id = ?', [user.id, req.body.listing_id]);
    return res.json({ success: true, favorited: false });
  }
  await query('INSERT INTO favorites (user_id, listing_id) VALUES (?, ?)', [user.id, req.body.listing_id]);
  res.json({ success: true, favorited: true });
});

app.get('/:id', async (req, res) => {
  const users = await query<Record<string, unknown>[]>('SELECT id, full_name, province, district, manner_score, manner_count FROM users WHERE id = ?', [req.params.id]);
  if (!users[0]) return res.status(404).json({ error: 'Not found' });
  const listings = await query<Record<string, unknown>[]>('SELECT l.*, (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) as primary_image FROM listings l WHERE l.user_id = ? AND l.status = "active"', [req.params.id]);
  for (const l of listings) {
    l.price_formatted = formatPrice(l.price as number);
    if (l.primary_image) l.primary_image = UPLOAD_URL + l.primary_image;
  }
  res.json({ success: true, user: users[0], listings });
});

const PORT = process.env.PORT || 3004;
app.listen(PORT, () => console.log(`Users service :${PORT}`));
