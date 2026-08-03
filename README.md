# SilverStripe DropzoneField

A [Dropzone.js](https://doc.dropzone.deltablot.app/) field for Silverstripe 6 — built for frontend
forms, and usable in any PHP-rendered form, including the CMS's page and GridField edit forms. The one
exception is React-rendered forms, such as those in the files section — see [CMS usage](#cms-usage).

Built on [`@deltablot/dropzone`](https://github.com/NicolasCARPi/dropzone), the maintained fork of the
now-abandoned `dropzone` package.

## Requirements

- Silverstripe 6 / `silverstripe/framework` ^6
- PHP 8.3+

For Silverstripe 5, use the [2.x release line](https://github.com/bigfork/silverstripe-dropzone/tree/2).

## Installation

```
composer require bigfork/silverstripe-dropzone
```

## Usage

```php
use Bigfork\SilverStripeDropzone\DropzoneField;

$field = DropzoneField::create('Attachments', 'Attachments')
    ->setAllowedMaxFileNumber(5)
    ->setFolderName('uploads/attachments')
    ->setDropzoneConfigOption('chunking', true);
```

Config options are listed here: https://doc.dropzone.deltablot.app/docs/configuration. Most config
options should already work, support for some hasn't been added yet.

#### Stuff that works:

- File uploads
- Multiple files per request (`->setDropzoneConfigOption('uploadMultiple', true)`)
- "Chunked" file uploads (`->setDropzoneConfigOption('chunking', true)`)
- Removing existing files
- Readonly fields (`->performReadonlyTransformation()`) — renders the attached files as a plain list
- Thumbnails for attached images

#### Stuff that doesn't work:

- Re-ordering files — Dropzone.js doesn't appear to support this out of the box
- **React-rendered forms** — see below

### CMS usage

This field works in any **PHP-rendered** form, which includes the CMS's page edit forms and
GridField detail forms — so a `DropzoneField` returned from `getCMSFields()` will work as you'd
expect. In the CMS the field is initialised through entwine rather than on `DOMContentLoaded`, so it
also works in forms the CMS loads, replaces or tears down over AJAX.

It does **not** work in forms rendered by `silverstripe/admin`'s React form schema, such as the ones
in the files section, or an Elemental block's inline edit form. The field declares
`schemaComponent = 'DropzoneField'` but ships no React component, so `FormBuilder` will throw
`Component not found in injector: DropzoneField`. Additionally, React forms submit values from
redux-form state rather than from the DOM, so this field's hidden-input value mechanism would be
ignored there regardless. Use
[`silverstripe/asset-admin`](https://github.com/silverstripe/silverstripe-asset-admin)'s
`UploadField` in those forms instead.

### Styling

There are two stylesheets, and only one of them is ever loaded on a given page:

- `bundle.css` — front-end. Dropzone.js' stock `basic.css` and `dropzone.css`, plus a rule hiding the
  field's `<input type="file">`. Restyle it however you'd restyle Dropzone.js anywhere else.
- `bundle-cms.css` — CMS only, loaded via `LeftAndMain.extra_requirements_css`. Doesn't use Dropzone.js'
  stylesheets at all; instead it styles every `.dz-*` class from scratch in Silverstripe's admin palette,
  laying the attached files out as a list of rows. Source is `client/src/styles/dropzone-cms.scss`.

## Contributing

Requires Node 20+ (see `.nvmrc`) and yarn.

```
yarn install
yarn build     # install + lint + production build
yarn dev       # development build
yarn watch     # development build, watching
yarn lint      # eslint + stylelint
```

`client/dist` is committed to the repository and exposed via composer, so **any change to
`client/src` needs a rebuild with the built bundle committed**, or it has no effect for consumers.
