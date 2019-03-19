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

        // Early file size check - to stop someone posting massive chunks
        $tmpFile = $request->postVar('file');
        $validator = $this->getUpload()->getValidator();
        $validator->setTmpFile($tmpFile);
        if (!$validator->isValidSize()) {
            $errors[] = 'File chunk is too large';
            return null;
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
