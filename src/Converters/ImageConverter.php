<?php

namespace Jurager\Media\Converters;

use Jurager\Media\Contracts\Converter;
use Jurager\Media\Conversions\Conversion;
use Jurager\Media\Models\Media;
use Jurager\Media\Support\ImageManagerFactory;

class ImageConverter implements Converter
{
    public function convert(string $sourcePath, Conversion $conversion, Media $media): ConversionResult
    {
        $image = ImageManagerFactory::make()->read($sourcePath);

        $hasWidth = $conversion->getWidth() > 0;
        $hasHeight = $conversion->getHeight() > 0;

        if ($hasWidth || $hasHeight) {
            match ($conversion->getFitMethod()) {
                'cover' => $image->cover(
                    $conversion->getWidth() ?: $conversion->getHeight(),
                    $conversion->getHeight() ?: $conversion->getWidth(),
                ),
                'contain' => $image->contain(
                    $conversion->getWidth() ?: $conversion->getHeight(),
                    $conversion->getHeight() ?: $conversion->getWidth(),
                ),
                default => $image->scale(
                    $hasWidth ? $conversion->getWidth() : null,
                    $hasHeight ? $conversion->getHeight() : null,
                ),
            };
        }

        // Capture dimensions before encoding — EncodedImage does not expose width/height
        $resultWidth = $image->width();
        $resultHeight = $image->height();

        $format = $conversion->getFormat();
        $quality = $conversion->getQuality();

        $encoded = match ($format) {
            'webp' => $image->toWebp($quality),
            'png' => $image->toPng(),
            'gif' => $image->toGif(),
            'avif' => $image->toAvif($quality),
            default => $image->toJpeg($quality),
        };

        $ext = $format ?: pathinfo($media->file_name, PATHINFO_EXTENSION);
        $tmpFile = tempnam(sys_get_temp_dir(), 'jurager_conv_img_');
        file_put_contents($tmpFile, (string) $encoded);

        return new ConversionResult(
            path: $tmpFile,
            extension: $ext,
            width: $resultWidth,
            height: $resultHeight,
        );
    }
}
