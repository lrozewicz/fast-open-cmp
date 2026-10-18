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
      '@': resolve(import.meta.dirname, './src'),
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
      entry: resolve(import.meta.dirname, 'src/index.ts'),
      name: 'cmpFramework',
      fileName: 'index',
      formats: ['iife'],
    },
    rolldownOptions: {
      // Production build: strip noisy/data-leaking console.log/info/debug from the bundle.
      // Marked as side-effect-free so they are tree-shaken (their return value is unused).
      // console.warn / console.error are kept on purpose for real error reporting.
      treeshake: {
        manualPureFunctions: ['console.log', 'console.info', 'console.debug'],
      },
      output: {
        minify: {
          compress: { dropDebugger: true },
        },
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
});