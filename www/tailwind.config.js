module.exports = {
  content: [
    // Scan ALL PHP and PVue files recursively
    "./**/*.php",
    "./**/*.pvue",
    "./**/*.html",
    
    // Be more specific about your component structure
    "./components/**/*.pvue",
    "./components/**/*.php",
    
    // Include your main entry points
    "./index.php",
    "./*.php",
    
    // JavaScript files if you use Tailwind classes in JS
    "./assets/**/*.js"
  ],
  theme: {
    extend: {},
  },
  plugins: [],
}