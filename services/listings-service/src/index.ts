import express from 'express';
import cors from 'cors';
import multer from 'multer';
import path from 'path';
import fs from 'fs';
import { query, formatPrice, timeAgo, UPLOAD_URL } from '../../shared/src/db.js';

const app = express();
app.use(cors());
app.use(express.json());

const uploadDir = path.resolve('../../public/uploads');
if (!fs.existsSync(uploadDir)) fs.mkdirSync(uploadDir, { recursive: true });
const upload = multer({ dest: uploadDir, limits: { fileSize: 5 * 1024 * 1024 } });

async function getUser(req: express.Request) {
  const token = (req.headers.authorization || '').replace('Bearer ', '');
  if (!token) return null;
  const rows = await query<{ id: number }[]>('SELECT u.id FROM users u JOIN sessions s ON s.user_id = u.id WHERE s.id = ? AND s.expires_at > NOW()', [token]);
  return rows[0] || null;
}

app.get('/', async (req, res) => {
  try {
    const where: string[] = [];
    const params: unknown[] = [];
    if (req.query.user_id) {
      where.push('l.user_id = ?'); params.push(req.query.user_id);
      if (req.query.status) { where.push('l.status = ?'); params.push(req.query.status); }
    } else {
      where.push('l.status = "active"');
    }
    if (req.query.category) { where.push('l.category_id = ?'); params.push(req.query.category); }
    if (req.query.district) { where.push('l.district = ?'); params.push(req.query.district); }
    if (req.query.search) { where.push('(l.title LIKE ? OR l.description LIKE ?)'); params.push(`%${req.query.search}%`, `%${req.query.search}%`); }
    if (req.query.free) where.push('l.is_free = 1');

    const sql = `SELECT l.*, u.full_name as seller_name, c.name_rw as category_name, c.icon as category_icon,
      (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) as primary_image
      FROM listings l JOIN users u ON u.id = l.user_id JOIN categories c ON c.id = l.category_id
      WHERE ${where.join(' AND ')} ORDER BY l.created_at DESC LIMIT 50`;
    const listings = await query<Record<string, unknown>[]>(sql, params);
    const user = await getUser(req);
    for (const l of listings) {
      l.price_formatted = formatPrice(l.price as number);
      l.time_ago = timeAgo(l.created_at as string);
      if (l.primary_image) l.primary_image = UPLOAD_URL + l.primary_image;
    }
    res.json({ success: true, listings });
  } catch (e) { res.status(500).json({ error: String(e) }); }
});

app.get('/:id', async (req, res) => {
  try {
    const rows = await query<Record<string, unknown>[]>('SELECT l.*, u.full_name as seller_name, u.manner_score as seller_manner, u.district as seller_district, u.province as seller_province, c.name_rw as category_name, c.icon as category_icon FROM listings l JOIN users u ON u.id = l.user_id JOIN categories c ON c.id = l.category_id WHERE l.id = ?', [req.params.id]);
    if (!rows[0]) return res.status(404).json({ error: 'Ntibonetse' });
    const l = rows[0];
    await query('UPDATE listings SET view_count = view_count + 1 WHERE id = ?', [req.params.id]);
    const images = await query<{ image_path: string }[]>('SELECT image_path FROM listing_images WHERE listing_id = ?', [req.params.id]);
    l.images = images.map(i => ({ url: UPLOAD_URL + i.image_path }));
    l.price_formatted = formatPrice(l.price as number);
    l.time_ago = timeAgo(l.created_at as string);
    const user = await getUser(req);
    if (user) l.is_owner = user.id === l.user_id;
    res.json({ success: true, listing: l });
  } catch (e) { res.status(500).json({ error: String(e) }); }
});

app.post('/', upload.array('images[]', 10), async (req, res) => {
  try {
    const user = await getUser(req);
    if (!user) return res.status(401).json({ error: 'Nyamuneka winjire mbere' });
    const isFree = req.body.is_free === '1';
    const price = isFree ? 0 : parseInt(req.body.price || '0');
    const result = await query<{ insertId: number }>(
      'INSERT INTO listings (user_id, category_id, title, description, price, is_free, province, district, sector) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
      [user.id, req.body.category_id || 9, req.body.title, req.body.description, price, isFree ? 1 : 0, req.body.province, req.body.district, req.body.sector || null]
    );
    const listingId = (result as unknown as { insertId: number }).insertId;
    const files = req.files as Express.Multer.File[];
    if (files) for (let i = 0; i < files.length; i++) {
      const ext = path.extname(files[i].originalname) || '.jpg';
      const newName = `gugu_${Date.now()}_${i}${ext}`;
      fs.renameSync(files[i].path, path.join(uploadDir, newName));
      await query('INSERT INTO listing_images (listing_id, image_path, is_primary, sort_order) VALUES (?, ?, ?, ?)', [listingId, newName, i === 0 ? 1 : 0, i]);
    }
    res.status(201).json({ success: true, listing_id: listingId });
  } catch (e) { res.status(500).json({ error: String(e) }); }
});

const PORT = process.env.PORT || 3002;
app.listen(PORT, () => console.log(`Listings service :${PORT}`));
