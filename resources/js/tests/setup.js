// Vitest setup file — global mocks for Inertia and Vue plugins

import { vi } from 'vitest';

// Mock @inertiajs/vue3
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: {
            auth: { user: { id: 1, name: 'Test', role: 'admin' } },
            flash: { success: null, error: null },
        },
    }),
    useForm: (initial) => ({
        ...initial,
        processing: false,
        errors: {},
        post: vi.fn(),
        put: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
        reset: vi.fn(),
        clearErrors: vi.fn(),
    }),
    router: {
        visit: vi.fn(),
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
        // Pages with a leave-guard subscribe in onMounted and call the
        // returned unsubscriber on unmount — the mock must hand one back.
        on: vi.fn(() => () => {}),
    },
    Link: {
        name: 'Link',
        template: '<a><slot /></a>',
        props: ['href'],
    },
    Head: {
        name: 'Head',
        template: '<div></div>',
        props: ['title'],
    },
}));

// Mock route() helper (Ziggy)
globalThis.route = vi.fn((name) => `/${name}`);
