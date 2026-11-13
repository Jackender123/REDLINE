/** Local utility styles for the Command Center. Keep the generated CSS in Git. */
module.exports = {
  content: {
    relative: true,
    files: ['../www/admin/index.php'],
  },
  theme: {
    extend: {
      colors: {
        ink: '#0F2A44',
        muted: '#5D7287',
        red: { DEFAULT: '#EF4057', hover: '#D92F46' },
        steel: '#4A86B0',
        navy: '#0B2E4E',
      },
      fontFamily: {
        display: ['"Barlow Condensed"', 'sans-serif'],
        sans: ['Barlow', 'sans-serif'],
      },
    },
  },
};
