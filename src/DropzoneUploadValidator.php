<?php

namespace Bigfork\SilverStripeDropzone;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Upload_Validator;
use SilverStripe\Core\Convert;

/**
 * An Upload_Validator that can be told not to clamp its maximum file size to PHP's
 * upload_max_filesize/post_max_size.
 *
 * Those two ini settings are limits on a single request, and Upload_Validator applies the smaller
 * of them as a limit on a single *file* (see parseAndVerifyAllowedSize()). For an ordinary upload
 * those are the same thing. For a chunked upload they aren't: the file arrives across many
 * requests, so nothing in php.ini has any bearing on its total size.
 *
 * DropzoneField turns the clamping off when chunking is enabled, and the per-request limit is
 * enforced against each individual chunk instead - see DropzoneFileUploadReceiver.
 */
class DropzoneUploadValidator extends Upload_Validator
{
    /**
     * @var bool
     */
    private $clampToPHPLimits = true;

    /**
     * Maximum file sizes as configured, before the parent class clamps them
     *
     * @var array
     */
    private $unclampedMaxFileSize = [];

    /**
     * The largest upload PHP will accept in a single request
     */
    public static function getPHPMaxUploadSize(): int
    {
        return min(
            Convert::memstring2bytes(ini_get('upload_max_filesize')),
            Convert::memstring2bytes(ini_get('post_max_size'))
        );
    }

    public function getClampToPHPLimits(): bool
    {
        return $this->clampToPHPLimits;
    }

    public function setClampToPHPLimits(bool $clamp): static
    {
        $this->clampToPHPLimits = $clamp;
        return $this;
    }

    /**
     * Keeps an unclamped copy of the configured sizes alongside the parent's clamped ones, so that
     * getAllowedMaxFileSize() can return either without depending on the order in which the size
     * and the clamping flag were set
     *
     * @param array|int|string $rules
     * @return $this
     */
    public function setAllowedMaxFileSize($rules)
    {
        if (is_array($rules) && count($rules)) {
            $this->unclampedMaxFileSize = array_map(
                fn($value) => $this->parseSize($value),
                array_change_key_case($rules, CASE_LOWER)
            );
        } else {
            $this->unclampedMaxFileSize = ['*' => $this->parseSize($rules)];
        }

        return parent::setAllowedMaxFileSize($rules);
    }

    /**
     * @param string $ext
     * @return int|false Filesize in bytes
     */
    public function getAllowedMaxFileSize($ext = null)
    {
        if ($this->clampToPHPLimits) {
            return parent::getAllowedMaxFileSize($ext);
        }

        // Mirrors the parent's lazy initialisation, minus the clamping. Deferring to the parent
        // here instead would make this method's return value depend on how many times it had been
        // called: the parent initialises by calling setAllowedMaxFileSize(), which populates
        // $unclampedMaxFileSize as a side effect, so the second call would answer differently
        if (empty($this->unclampedMaxFileSize)) {
            $default = static::config()->get('default_max_file_size');
            $this->setAllowedMaxFileSize($default ?: static::getPHPMaxUploadSize());
        }

        // Same lookup order as the parent: exact extension, then app category, then the catch-all
        if ($ext !== null) {
            $ext = strtolower($ext);
            if (isset($this->unclampedMaxFileSize[$ext])) {
                return $this->unclampedMaxFileSize[$ext];
            }

            $category = File::get_app_category($ext);
            if ($category && isset($this->unclampedMaxFileSize["[{$category}]"])) {
                return $this->unclampedMaxFileSize["[{$category}]"];
            }
        }

        return $this->unclampedMaxFileSize['*'] ?? false;
    }

    /**
     * Bytes or ini-format string to bytes, falling back to PHP's limit for an empty value.
     * The parent's equivalent is private, and clamps.
     *
     * @param int|string $value
     */
    private function parseSize($value): int
    {
        $size = 0;
        if (is_numeric($value)) {
            $size = (int)$value;
        } elseif (is_string($value)) {
            $size = Convert::memstring2bytes($value);
        }

        return empty($size) ? static::getPHPMaxUploadSize() : $size;
    }
}
