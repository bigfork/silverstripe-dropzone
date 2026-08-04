<?php

namespace Bigfork\SilverStripeDropzone;

use SilverStripe\Assets\Storage\AssetContainer;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Forms\FileUploadReceiver;

/**
 * A FileUploadReceiver designed specifically for use with Dropzone.js.
 * Supports single file per request, multiple files per request, and
 * multiple requests per file (i.e. "chunked") file uploads
 */
trait DropzoneFileUploadReceiver
{
    use FileUploadReceiver;

    /**
     * How long, in seconds, a chunk file from an incomplete upload is kept before it's treated as
     * abandoned and swept up. Set to 0 to disable the sweep.
     *
     * @config
     * @var int
     */
    private static $chunk_max_age = 86400;

    /**
     * Required POST data for chunked file uploads
     *
     * @var array
     */
    protected static $required_chunk_data = [
        'dzuuid',
        'dzchunkbyteoffset',
        'dzchunkindex',
        'dztotalchunkcount',
        'dzchunksize',
        'dztotalfilesize'
    ];

    /**
     * @param HTTPRequest $request
     * @param array $errors
     * @return array|null
     */
    public function saveTemporaryFilesFromRequest(HTTPRequest $request, &$errors = [])
    {
        // Save a chunked file
        if ($request->postVar('dzchunkindex') !== null) {
            $result = $this->saveTemporaryFileChunkFromRequest($request, $errors);
            return ($result) ? [$result] : null;
        }

        // Save multiple files sent in a single request
        $tmpFile = $request->postVar('file');
        if (isset($tmpFile['tmp_name']) && is_array($tmpFile['tmp_name'])) {
            return $this->saveMultipleTemporaryFilesFromRequest($request, $errors);
        }

        // Fall back to default one-file-per-request behaviour
        $result = $this->saveTemporaryFile($tmpFile, $error);
        if ($error !== null) {
            $errors[] = $error;
        }

        return [$result];
    }

    /**
     * @param HTTPRequest $request
     * @param array $errors
     * @return array
     */
    protected function saveMultipleTemporaryFilesFromRequest(HTTPRequest $request, &$errors)
    {
        $tmpFile = $request->postVar('file');
        $count = count($tmpFile['tmp_name']);

        $tmpFiles = [];
        for ($i = 0; $i < $count; $i++) {
            $tmpFiles[] = [
                'name' => $tmpFile['name'][$i],
                'type' => $tmpFile['type'][$i],
                'tmp_name' => $tmpFile['tmp_name'][$i],
                'error' => $tmpFile['error'][$i],
                'size' => $tmpFile['size'][$i]
            ];
        }

        $result = [];
        foreach ($tmpFiles as $tmpFile) {
            $file = $this->saveTemporaryFile($tmpFile, $error);
            if ($error !== null) {
                $errors[] = $error;
            } else {
                $result[] = $file;
            }
        }

        return $result;
    }

    /**
     * @param HTTPRequest $request
     * @param array $errors
     * @return AssetContainer|null
     */
    protected function saveTemporaryFileChunkFromRequest(HTTPRequest $request, &$errors)
    {
        // Validate required data is present
        foreach (static::$required_chunk_data as $required) {
            if ($request->postVar($required) === null) {
                $errors[] = sprintf('Required POST data "%s" missing', $required);
                return null;
            }
        }

        $tmpFile = $request->postVar('file');

        // PHP rejects an upload over upload_max_filesize before it reaches userland, leaving an
        // error code and no usable temp file
        if (!empty($tmpFile['error'])) {
            $errors[] = 'File chunk upload failed';
            $this->deleteChunks($request);
            return null;
        }

        // A chunk is posted as an ordinary file upload, so what applies here is PHP's per-request
        // limit - not the field's maximum file size, which governs the reassembled file and may
        // legitimately be far larger
        if ($tmpFile['size'] > DropzoneUploadValidator::getPHPMaxUploadSize()) {
            $errors[] = 'File chunk is too large';
            $this->deleteChunks($request);
            return null;
        }

        // Reject an oversized file before accepting any of it, rather than after reassembling the
        // whole thing. dztotalfilesize comes from the client, so this is there to fail fast, not to
        // enforce anything - the reassembled file is checked again by saveTemporaryFile() below
        $maxFileSize = $this->getValidator()->getAllowedMaxFileSize(
            pathinfo($tmpFile['name'] ?? '', PATHINFO_EXTENSION)
        );
        if ($maxFileSize && (int)$request->postVar('dztotalfilesize') >= $maxFileSize) {
            $errors[] = _t(
                __CLASS__ . '.ErrorFileTooLarge',
                'File is too large'
            );
            $this->deleteChunks($request);
            return null;
        }

        // Nothing cleans up after an upload that's abandoned part-way through, so sweep any chunks
        // old enough to be certain they're dead whenever a new upload starts
        if ((int)$request->postVar('dzchunkindex') === 0) {
            $this->deleteStaleChunks();
        }

        $chunkPath = $this->getPathforChunkIndex($request, $request->postVar('dzchunkindex'));
        $fp = fopen($chunkPath, 'c'); // Create or open the file
        fseek($fp, $request->postVar('dzchunkbyteoffset')); // Seek to the chunk start offset

        // Copy the uploaded file data to the chunk file location
        $tmpFp = fopen($tmpFile['tmp_name'], 'r');
        stream_copy_to_stream($tmpFp, $fp, $request->postVar('dzchunksize'));
        fclose($fp);
        fclose($tmpFp);

        // If this isn't the final chunk, bail out early
        if (!$this->isFinalChunk($request)) {
            return null;
        }

        // Combine the chunks into the tmp file - so we can just re-use saveTemporaryFile()
        $filesize = $this->combineChunksIntoFile($tmpFile['tmp_name'], $request);
        $tmpFile['size'] = $filesize;

        // The chunks have served their purpose once they're reassembled, whether or not the file
        // itself turns out to be valid
        $this->deleteChunks($request);

        $result = $this->saveTemporaryFile($tmpFile, $error);
        if ($error !== null) {
            $errors[] = $error;
        }

        return $result;
    }

    /**
     * @param HTTPRequest $request
     * @param int $index
     * @return string
     */
    protected function getPathforChunkIndex(HTTPRequest $request, $index)
    {
        $filename = "{$request->postVar('dzuuid')}-chunk{$index}";
        return TEMP_PATH . DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * @param HTTPRequest $request
     * @return bool
     */
    protected function isFinalChunk(HTTPRequest $request)
    {
        $allFilesExist = true;
        for ($i = 0; $i < $request->postVar('dztotalchunkcount'); $i++) {
            if (!file_exists($this->getPathforChunkIndex($request, $i))) {
                $allFilesExist = false;
            }
        }

        return $allFilesExist;
    }

    /**
     * Removes the chunk files belonging to the upload this request is part of
     *
     * @param HTTPRequest $request
     */
    protected function deleteChunks(HTTPRequest $request): void
    {
        for ($i = 0; $i < $request->postVar('dztotalchunkcount'); $i++) {
            $path = $this->getPathforChunkIndex($request, $i);
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Removes chunk files left behind by uploads that never completed. An upload that's abandoned
     * (or that fails before its final chunk) has nothing else to clean up after it, and stale chunks
     * would otherwise make isFinalChunk() return true early for a retry reusing the same dzuuid.
     */
    protected function deleteStaleChunks(): void
    {
        $maxAge = (int)static::config()->get('chunk_max_age');
        if ($maxAge <= 0) {
            return;
        }

        $cutoff = time() - $maxAge;
        $pattern = TEMP_PATH . DIRECTORY_SEPARATOR . '*-chunk[0-9]*';

        foreach (glob($pattern) ?: [] as $path) {
            if (is_file($path) && filemtime($path) < $cutoff) {
                unlink($path);
            }
        }
    }

    /**
     * @param $filename
     * @param HTTPRequest $request
     * @return int
     */
    protected function combineChunksIntoFile($filename, HTTPRequest $request)
    {
        $fp = fopen($filename, 'c');
        $bytesWritten = 0;
        for ($i = 0; $i < $request->postVar('dztotalchunkcount'); $i++) {
            $chunkFp = fopen($this->getPathforChunkIndex($request, $i), 'r');
            $bytesWritten += stream_copy_to_stream($chunkFp, $fp, -1, $bytesWritten);
            fclose($chunkFp);
        }
        fclose($fp);

        return $bytesWritten;
    }
}
