<?php

namespace Jurager\Media\Support;

use Jurager\Media\Contracts\FileProcessor;
use Jurager\Media\Processors\PassthroughProcessor;

class FileProcessorRegistry
{
    /** @var array<string, class-string<FileProcessor>> */
    private array $processors = [];

    public function register(string $mimePattern, string $processorClass): void
    {
        $this->processors[$mimePattern] = $processorClass;
    }

    /**
     * Resolve a processor for the given MIME type.
     * Exact match takes priority over wildcards (e.g. 'image/*').
     * Falls back to PassthroughProcessor when no match is found.
     */
    public function resolve(string $mimeType): FileProcessor
    {
        if (isset($this->processors[$mimeType])) {
            return app($this->processors[$mimeType]);
        }

        $prefix = explode('/', $mimeType, 2)[0];
        $wildcard = $prefix.'/*';

        if (isset($this->processors[$wildcard])) {
            return app($this->processors[$wildcard]);
        }

        return app(PassthroughProcessor::class);
    }
}
