import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

const backendUrl = 'http://laravel-property-erp-v13.test'

export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      '/api': {
        target: backendUrl,
        changeOrigin: true,
      },
      '/sanctum': {
        target: backendUrl,
        changeOrigin: true,
      },
    },
  },
})