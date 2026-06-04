<?php

namespace Jurager\Media\Processors;

use Jurager\Media\Contracts\FileProcessor;

class PassthroughProcessor implements FileProcessor
{
    public function process(string $filePath, string $mimeType): ProcessResult
    {
        return new ProcessResult($filePath, []);
    }
}
