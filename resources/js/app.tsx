import '@fontsource-variable/inter';
import '@fontsource/cinzel/600.css';
import '@fontsource/cinzel/800.css';

import { createInertiaApp } from '@inertiajs/react';
import type { ComponentType, ReactNode } from 'react';
import { createRoot } from 'react-dom/client';
import GameLayout from './layouts/GameLayout';

type PageModule = { default: ComponentType & { layout?: (page: ReactNode) => ReactNode } };

createInertiaApp({
    title: (title) => (title ? `${title} · Delvebound` : 'Delvebound'),
    resolve: async (name) => {
        const pages = import.meta.glob<PageModule>('./pages/**/*.tsx');
        const load = pages[`./pages/${name}.tsx`];
        if (!load) throw new Error(`Unknown page ${name}`);
        const page = await load();
        if (page.default.layout === undefined && !name.startsWith('auth/')) {
            page.default.layout = (child: ReactNode) => <GameLayout>{child}</GameLayout>;
        }
        return page;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#f5b453', showSpinner: false },
});
