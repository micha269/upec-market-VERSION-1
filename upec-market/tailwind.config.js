/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      colors: {
        'upec-dark': '#0f172a',
        'upec-green': '#22c55e',
      }
    },
  },
  plugins: [],
}