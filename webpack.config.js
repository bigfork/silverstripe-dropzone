const Path = require('path');
const { JavascriptWebpackConfig, CssWebpackConfig } = require('@silverstripe/webpack-config');

const PATHS = {
  MODULES: 'node_modules',
  ROOT: Path.resolve(),
  SRC: Path.resolve('client/src'),
  DIST: Path.resolve('client/dist'),
};

// `bundle` bootstraps on DOMContentLoaded for front-end pages, `bundle-cms` on entwine for the CMS.
// Only ever one of the two is loaded (the CMS ones come from LeftAndMain.extra_requirements_* in
// _config/config.yml, the front-end ones from the field's template), so Dropzone.js being compiled
// into both is not a payload cost.
const jsConfig = new JavascriptWebpackConfig('js', PATHS)
  .setEntry({
    bundle: `${PATHS.SRC}/bundles/bundle.js`,
    'bundle-cms': `${PATHS.SRC}/bundles/bundle-cms.js`,
  })
  .getConfig();

// The front-end bundle runs on pages where none of silverstripe/admin's globals exist. Removing
// ProvidePlugin also leaves Dropzone's own `typeof jQuery !== "undefined"` guard intact as a genuine
// runtime check, so there's no need to patch jQuery support out of it before compiling. Both have to
// be done after getConfig() - mergeConfig() can do neither, as lodash.merge treats an empty object
// as a no-op and merges arrays by index.
jsConfig.externals = {};
jsConfig.plugins = jsConfig.plugins.filter((plugin) => plugin.constructor.name !== 'ProvidePlugin');

const config = [
  jsConfig,
  new CssWebpackConfig('css', PATHS)
    .setEntry({
      bundle: `${PATHS.SRC}/bundles/bundle.scss`,
      'bundle-cms': `${PATHS.SRC}/bundles/bundle-cms.scss`,
    })
    .getConfig(),
];

// Use WEBPACK_CHILD=js or WEBPACK_CHILD=css env var to run a single config
module.exports = (process.env.WEBPACK_CHILD)
  ? config.find((entry) => entry.name === process.env.WEBPACK_CHILD)
  : config;
