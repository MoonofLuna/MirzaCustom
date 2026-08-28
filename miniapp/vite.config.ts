import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// Builds straight into ../app/assets, which app/index.php serves. index.php
// reads the Vite manifest (build.manifest below) instead of hardcoded
// filenames, so a plain `npm run build` here is self-deploying.
export default defineConfig({
  plugins: [react()],
  base: '',
  server: {
    proxy: {
      '/api': 'http://localhost:8080',
    },
  },
  build: {
    manifest: true,
    outDir: '../app/assets',
    assetsDir: '',
    emptyOutDir: true,
  },
})
