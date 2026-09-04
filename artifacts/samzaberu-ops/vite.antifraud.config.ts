import path from 'path';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { defineConfig } from 'vite';

// Добавлено 03.09.2026 ИТ Директор Евразии
export default defineConfig({
  base: '/',
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': path.resolve(import.meta.dirname, 'src'),
      '@assets': path.resolve(import.meta.dirname, '..', '..', 'attached_assets'),
    },
    dedupe: ['react', 'react-dom', '@tanstack/react-query'],
  },
  root: path.resolve(import.meta.dirname),
  build: {
    outDir: path.resolve(import.meta.dirname, 'dist-antifraud/public'),
    emptyOutDir: true,
    rollupOptions: {
      input: path.resolve(import.meta.dirname, 'antifraud.html'),
      output: {
        entryFileNames: 'assets/antifraud-[hash].js',
        chunkFileNames: 'assets/antifraud-chunk-[hash].js',
        assetFileNames: 'assets/antifraud-[name]-[hash][extname]',
      },
    },
  },
});
