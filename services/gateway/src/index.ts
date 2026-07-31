import express from 'express';
import cors from 'cors';
import { createProxyMiddleware } from 'http-proxy-middleware';

const app = express();
app.use(cors());
app.use(express.json());

const services = {
  auth: process.env.AUTH_SERVICE || 'http://localhost:3001',
  listings: process.env.LISTINGS_SERVICE || 'http://localhost:3002',
  chat: process.env.CHAT_SERVICE || 'http://localhost:3003',
  users: process.env.USERS_SERVICE || 'http://localhost:3004',
};

app.get('/health', (_req, res) => {
  res.json({
    status: 'ok',
    architecture: 'GUGU Microservices',
    services: Object.keys(services),
  });
});

app.use('/api/auth', createProxyMiddleware({ target: services.auth, changeOrigin: true, pathRewrite: { '^/api/auth': '' } }));
app.use('/api/listings', createProxyMiddleware({ target: services.listings, changeOrigin: true, pathRewrite: { '^/api/listings': '' } }));
app.use('/api/chat', createProxyMiddleware({ target: services.chat, changeOrigin: true, pathRewrite: { '^/api/chat': '' } }));
app.use('/api/users', createProxyMiddleware({ target: services.users, changeOrigin: true, pathRewrite: { '^/api/users': '' } }));

const PORT = process.env.PORT || 8080;
app.listen(PORT, () => {
  console.log(`\n🥕 GUGU API Gateway :${PORT}`);
  console.log('   /api/auth      → Auth Service');
  console.log('   /api/listings  → Listings Service');
  console.log('   /api/chat      → Chat Service');
  console.log('   /api/users     → Users Service\n');
});
