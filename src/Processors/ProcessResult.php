<?php

namespace Jurager\Media\Processors;

class ProcessResult
{
    public function __construct(
        /** Absolute path to the file to store. May be a temp file. */
        public readonly string $path,
        /** Extracted metadata — e.g. ['width' => 1920, 'height' => 1080]. */
        public readonly array $properties,
    ) {}
}
