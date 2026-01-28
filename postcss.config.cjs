const autoprefixer = require('autoprefixer');

let tailwindPlugin;
try {
  // Tailwind v4
  tailwindPlugin = require('@tailwindcss/postcss');
} catch (e) {
  // Tailwind v3
  tailwindPlugin = require('tailwindcss');
}

module.exports = {
  plugins: [
    tailwindPlugin,
    autoprefixer,
  ],
};
