<?php

namespace Jurager\Media\Processors;

use Jurager\Media\Contracts\FileProcessor;

class SvgProcessor implements FileProcessor
{
    public function process(string $filePath, string $mimeType): ProcessResult
    {
        return new ProcessResult($filePath, $this->extractProperties($filePath));
    }

    private function extractProperties(string $filePath): array
    {
        $xml = @simplexml_load_string((string) file_get_contents($filePath));

        if ($xml === false) {
            return [];
        }

        $attrs = $xml->attributes();
        $width = isset($attrs['width']) ? (int) $attrs['width'] : null;
        $height = isset($attrs['height']) ? (int) $attrs['height'] : null;

        if ((! $width || ! $height) && isset($attrs['viewBox'])) {
            $parts = preg_split('/[\s,]+/', trim((string) $attrs['viewBox']));

            if (is_array($parts) && count($parts) === 4) {
                $width = $width ?: (int) $parts[2];
                $height = $height ?: (int) $parts[3];
            }
        }

        return array_filter(['width' => $width, 'height' => $height]);
    }
}
