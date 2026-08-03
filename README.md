# SilverStripe DropzoneField

A [Dropzone.js](https://doc.dropzone.deltablot.app/) field for Silverstripe 6 — built for frontend
forms, and usable in any PHP-rendered form, including inside the CMS. The one exception is the CMS's
React-driven form schema (i.e. `getCMSFields()`), which isn't supported — see
[CMS usage](#cms-usage).

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

#### Stuff that doesn't work:

- Re-ordering files — Dropzone.js doesn't appear to support this out of the box
- Server-side thumbnail generation
- **React-rendered CMS forms** — see below

### CMS usage

This field works in any **PHP-rendered** form, including forms rendered by a template inside the CMS.

It does **not** work in the CMS's React-driven form schema (i.e. fields returned from
`getCMSFields()`). The field declares `schemaComponent = 'DropzoneField'` but ships no React
component, so `silverstripe/admin`'s `FormBuilder` will throw
`Component not found in injector: DropzoneField`. Additionally, React forms submit values from
redux-form state rather than from the DOM, so this field's hidden-input value mechanism would be
ignored there regardless.

So for a standard `getCMSFields()` edit form, reach for
[`silverstripe/asset-admin`](https://github.com/silverstripe/silverstripe-asset-admin)'s
`UploadField` instead — that's what it's for. This field is a good fit for custom admin
controllers and anywhere else in the CMS where you render a form through a template.

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
