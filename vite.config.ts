import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import path from 'path';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.tsx'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
        },
    },
    build: {
        cssCodeSplit: true,
        chunkSizeWarningLimit: 1000,
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (id.includes('node_modules')) {
                        if (id.includes('echarts')) {
                            return 'vendor-echarts';
                        }
                        if (id.includes('lucide-react')) {
                            return 'vendor-lucide';
                        }
                        if (id.includes('@radix-ui')) {
                            return 'vendor-radix';
                        }
                        if (id.includes('react') || id.includes('react-dom') || id.includes('@inertiajs')) {
                            return 'vendor-framework';
                        }
                    }
                },
            },
        },
    },
});
