import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [react(), tailwindcss()],
    build: {
        outDir: 'dist',
        emptyOutDir: true,
        manifest: true,
        rollupOptions: { input: 'resources/js/main.tsx' },
    },
    test: {
        environment: 'jsdom',
    },
});
