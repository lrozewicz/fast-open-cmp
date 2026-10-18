// vite.config.js - using ESM format
import { defineConfig } from 'vite';
import { resolve } from 'path';
import sassConfig from './sass.config.js';
import cssInjectedByJsPlugin from 'vite-plugin-css-injected-by-js';
import { copyIndexHtml } from './vitePlugins/copyIndexHtml.js';
import { htmlTransform } from './vitePlugins/htmlTransform.js';

// Determine if we're in watch mode
const isWatch = process.argv.includes('--watch');

export default defineConfig({
  define: {
    // Production build: placeholders replaced by WordPress PHP at runtime
    __CMP_API_BASE_URL__: JSON.stringify('{API_BASE_URL}'),
    __CMP_GVL_URL__: JSON.stringify('{GVL_URL}'),
  },
  base: './',
  resolve: {
    alias: {
      '@': resolve(__dirname, './src'),
    },
  },
  server: {
    open: true,
    host: 'localhost',
    port: 5173,
    watch: {
      usePolling: true,
      interval: 100
    }
  },
  build: {
    outDir: 'dist',
    assetsDir: 'assets',
    sourcemap: true,
    lib: {
      entry: resolve(__dirname, 'src/index.ts'),
      name: 'cmpFramework',
      fileName: 'index',
      formats: ['iife'],
    },
    rollupOptions: {
      output: {
        entryFileNames: 'src/[name].js',
        chunkFileNames: 'src/[name].js',
        assetFileNames: 'assets/[name].[ext]',
        strict: false,
        extend: true,
        manualChunks: undefined,
      }
    },
    watch: isWatch ? {
      include: ['src/**'],
      exclude: ['node_modules/**'],
      buildDelay: 100
    } : null, // Enable watch mode based on --watch flag
  },
  plugins: [
    htmlTransform(),
    cssInjectedByJsPlugin(),
    copyIndexHtml()
  ],
  publicDir: 'public',
  css: {
    preprocessorOptions: {
      scss: {
        ...sassConfig
      }
    },
    devSourcemap: true,
  },
  optimizeDeps: {
    include: ['@iabtcf/core']
  },
  esbuild: {
    legalComments: 'none',
    // Production build: strip noisy/data-leaking console.log/info/debug from the bundle.
    // Marked as side-effect-free so esbuild tree-shakes them (their return value is unused).
    // console.warn / console.error are kept on purpose for real error reporting.
    pure: ['console.log', 'console.info', 'console.debug'],
    drop: ['debugger'],
  }
});