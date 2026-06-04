<?php

namespace Jurager\Media\Processors;

use Jurager\Media\Contracts\FileProcessor;
use Jurager\Media\Support\ImageManagerFactory;

class BitmapImageProcessor implements FileProcessor
{
    public function process(string $filePath, string $mimeType): ProcessResult
    {
        $properties = $this->extractProperties($filePath);

        if (config('media.strip_exif', true)) {
            $normalized = $this->stripExif($filePath, $mimeType);

            return new ProcessResult($normalized, $properties);
        }

        return new ProcessResult($filePath, $properties);
    }

    private function extractProperties(string $filePath): array
    {
        $size = @getimagesize($filePath);

        if ($size === false) {
            return [];
        }

        return ['width' => $size[0], 'height' => $size[1]];
    }

    private function stripExif(string $filePath, string $mimeType): string
    {
        $image = ImageManagerFactory::make()->read($filePath);

        $encoded = match (true) {
            str_contains($mimeType, 'png') => $image->toPng(),
            str_contains($mimeType, 'gif') => $image->toGif(),
            str_contains($mimeType, 'webp') => $image->toWebp(90),
            str_contains($mimeType, 'avif') => $image->toAvif(90),
            default => $image->toJpeg(95),
        };

        $tmpFile = tempnam(sys_get_temp_dir(), 'jurager_media_exif_');
        file_put_contents($tmpFile, (string) $encoded);

        return $tmpFile;
    }
}
