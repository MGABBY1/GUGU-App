import express from 'express';
import cors from 'cors';
import { query, timeAgo, UPLOAD_URL } from '../../shared/src/db.js';

const app = express();
app.use(cors());
app.use(express.json());

async function getUserId(req: express.Request) {
  const token = (req.headers.authorization || '').replace('Bearer ', '');
  const rows = await query<{ id: number }[]>('SELECT u.id FROM users u JOIN sessions s ON s.user_id = u.id WHERE s.id = ? AND s.expires_at > NOW()', [token]);
  return rows[0]?.id || null;
}

app.get('/rooms', async (req, res) => {
  const uid = await getUserId(req);
  if (!uid) return res.status(401).json({ error: 'Login required' });
  const rooms = await query<Record<string, unknown>[]>(`
    SELECT cr.*, l.title as listing_title,
      CASE WHEN cr.buyer_id = ? THEN seller.full_name ELSE buyer.full_name END as other_name,
      (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) as listing_image,
      (SELECT content FROM messages WHERE room_id = cr.id ORDER BY created_at DESC LIMIT 1) as last_message
    FROM chat_rooms cr JOIN listings l ON l.id = cr.listing_id
    JOIN users buyer ON buyer.id = cr.buyer_id JOIN users seller ON seller.id = cr.seller_id
    WHERE cr.buyer_id = ? OR cr.seller_id = ? ORDER BY cr.last_message_at DESC`, [uid, uid, uid]);
  for (const r of rooms) {
    r.time_ago = timeAgo(r.last_message_at as string);
    if (r.listing_image) r.listing_image = UPLOAD_URL + r.listing_image;
  }
  res.json({ success: true, rooms });
});

app.post('/rooms', async (req, res) => {
  const uid = await getUserId(req);
  if (!uid) return res.status(401).json({ error: 'Login required' });
  const listing = await query<{ user_id: number }[]>('SELECT user_id FROM listings WHERE id = ?', [req.body.listing_id]);
  if (!listing[0]) return res.status(404).json({ error: 'Not found' });
  if (listing[0].user_id === uid) return res.status(400).json({ error: 'Cannot chat with yourself' });
  const existing = await query<{ id: number }[]>('SELECT id FROM chat_rooms WHERE listing_id = ? AND buyer_id = ?', [req.body.listing_id, uid]);
  if (existing[0]) return res.json({ success: true, room_id: existing[0].id });
  const result = await query<{ insertId: number }>('INSERT INTO chat_rooms (listing_id, buyer_id, seller_id) VALUES (?, ?, ?)', [req.body.listing_id, uid, listing[0].user_id]);
  res.status(201).json({ success: true, room_id: (result as unknown as { insertId: number }).insertId });
});

app.get('/rooms/:id/messages', async (req, res) => {
  const uid = await getUserId(req);
  if (!uid) return res.status(401).json({ error: 'Login required' });
  await query('UPDATE messages SET is_read = 1 WHERE room_id = ? AND sender_id != ?', [req.params.id, uid]);
  const messages = await query<Record<string, unknown>[]>('SELECT m.*, u.full_name as sender_name FROM messages m JOIN users u ON u.id = m.sender_id WHERE m.room_id = ? ORDER BY m.created_at ASC', [req.params.id]);
  for (const m of messages) { m.is_mine = m.sender_id === uid; m.time_ago = timeAgo(m.created_at as string); }
  res.json({ success: true, messages });
});

app.post('/rooms/:id/messages', async (req, res) => {
  const uid = await getUserId(req);
  if (!uid) return res.status(401).json({ error: 'Login required' });
  await query('INSERT INTO messages (room_id, sender_id, content) VALUES (?, ?, ?)', [req.params.id, uid, req.body.content]);
  await query('UPDATE chat_rooms SET last_message_at = NOW() WHERE id = ?', [req.params.id]);
  res.status(201).json({ success: true });
});

const PORT = process.env.PORT || 3003;
app.listen(PORT, () => console.log(`Chat service :${PORT}`));
