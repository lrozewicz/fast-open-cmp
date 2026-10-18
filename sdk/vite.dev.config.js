// vite.config.js - using ESM format
import { defineConfig } from 'vite';
import { resolve } from 'path';
import sassConfig from './sass.config.js';
import { htmlTransform } from './vitePlugins/htmlTransform.js';

export default defineConfig({
  define: {
    // Dev build: local development URLs
    __CMP_API_BASE_URL__: JSON.stringify('http://localhost:3001/api'),
    __CMP_GVL_URL__: JSON.stringify('http://localhost:3001/vendor-list'),
  },
  root: './',
  resolve: {
    alias: {
      '@': resolve(__dirname, './src'),
      '/src': resolve(__dirname, './src'),
    },
  },
  server: {
    open: true,
    host: 'localhost',
    port: 5173
  },
  build: {
    outDir: 'dist',
    assetsDir: 'assets',
    sourcemap: true,
  },
  plugins: [htmlTransform()],
  publicDir: 'public',
  css: {
    preprocessorOptions: {
      scss: {
        ...sassConfig
      }
    },
    // css optimization
    devSourcemap: true,
  },
  // better performance
  optimizeDeps: {
    include: ['@iabtcf/core']
  },
  esbuild: {
    legalComments: 'none',
  }
});