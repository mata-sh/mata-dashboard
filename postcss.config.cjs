const postcssImport = require('postcss-import');
const purgecss = require('@fullhuman/postcss-purgecss').default;
const cssnano = require('cssnano');

module.exports = {
  plugins: [
    postcssImport,
    purgecss({
      content: ['./**/*.php', './**/*.html', './**/*.js'],
      defaultExtractor: content => content.match(/[\w-/:]+(?<!:)/g) || []
    }),
    cssnano({ preset: 'default' })
  ]
};
