import mysql from 'mysql2/promise';

export const dbConfig = {
  host: process.env.DB_HOST || 'localhost',
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASS || '',
  database: process.env.DB_NAME || 'GUGUapDB',
  charset: 'utf8mb4',
};

let pool: mysql.Pool | null = null;

export function getPool() {
  if (!pool) pool = mysql.createPool({ ...dbConfig, waitForConnections: true, connectionLimit: 10 });
  return pool;
}

export async function query<T>(sql: string, params: unknown[] = []): Promise<T> {
  const [rows] = await getPool().execute(sql, params);
  return rows as T;
}

export function formatPhone(phone: string): string {
  phone = phone.replace(/[^0-9+]/g, '');
  if (phone.startsWith('0')) phone = '+250' + phone.slice(1);
  else if (phone.startsWith('250')) phone = '+' + phone;
  else if (!phone.startsWith('+')) phone = '+250' + phone;
  return phone;
}

export function formatPrice(price: number): string {
  if (price === 0) return 'Ubuntu';
  return price.toLocaleString('en-US') + ' FRW';
}

export function timeAgo(datetime: string): string {
  const diff = Math.floor((Date.now() - new Date(datetime).getTime()) / 1000);
  if (diff < 60) return 'Ubu noneho';
  if (diff < 3600) return Math.floor(diff / 60) + ' iminota ishize';
  if (diff < 86400) return Math.floor(diff / 3600) + ' amasaha ashize';
  if (diff < 604800) return Math.floor(diff / 86400) + ' iminsi ishize';
  return new Date(datetime).toLocaleDateString('rw-RW');
}

export const UPLOAD_URL = process.env.UPLOAD_URL || '/gugu-app/public/uploads/';
