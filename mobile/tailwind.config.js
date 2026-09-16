/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./app/**/*.{js,jsx,ts,tsx}', './src/**/*.{js,jsx,ts,tsx}'],
  presets: [require('nativewind/preset')],
  theme: {
    extend: {
      colors: {
        // Same brand palette as web/ (see web/src/index.css --color-primary: 37 176 3).
        primary: {
          DEFAULT: '#25B003',
          50: '#EBF9E6',
          100: '#D3F0C6',
          200: '#A8E091',
          300: '#7DD05C',
          400: '#4CBE27',
          500: '#25B003',
          600: '#1E8F03',
          700: '#176D02',
          800: '#104C02',
          900: '#092A01',
        },
      },
      fontFamily: {
        nunito: ['Nunito_400Regular'],
        'nunito-medium': ['Nunito_500Medium'],
        'nunito-semibold': ['Nunito_600SemiBold'],
        'nunito-bold': ['Nunito_700Bold'],
      },
    },
  },
  plugins: [],
};
