import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      '/api': {
        target: 'http://laravel-property-erp-v13.test',
        changeOrigin: true,
      },
    },
  },
})