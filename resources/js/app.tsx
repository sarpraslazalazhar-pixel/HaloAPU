import './bootstrap';
import '../css/app.css';

import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ThemeProvider } from '@/Components/ThemeProvider';
const appName = import.meta.env.VITE_APP_NAME || 'Halo APU';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(`./Pages/${name}.tsx`, import.meta.glob('./Pages/**/*.tsx')) as any,
    setup({ el, App, props }: any) {
        const root = createRoot(el!);
        root.render(
            <ThemeProvider defaultTheme="system" storageKey="halo-apu-theme">
                <App {...props} />
            </ThemeProvider>
        );
    },
    progress: {
        color: '#2563eb',
    },
});
