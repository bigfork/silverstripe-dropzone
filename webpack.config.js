const Path = require('path');
const { JavascriptWebpackConfig, CssWebpackConfig } = require('@silverstripe/webpack-config');

const PATHS = {
  MODULES: 'node_modules',
  ROOT: Path.resolve(),
  SRC: Path.resolve('client/src'),
  DIST: Path.resolve('client/dist'),
};

const jsConfig = new JavascriptWebpackConfig('js', PATHS)
  .setEntry({ bundle: `${PATHS.SRC}/bundles/bundle.js` })
  .getConfig();

// This bundle is primarily used on the front-end, where none of silverstripe/admin's globals exist.
// Removing ProvidePlugin also leaves Dropzone's own `typeof jQuery !== "undefined"` guard intact as
// a genuine runtime check, so there's no need to patch jQuery support out of it before compiling.
// Both have to be done after getConfig() - mergeConfig() can do neither, as lodash.merge treats an
// empty object as a no-op and merges arrays by index.
jsConfig.externals = {};
jsConfig.plugins = jsConfig.plugins.filter((plugin) => plugin.constructor.name !== 'ProvidePlugin');

const config = [
  jsConfig,
  new CssWebpackConfig('css', PATHS)
    .setEntry({ bundle: `${PATHS.SRC}/bundles/bundle.scss` })
    .getConfig(),
];

// Use WEBPACK_CHILD=js or WEBPACK_CHILD=css env var to run a single config
module.exports = (process.env.WEBPACK_CHILD)
  ? config.find((entry) => entry.name === process.env.WEBPACK_CHILD)
  : config;
