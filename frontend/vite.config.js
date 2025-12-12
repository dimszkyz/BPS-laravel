import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  plugins: [react(), tailwindcss(),],
  server: {
    proxy: {
      '/api': {
        // UBAH BARIS INI: Dari localhost:3000 menjadi localhost:8000
        target: 'http://localhost:8000', 
        changeOrigin: true,
        secure: false,
      },
      // Jika Anda meload gambar dari backend, tambahkan ini untuk Laravel
      '/storage': {
         target: 'http://localhost:8000',
         changeOrigin: true,
      }
    },
  },
})
