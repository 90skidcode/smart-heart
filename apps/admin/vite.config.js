import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Built files go straight into the Laravel public folder, served at /admin/.
// During development, `npm run dev` proxies /api to `php artisan serve` on :8000.
export default defineConfig({
  plugins: [react()],
  base: '/admin/',
  build: {
    outDir: '../api/public/admin',
    emptyOutDir: true,
  },
  server: {
    port: 5173,
    proxy: { '/api': 'http://127.0.0.1:8000' },
  },
});
