<?php

namespace Jurager\Media\Support;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;

class ImageManagerFactory
{
    public static function make(): ImageManager
    {
        $driver = config('media.image_driver', 'gd') === 'imagick'
            ? new ImagickDriver
            : new GdDriver;

        return new ImageManager($driver);
    }
}
