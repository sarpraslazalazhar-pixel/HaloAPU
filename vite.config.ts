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
        chunkSizeWarningLimit: 600,
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (!id.includes('node_modules')) return;

                    // 1. Core React runtime
                    if (/[\\/]node_modules[\\/](react|react-dom|scheduler)[\\/]/.test(id)) {
                        return 'vendor-react';
                    }

                    // 2. Inertia core
                    if (/[\\/]node_modules[\\/]@inertiajs[\\/]/.test(id)) {
                        return 'vendor-inertia';
                    }

                    // 3. ECharts & ZRender
                    if (id.includes('echarts') || id.includes('zrender')) {
                        return 'vendor-echarts';
                    }

                    // 4. UI Primitives
                    if (id.includes('@radix-ui') || id.includes('@base-ui')) {
                        return 'vendor-radix';
                    }

                    // 5. Heavy Document Processors
                    if (id.includes('mammoth') || id.includes('docx')) {
                        return 'vendor-docs';
                    }
                },
            },
        },
    },
});
