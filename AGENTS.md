# AGENTS.md

This file provides guidance to AI agents when working with code in this repository.

## What this is

A Dropzone.js upload field for Silverstripe 6, built primarily for **frontend forms** but working in any **PHP-rendered** form, including inside the CMS. React-driven CMS forms (`getCMSFields()`) are the one unsupported case. PHP module + a small vanilla-JS client bundle. Built on `@deltablot/dropzone` (the maintained fork of the abandoned `dropzone`).

The `2` branch is the Silverstripe 5 line — `master` does not need back-compat with it.

## Commands

```bash
yarn dev          # development build
yarn watch        # development build, watching
yarn build        # yarn install + lint + rm -rf client/dist/* + production webpack build
yarn css          # CSS bundle only (WEBPACK_CHILD=css)
yarn lint         # eslint client/src && stylelint client/src
yarn lint-js-fix  # eslint --fix
```

`yarn build` chains `yarn && yarn lint && webpack` — a lint failure kills the build.

Node 20+ (`.nvmrc` pins 22), webpack 5, `@silverstripe/webpack-config` ^3, dart-sass. Note yarn may only be on PATH under the nvm-managed Node, not the system one.

**There is no test suite** — no `tests/`, no phpunit/phpcs config, no CI. PHP changes can only be verified against a real PHP 8.3+ Silverstripe 6 install.

## Two things that will bite you

1. **`client/dist` is committed.** `.gitignore` excludes only `node_modules`, `vendor`, `resources` and source maps, and `composer.json` exposes `client/dist`. Any change to `client/src` needs a rebuild *and* the built bundle committed, or it has no effect for consumers.

2. **`terser-webpack-plugin` must stay in devDependencies.** `@silverstripe/webpack-config` `require`s it at module top level but doesn't declare it, and webpack 5.108+ dropped it in favour of `minimizer-webpack-plugin`. Remove it and *every* build — including dev and `WEBPACK_CHILD=css` — dies with `MODULE_NOT_FOUND`.

## Architecture

`DropzoneField extends FormField implements FileHandleField` — deliberately *not* `UploadField`.

It borrows the CMS React form-schema plumbing (`schemaComponent = 'DropzoneField'`, `data-schema`/`data-state` JSON attributes on the `<input>`) but **there is no React component**. `client/src/components/DropzoneField/DropzoneField.js` is plain JS that parses `data-schema` off the input element.

`getSchemaDataDefaults()` is the single seam where PHP config becomes Dropzone.js options: upload URL, CSRF header, `maxFiles`, `acceptedFiles` (derived from allowed extensions plus `HTTP.MimeTypes`), `maxFilesize`. Anything the PHP side needs to tell the JS side goes through here.

Only **PHP-rendered** forms are supported, including PHP-rendered forms inside the CMS. React-driven CMS forms (`getCMSFields()`) are *not* supported — see below.

### Value flow

The field has no conventional form value:

1. Files POST to `Link('upload')`; `DropzoneField::upload()` returns JSON file metadata.
2. JS injects `<input type="hidden" name="{Name}[Files][]" value="{id}">` into the field holder, tagged with `data-uuid`.
3. Pre-existing items are rendered server-side into `.dropzone-placeholder` by the template, then replayed into Dropzone as mock files via `emit('addedfile' | 'complete' | 'success')`.
4. Removing a file deletes the matching hidden input by `data-uuid`.

### Don't "simplify" these

- **Never build `data-schema`/`data-state` in `getDefaultAttributes()`.** Silverstripe 6's `FormField::getSchemaDataDefaults()` calls `getAttributes()`, so doing so recurses infinitely and fatals on first render. They come from `$SchemaAttributesHtml` in the template instead — core added that helper for exactly this reason.
- **`getDefaultAttributes()` must not call `array_merge($this->attributes)` or `extend('updateAttributes')`.** The `AttributesHTML` trait's `getAttributes()` already does both; duplicating them fires the extension hook twice.
- `webpack.config.js` clears `externals` and filters out `ProvidePlugin` **after** `getConfig()`. This is what keeps Dropzone's `typeof jQuery !== "undefined"` guard as a genuine runtime check instead of a `require('jquery')` that throws on frontend pages — it's why there's no longer any need to hand-edit `node_modules` before compiling. `mergeConfig()` cannot do either (lodash treats `{}` as a no-op and merges arrays by index).
- The `js-dropzone` → `dropzone` class swap in JS. Its original purpose (avoiding `Dropzone.autoDiscover` colliding with asset-admin's own Dropzone) is moot — `autoDiscover` was removed in the deltablot fork, and asset-admin 3 uses that fork too. It's kept for progressive enhancement: no dropzone-styled element until JS actually initialises.
- The empty-response workaround in the `success` handler (`if (res === '') res = JSON.parse(file.xhr.response)`). The upstream bug is still unfixed.
- The double `$file->write()` in `upload()` is intentional — protected files aren't actually protected after one write. The original issue (silverstripe/assets#224) has since been **deleted**; the architectural fix is assets RFC #88, still open. Re-verify before removing.
- Client-side `maxFiles` bookkeeping (`fileSlotsReserved`) is cosmetic only. Real enforcement is server-side `validate()` against `getAllowedMaxFileNumber()`.

### Upload receiver

`DropzoneFileUploadReceiver` wraps core `FileUploadReceiver`. `saveTemporaryFilesFromRequest()` branches three ways:

- **Chunked** — `dzchunkindex` present. Chunks land in `TEMP_PATH` as `{dzuuid}-chunk{n}`; `isFinalChunk()` just checks all chunk files exist, then `combineChunksIntoFile()` reassembles into the tmp file so `saveTemporaryFile()` can be reused. Returning `null` means "still in progress" and `upload()` replies with an empty 200.
- **Multiple per request** — `file[tmp_name]` is an array (`uploadMultiple` config option).
- **Single** — default fallback.

The six `dz*` chunk param names are identical between `dropzone` 5 and `@deltablot/dropzone` 7, which is why the fork migration needed no PHP changes here.

### Why React CMS forms fail

`schemaComponent = 'DropzoneField'` names a React component that has never existed, so `silverstripe/admin`'s `FormBuilder` throws `Component not found in injector: DropzoneField`. This is **deliberately left as-is**: setting `schemaComponent` to `null` would fall through to `getComponentForDataType('Custom')` → `get('GridField')` and silently render a GridField, which is worse than a clear error. React forms also submit from redux-form state rather than the DOM, so the hidden-input value flow wouldn't work there anyway.

### Other notes

- Requirements are loaded from `templates/.../DropzoneField.ss` via `<% require %>`, not from PHP.
- `DropzoneField_Readonly` is **not** dead code — `FormField::performReadonlyTransformation()` auto-discovers it via the `_Readonly` class-name convention, so the underscore name must stay. It renders `DropzoneField_Readonly.ss` (a plain file list, no upload UI, no JS) and overrides `Type()` so the JS bootstrap's `div.dropzonefield` selector doesn't match it.
- Translations live in `lang/en.yml` under this module's own namespace. There is no longer any `AssetAdmin` reference, and asset-admin is deliberately not a composer dependency.
- Known unsupported (per README): file re-ordering (Dropzone.js limitation) and server-side thumbnail generation.
