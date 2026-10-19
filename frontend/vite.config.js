import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const projectRoot = path.dirname(fileURLToPath(import.meta.url))

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      // Absolute imports keep component paths stable when files move between
      // folders, and avoid deep ../../ chains in the page components.
      '@': path.resolve(projectRoot, './src'),
    },
  },
  server: {
    port: 5173,
    proxy: {
      // Proxying in development means the browser sees a single origin, so no
      // CORS configuration is needed locally. In production the frontend calls
      // the deployed API directly through VITE_API_URL.
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: 'dist',
    // Source maps are omitted: they would expose the full application source,
    // including the shape of the API, to anyone opening devtools on the
    // deployed site.
    sourcemap: false,
    // Vite's default limit again. It was raised to 900 kB to quiet the single
    // 1 MB bundle; pages now load on demand (App.jsx), so a chunk past 500 kB
    // is worth hearing about.
  },
})
