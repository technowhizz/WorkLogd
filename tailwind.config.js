import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';
import { solidtimeTheme } from './resources/js/packages/ui/tailwind.theme.js';

/** @type {import("tailwindcss").Config} */
export default {
    darkMode: ['selector', '.dark'],
    content: [
        './extensions/Invoicing/resources/js/**/*.vue',
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
        './resources/js/**/*.ts',
        '!./resources/js/**/node_modules',
    ],
    theme: {
        extend: {
            ...solidtimeTheme,
            fontFamily: {
                // Inter is the interface face and therefore the default; Archivo is the
                // display face for headings, figures and the wordmark. Splitting them is
                // deliberate - Archivo carries the brand voice, Inter carries density.
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
                display: ['Archivo', ...defaultTheme.fontFamily.sans],
            },
            /*
             * The brand radius scale from ui-plan.md, mapped onto Tailwind's names so the
             * app's existing rounded-* utilities land on it without touching components.
             * Nothing sits between these steps.
             *
             * The logo is exempt: the mark is square, and its optional 7px softening at
             * 24-40px is applied by the component itself, not by a utility.
             */
            borderRadius: {
                none: '0px',
                sm: '10px', // control
                DEFAULT: '10px', // control
                md: '10px', // control
                lg: '12px', // card
                xl: '16px', // panel
                '2xl': '16px', // panel
                '3xl': '16px', // panel
                full: '999px', // pill
            },
        },
    },

    plugins: [
        forms,
        typography,
        require('@tailwindcss/container-queries'),
        require('tailwindcss-animate'),
    ],
};
