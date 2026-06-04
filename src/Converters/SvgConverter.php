<?php

namespace Jurager\Media\Converters;

use Jurager\Media\Contracts\Converter;
use Jurager\Media\Conversions\Conversion;
use Jurager\Media\Models\Media;

/**
 * Passes SVG files through as-is during conversion.
 *
 * SVG is a vector format — resizing is meaningless and re-encoding would strip
 * inline styles and scripts. The file is copied verbatim; width/height/format
 * conversion settings are ignored.
 *
 * To rasterize SVG thumbnails instead, register a custom converter for
 * 'image/svg+xml' in config/media.php using Imagick's SVG renderer.
 */
class SvgConverter implements Converter
{
    public function convert(string $sourcePath, Conversion $conversion, Media $media): ConversionResult
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'jurager_conv_svg_');
        copy($sourcePath, $tmpFile);

        return new ConversionResult(
            path: $tmpFile,
            extension: 'svg',
        );
    }
}
