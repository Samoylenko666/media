<?php

namespace Jurager\Media\Contracts;

use Jurager\Media\Processors\ProcessResult;

interface FileProcessor
{
    /**
     * Normalize the file and extract metadata properties.
     *
     * Returns a ProcessResult with the path to use for storage (may be a new
     * temp file, e.g. after EXIF stripping) and any extracted properties such
     * as width/height. The caller is responsible for unlinking the temp file
     * when result->path differs from the original filePath.
     */
    public function process(string $filePath, string $mimeType): ProcessResult;
}
