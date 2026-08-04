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

### Maximum file size

`setAllowedMaxFileSize()` takes a size in bytes or ini format, and applies to a whole file:

```php
$field->setAllowedMaxFileSize('20m');
// or per extension / app category, as Upload_Validator supports:
$field->setAllowedMaxFileSize(['*' => '20m', 'zip' => '200m', '[image]' => '5m']);
```

Without chunking, the size you set is capped at whatever PHP will accept in a single request —
`min(upload_max_filesize, post_max_size)` — because that's the limit the upload actually has to fit
inside. This is core `Upload_Validator` behaviour and it's the right answer for a normal upload.

**With chunking enabled, that cap is lifted**, because a chunked upload never sends the whole file in
one request:

```php
$field = DropzoneField::create('Attachments')
    ->setDropzoneConfigOption('chunking', true)
    ->setAllowedMaxFileSize('200m'); // works even if upload_max_filesize is 10M
```

The per-request limit is then enforced against each individual **chunk** instead, and `chunkSize` is
capped at what PHP will accept so you can't accidentally configure chunks that PHP rejects. The order
you call `setAllowedMaxFileSize()` and enable `chunking` in doesn't matter.

Two things to be aware of when accepting large files this way:

- **Your web server has its own limit, and PHP can't see it.** nginx's `client_max_body_size`
  defaults to **1MB**, which will reject even the chunks — raise it above your chunk size.
  (Apache's `LimitRequestBody` defaults to unlimited.)
- **The final chunk's request does the reassembly**, on top of receiving its own chunk: it
  concatenates every chunk, hashes the result and writes it into the asset store. That one request is
  the one that may need more `max_execution_time`.

If you replace the field's validator with `setValidator()`, use a subclass of
`Bigfork\SilverStripeDropzone\DropzoneUploadValidator` — a plain `Upload_Validator` can't lift the
cap, so combining one with chunking throws rather than silently capping your uploads.

Chunks from uploads that are abandoned part-way through are swept up when a later upload starts, once
they're older than `DropzoneField.chunk_max_age` (default 24 hours; set it to `0` to disable the
sweep):

```yml
Bigfork\SilverStripeDropzone\DropzoneField:
  chunk_max_age: 3600
```

Note that `parallelChunkUploads` is **not** supported: whether a chunk is the last one is worked out
by checking whether all the others have arrived, so chunks completing concurrently can both start
reassembling and produce duplicate `File` records. Leave it at its default of `false`.

#### Stuff that works:

- File uploads
- Multiple files per request (`->setDropzoneConfigOption('uploadMultiple', true)`)
- "Chunked" file uploads (`->setDropzoneConfigOption('chunking', true)`)
- Removing existing files
- Readonly fields (`->performReadonlyTransformation()`) — renders the attached files as a plain list
- Thumbnails for attached images

#### Stuff that doesn't work:

- Re-ordering files — Dropzone.js doesn't appear to support this out of the box
- Parallel chunk uploads (`parallelChunkUploads`) — see [Maximum file size](#maximum-file-size)
- **React-rendered forms** — see below

### CMS usage

This field works in any **PHP-rendered** form, which includes the CMS's page edit forms, GridField
detail forms and inline-editable GridField columns (`GridFieldEditableColumns`) — so a
`DropzoneField` returned from `getCMSFields()` will work as you'd expect. In the CMS the field is
initialised through entwine rather than on `DOMContentLoaded`, so it also works in forms the CMS
loads, replaces or tears down over AJAX.

Note that `GridFieldEditableColumns` support only covers *existing* rows —
`GridFieldAddNewInlineButton` builds its new rows from a client-side template, so the field's upload
URL would point at a record that doesn't exist yet.

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
