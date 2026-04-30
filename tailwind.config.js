import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

export default {
    content: [
        './app/**/*.php',
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './routes/**/*.php',
    ],
    theme: {
        extend: {},
    },
    plugins: [forms],
};
