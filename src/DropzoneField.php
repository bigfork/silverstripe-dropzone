<?php

namespace Bigfork\SilverStripeDropzone;

use LogicException;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTP;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FileHandleField;
use SilverStripe\Forms\FormField;
use SilverStripe\Model\List\SS_List;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\NullSecurityToken;

class DropzoneField extends FormField implements FileHandleField
{
    use DropzoneFileUploadReceiver;

    /**
     * @config
     * @var array
     */
    private static $allowed_actions = [
        'upload'
    ];

    /**
     * How much of PHP's per-request upload limit to leave free when deriving a chunk size. The
     * request body carries the six dz* fields and multipart boundaries as well as the chunk itself.
     *
     * @config
     * @var int
     */
    private static $chunk_size_headroom = 524288;

    /**
     * Dropzone.js' own default chunkSize, mirrored here so that an ini limit smaller than it can be
     * detected. Only relevant when the field hasn't been given an explicit chunkSize.
     */
    private const DROPZONE_DEFAULT_CHUNK_SIZE = 2097152;

    protected $inputType = 'file';

    protected $schemaDataType = FormField::SCHEMA_DATA_TYPE_CUSTOM;

    protected $schemaComponent = 'DropzoneField';

    /**
     * @var array
     */
    protected $dropzoneConfig = [];

    /**
     * The number of files allowed for this field
     *
     * @var null|int
     */
    protected $allowedMaxFileNumber = null;

    /**
     * @var bool|null
     */
    protected $multiUpload = null;

    /**
     * Create a new file field.
     *
     * @param string $name The internal field name, passed to forms.
     * @param string $title The field label.
     * @param SS_List $items Items assigned to this field
     */
    public function __construct($name, $title = null, ?SS_List $items = null)
    {
        $this->constructFileUploadReceiver();

        // Chunked uploads need a validator that can be told to ignore PHP's per-request size
        // limits. The allowed extensions are carried across rather than re-read from config, so
        // there's only one place that knows where they come from
        $validator = DropzoneUploadValidator::create();
        $validator->setAllowedExtensions($this->getValidator()->getAllowedExtensions());
        $this->setValidator($validator);

        // When creating new files, rename on conflict
        $this->getUpload()->setReplaceFile(false);

        parent::__construct($name, $title);
        if ($items) {
            $this->setItems($items);
        }
    }

    /**
     * Creates a single file based on a form-urlencoded upload.
     *
     * @param HTTPRequest $request
     * @return HTTPResponse
     * @throws HTTPResponse_Exception
     * @throws ValidationException
     */
    public function upload(HTTPRequest $request)
    {
        if ($this->isDisabled() || $this->isReadonly()) {
            $this->httpError(403);
        }

        // CSRF check
        $token = $this->getForm()->getSecurityToken();
        if (!$token->checkRequest($request)) {
            $this->httpError(400);
        }

        $this->configureValidatorClamping();

        $files = $this->saveTemporaryFilesFromRequest($request, $errors);
        if (!empty($errors)) {
            $result = implode(', ', $errors);

            $this->getUpload()->clearErrors();
            return (new HTTPResponse(json_encode($result), 400))
                ->addHeader('Content-Type', 'application/json');
        }

        // Null response indicates an unfinished chunked file upload, so just return nothing
        if ($files === null) {
            return new HTTPResponse('');
        }

        $result = [];
        /** @var File $file */
        foreach ($files as $file) {
            // Ensure file is written twice so files that should be protected are actually protected
            // https://github.com/silverstripe/silverstripe-assets/issues/224
            $file->write();
            $file->write();

            // Return success response
            $fileResult = [
                'id' => $file->ID,
                'filename' => $file->Filename,
                'title' => $file->Title,
                'exists' => $file->exists(),
                'category' => $file instanceof Folder ? 'folder' : $file->appCategory(),
                'extension' => $file->Extension,
                'size' => $file->AbsoluteSize,
                'parent' => null
            ];

            /** @var Folder $parent */
            $parent = $file->Parent();
            if ($parent) {
                $fileResult['parent'] = [
                    'id' => $parent->ID,
                    'title' => $parent->Title,
                    'filename' => $parent->Filename
                ];
            }

            $result[] = $fileResult;
        }

        $this->getUpload()->clearErrors();
        return (new HTTPResponse(json_encode($result)))
            ->addHeader('Content-Type', 'application/json');
    }

    /**
     * @param array $config
     * @return $this
     */
    public function setDropzoneConfig(array $config)
    {
        $this->dropzoneConfig = $config;
        return $this;
    }

    /**
     * @param string $option
     * @param $value
     * @return $this
     */
    public function setDropzoneConfigOption($option, $value)
    {
        $this->dropzoneConfig[$option] = $value;
        return $this;
    }

    /**
     * @param string $option
     * @param mixed $default
     * @return mixed
     */
    public function getDropzoneConfigOption($option, $default = null)
    {
        return $this->dropzoneConfig[$option] ?? $default;
    }

    /**
     * Whether this field uploads files in chunks, across multiple requests
     */
    public function getIsChunked(): bool
    {
        return (bool)$this->getDropzoneConfigOption('chunking', false);
    }

    /**
     * The maximum size a single chunk may be. Each chunk is posted as an ordinary file upload, so
     * this is bounded by what PHP will accept in one request - never by the field's own maximum
     * file size, which governs the reassembled file.
     */
    public function getMaxChunkSize(): int
    {
        $phpMax = DropzoneUploadValidator::getPHPMaxUploadSize();
        $headroom = (int)static::config()->get('chunk_size_headroom');

        // Proportional floor, so that a small but legitimate ini limit can't be reduced to
        // something absurd (or to zero) by a fixed headroom larger than itself
        return max($phpMax - $headroom, (int)($phpMax * 0.9));
    }

    /**
     * Tells the validator whether to keep clamping its maximum file size to PHP's per-request
     * limits. Called at the two points the answer matters - building the client-side config, and
     * validating an upload - rather than from the setters, so that it doesn't matter whether
     * chunking is enabled before or after the maximum file size is set.
     *
     * @throws LogicException if chunking is enabled on a validator that can't unclamp
     */
    protected function configureValidatorClamping(): void
    {
        $validator = $this->getValidator();

        if (!$validator instanceof DropzoneUploadValidator) {
            if ($this->getIsChunked()) {
                throw new LogicException(sprintf(
                    'Field "%s" has chunking enabled, but its validator (%s) is not a %s, so its '
                        . 'maximum file size cannot exceed PHP\'s per-request upload limit. Extend '
                        . '%s instead.',
                    $this->getName(),
                    get_class($validator),
                    DropzoneUploadValidator::class,
                    DropzoneUploadValidator::class
                ));
            }

            return;
        }

        $validator->setClampToPHPLimits(!$this->getIsChunked());
    }

    /**
     * Sets the maximum size of an uploaded file, in bytes or ini format ('200m'). When chunking is
     * enabled this may exceed PHP's upload_max_filesize/post_max_size, as the file is never sent
     * in a single request.
     *
     * @param array|int|string $rules
     * @return $this
     */
    public function setAllowedMaxFileSize($rules)
    {
        $this->getValidator()->setAllowedMaxFileSize($rules);
        return $this;
    }

    /**
     * @param string $ext
     * @return int|false Filesize in bytes
     */
    public function getAllowedMaxFileSize($ext = null)
    {
        $this->configureValidatorClamping();
        return $this->getValidator()->getAllowedMaxFileSize($ext);
    }

    /**
     * Whether this field is being rendered inside the CMS. The CMS has its own JavaScript and CSS
     * bundles, loaded via LeftAndMain.extra_requirements_[javascript|css] in _config/config.yml, so
     * the template uses this to leave the front-end ones out
     */
    public function getIsCMS(): bool
    {
        return class_exists(LeftAndMain::class) && Controller::curr() instanceof LeftAndMain;
    }

    public function getSchemaDataDefaults()
    {
        $this->configureValidatorClamping();

        $state = parent::getSchemaDataDefaults();

        $state['config'] = $this->dropzoneConfig;

        // Without this Dropzone.js renders no way of removing an attached file at all
        if (!isset($state['config']['addRemoveLinks'])) {
            $state['config']['addRemoveLinks'] = true;
        }

        // The upload URL and security token both require a form, which won't be present if the
        // field hasn't been added to one yet
        if ($this->getForm()) {
            $state['config']['url'] = $this->Link('upload');

            $token = $this->getForm()->getSecurityToken();
            if (!$token instanceof NullSecurityToken) {
                $state['config']['headers']["X-{$token->getName()}"] = $token->getValue();
            }
        }

        // If a max files number has been set
        if ($this->getAllowedMaxFileNumber() !== null) {
            $state['config']['maxFiles'] = $this->getAllowedMaxFileNumber();
        }

        // If multi-upload is explicitly disallowed, max file number has to be 1
        if ($this->getIsMultiUpload() === false) {
            $state['config']['maxFiles'] = 1;
        }

        // Add mime types for allowed file extensions (if set)
        $extensionsWhitelist = $this->getAllowedExtensions();
        if ($extensionsWhitelist) {
            $accept = [];
            $mimeTypes = HTTP::config()->uninherited('MimeTypes');
            foreach ($extensionsWhitelist as $extension) {
                $accept[] = ".{$extension}";
                // Check for corresponding mime type
                if (isset($mimeTypes[$extension])) {
                    $accept[] = $mimeTypes[$extension];
                }
            }

            $state['config']['acceptedFiles'] = implode(',', $accept);
        }

        // Max file size validation. Dropzone.js compares against maxFilesize * 1048576, so this has
        // to be MiB - filesizeBase only affects the numbers it prints in its own messages
        $maxFileSize = $this->getValidator()->getAllowedMaxFileSize();
        if ($maxFileSize && $maxFileSize > 0 && !isset($state['config']['maxFilesize'])) {
            $state['config']['maxFilesize'] = $maxFileSize / 1048576; // Bytes -> MiB
        }

        // A chunk has to fit inside PHP's per-request limits. Bringing an oversized chunkSize down
        // also closes a gap in Dropzone.js: chunking only kicks in for files larger than chunkSize
        // (unless forceChunking is set), so a chunkSize above the per-request limit would let a
        // file between the two be sent in a single request that PHP then rejects
        if ($this->getIsChunked()) {
            $maxChunkSize = $this->getMaxChunkSize();
            $chunkSize = $state['config']['chunkSize'] ?? self::DROPZONE_DEFAULT_CHUNK_SIZE;
            if ($chunkSize > $maxChunkSize) {
                $state['config']['chunkSize'] = $maxChunkSize;
            }
        }

        return $state;
    }

    /**
     * Checks if the number of files attached adheres to the $allowedMaxFileNumber defined
     */
    public function validate(): ValidationResult
    {
        $this->beforeExtending('updateValidate', function (ValidationResult $result) {
            $maxFiles = $this->getAllowedMaxFileNumber();
            if ($maxFiles > 0 && $this->getItems()->count() > $maxFiles) {
                $result->addFieldError(
                    $this->getName(),
                    _t(
                        __CLASS__ . '.ErrorMaxFilesReached',
                        'You can only upload {count} file.|You can only upload {count} files.',
                        ['count' => $maxFiles]
                    )
                );
            }
        });

        return parent::validate();
    }

    /**
     * Note the data-schema and data-state attributes are deliberately absent - they're added to the
     * template via $SchemaAttributesHtml. Building them here would recurse infinitely, as
     * FormField::getSchemaDataDefaults() calls getAttributes()
     */
    protected function getDefaultAttributes(): array
    {
        return [
            'class' => $this->extraClass(),
            'type' => 'file',
            'multiple' => $this->getIsMultiUpload(),
            'id' => $this->ID(),
        ];
    }

    /**
     * Gets the number of files allowed for this field
     *
     * @return null|int
     */
    public function getAllowedMaxFileNumber()
    {
        return $this->allowedMaxFileNumber;
    }

    /**
     * Sets the number of files allowed for this field
     *
     * @param $count
     * @return $this
     */
    public function setAllowedMaxFileNumber($count)
    {
        $this->allowedMaxFileNumber = $count;

        return $this;
    }

    /**
     * Check if allowed to upload more than one file
     *
     * @return bool
     */
    public function getIsMultiUpload()
    {
        if (isset($this->multiUpload)) {
            return $this->multiUpload;
        }

        // Guess from record
        $record = $this->getRecord();
        $name = $this->getName();

        // Disabled for has_one components
        if ($record && DataObject::getSchema()->hasOneComponent(get_class($record), $name)) {
            return false;
        }

        return true;
    }

    /**
     * Set upload type to multiple or single
     *
     * @param bool $bool True for multiple, false for single
     * @return $this
     */
    public function setIsMultiUpload($bool)
    {
        $this->multiUpload = $bool;
        return $this;
    }

    public function Type()
    {
        return 'dropzonefield';
    }
}
