<?php

namespace Bigfork\SilverStripeDropzone;

/**
 * Readonly counterpart of DropzoneField, rendered by
 * FormField::performReadonlyTransformation() via the "_Readonly" class naming convention.
 *
 * Renders the attached files as a plain list, with no upload UI and no Dropzone.js.
 */
class DropzoneField_Readonly extends DropzoneField
{
    protected $readonly = true;

    /**
     * No upload actions are available on a readonly field
     *
     * @config
     * @var array
     */
    private static $allowed_actions = [];

    /**
     * The holder class is deliberately different to the editable field's, so the JS bootstrap
     * (which looks for div.dropzonefield) doesn't try to initialise a dropzone that isn't there
     */
    public function Type()
    {
        return 'dropzonefield_readonly readonly';
    }

    public function performReadonlyTransformation()
    {
        return clone $this;
    }
}
