import express from 'express';
import cors from 'cors';
import bcrypt from 'bcryptjs';
import crypto from 'crypto';
import { getPool, formatPhone } from '../../shared/src/db.js';
import type { ResultSetHeader } from 'mysql2';

const app = express();
app.use(cors());
app.use(express.json());

app.post('/login', async (req, res) => {
  try {
    const phone = formatPhone(req.body.phone || '');
    const [rows] = await getPool().execute('SELECT * FROM users WHERE phone = ?', [phone]);
    const user = (rows as Record<string, unknown>[])[0];
    if (!user || !await bcrypt.compare(req.body.password, user.password_hash as string)) {
      return res.status(401).json({ error: 'Nomero cyangwa ijambo ry\'ibanga ntabwo ari byo' });
    }
    const token = crypto.randomBytes(32).toString('hex');
    await getPool().execute('INSERT INTO sessions (id, user_id, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))', [token, user.id]);
    const { password_hash, ...safe } = user;
    res.json({ success: true, token, user: safe });
  } catch (e) { res.status(500).json({ error: String(e) }); }
});

app.post('/register', async (req, res) => {
  try {
    const { full_name, province, district, sector, password } = req.body;
    const phone = formatPhone(req.body.phone || '');
    const hash = await bcrypt.hash(password, 10);
    const [result] = await getPool().execute(
      'INSERT INTO users (phone, password_hash, full_name, province, district, sector, is_verified) VALUES (?, ?, ?, ?, ?, ?, 1)',
      [phone, hash, full_name, province, district, sector || null]
    );
    const userId = (result as ResultSetHeader).insertId;
    const token = crypto.randomBytes(32).toString('hex');
    await getPool().execute('INSERT INTO sessions (id, user_id, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))', [token, userId]);
    const [users] = await getPool().execute('SELECT id, phone, full_name, province, district, manner_score, manner_count FROM users WHERE id = ?', [userId]);
    res.status(201).json({ success: true, token, user: (users as Record<string, unknown>[])[0] });
  } catch (e: unknown) {
    if ((e as { code?: string }).code === 'ER_DUP_ENTRY') return res.status(400).json({ error: 'Iyi nomero isanzwe ikoreshwa' });
    res.status(500).json({ error: String(e) });
  }
});

app.get('/me', async (req, res) => {
  const token = (req.headers.authorization || '').replace('Bearer ', '');
  const [rows] = await getPool().execute(
    'SELECT u.id, u.phone, u.full_name, u.province, u.district, u.manner_score, u.manner_count FROM users u JOIN sessions s ON s.user_id = u.id WHERE s.id = ? AND s.expires_at > NOW()', [token]
  );
  if (!(rows as unknown[]).length) return res.status(401).json({ error: 'Nyamuneka winjire mbere' });
  res.json({ success: true, user: (rows as Record<string, unknown>[])[0] });
});

const PORT = process.env.PORT || 3001;
app.listen(PORT, () => console.log(`Auth service :${PORT}`));
