/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/**/*.{vue,js,blade.php}',
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif'],
                mono: ['JetBrains Mono', 'monospace'],
            },
            colors: {
                brand: {
                    DEFAULT: '#1D4289',
                    dark: '#0C426F',
                    light: '#2B5AAF',
                    50: '#EEF2F9',
                    100: '#D4DEEF',
                    200: '#A9BDE0',
                    300: '#7E9CD0',
                    400: '#537BC1',
                    500: '#1D4289',
                    600: '#18376F',
                    700: '#122B56',
                    800: '#0C1F3D',
                    900: '#061324',
                },
            },
            boxShadow: {
                'card': 'var(--shadow-sm)',
                'card-hover': 'var(--shadow-md)',
                'elevated': 'var(--shadow-lg)',
                'overlay': 'var(--shadow-xl)',
            },
            borderRadius: {
                'card': 'var(--radius-lg)',
                'button': 'var(--radius-md)',
            },
            transitionDuration: {
                'fast': 'var(--duration-fast)',
                'normal': 'var(--duration-normal)',
                'slow': 'var(--duration-slow)',
            },
            transitionTimingFunction: {
                'default': 'var(--easing-default)',
                'spring': 'var(--easing-spring)',
            },
        },
    },
    plugins: [],
};
