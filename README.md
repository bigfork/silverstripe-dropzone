# SilverStripe DropzoneField

A Dropzone.js field for SilverStripe 4 - designed to be used on the frontend, rather than the CMS.

## Work in progress

#### Stuff that works:

- File uploads
- Multiple files per request (`->setDropzoneConfigOption('uploadMultiple', true)`)
- "Chunked" file uploads (`->setDropzoneConfigOption('chunking', true)`)
- Removing existing files
- CMS appears functional, but has no styling and hasn't really been tested

#### Stuff that doesn't work:

- Picking existing files from the CMS - probably won't ever be added, just use UploadField if you want this
- Re-ordering files - Dropzone.js doesn't appear to support this out of the box
- Server-side thumbnail generation
