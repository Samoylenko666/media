<?php

use Jurager\Media\Converters\ImageConverter;
use Jurager\Media\Converters\PdfConverter;
use Jurager\Media\Converters\SvgConverter;
use Jurager\Media\Models\Media;
use Jurager\Media\Models\MediaConversion;
use Jurager\Media\Processors\BitmapImageProcessor;
use Jurager\Media\Processors\SvgProcessor;
use Jurager\Media\Support\PathGenerator;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Storage Disk
    |--------------------------------------------------------------------------
    | The Laravel filesystem disk where original files are stored.
    | Typically an S3-compatible disk defined in config/filesystems.php.
    */
    'disk' => env('MEDIA_DISK', 's3'),

    /*
    |--------------------------------------------------------------------------
    | Conversions Disk
    |--------------------------------------------------------------------------
    | Disk used to store generated conversions. Defaults to the same disk as
    | the original when null. Override per-collection with storeConversionsOnDisk().
    */
    'conversions_disk' => env('MEDIA_CONVERSIONS_DISK', null),

    /*
    |--------------------------------------------------------------------------
    | CDN URL
    |--------------------------------------------------------------------------
    | When set, all getUrl() calls — originals and conversions — prepend this
    | base URL instead of the disk URL. No code changes required in the app.
    | Example: https://d1234example.cloudfront.net
    */
    'cdn_url' => env('MEDIA_CDN_URL', null),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    | Queue name for async conversion jobs. Set to 'sync' to disable queueing
    | globally (not recommended for production — use ->nonQueued() per conversion).
    */
    'queue' => env('MEDIA_QUEUE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Image Driver
    |--------------------------------------------------------------------------
    | Intervention Image driver to use for image processing.
    | 'gd'      — default, available in most environments (ext-gd)
    | 'imagick' — better quality, required for AVIF and PDF conversion (ext-imagick)
    */
    'image_driver' => env('MEDIA_IMAGE_DRIVER', 'gd'),

    /*
    |--------------------------------------------------------------------------
    | Strip EXIF
    |--------------------------------------------------------------------------
    | When true, uploaded images are re-encoded before being stored, which
    | discards EXIF metadata (GPS coordinates, camera model, author, etc.).
    | Disable only if preserving EXIF is required (e.g. internal archival).
    */
    'strip_exif' => env('MEDIA_STRIP_EXIF', true),

    /*
    |--------------------------------------------------------------------------
    | Deduplication
    |--------------------------------------------------------------------------
    | When true, uploading a file computes its MD5 hash and returns the existing
    | Media record if an identical file already exists in the same model+collection.
    | Designed for idempotent imports — safe to run the same import twice.
    */
    'deduplication' => env('MEDIA_DEDUPLICATION', true),

    /*
    |--------------------------------------------------------------------------
    | Download Timeout
    |--------------------------------------------------------------------------
    | HTTP timeout in seconds for addMediaFromUrl() downloads.
    */
    'download_timeout' => env('MEDIA_DOWNLOAD_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Allowed Domains for URL Downloads
    |--------------------------------------------------------------------------
    | Restricts addMediaFromUrl() to specific hostnames to prevent SSRF attacks.
    | Set to ['*'] to allow all domains (trusted internal callers only).
    | When empty (default), all URL downloads are blocked.
    | Example: ['cdn.supplier.com', 'assets.brand.com']
    */
    'allowed_domains' => env('MEDIA_ALLOWED_DOMAINS')
        ? explode(',', env('MEDIA_ALLOWED_DOMAINS'))
        : [],

    /*
    |--------------------------------------------------------------------------
    | Automatic Cleanup Schedule
    |--------------------------------------------------------------------------
    | Time (HH:MM) at which media:clean runs daily. Set to null to disable
    | automatic scheduling and manage the schedule yourself.
    */
    'clean_schedule' => env('MEDIA_CLEAN_SCHEDULE', '01:00'),

    /*
    |--------------------------------------------------------------------------
    | Custom Cleaners
    |--------------------------------------------------------------------------
    | Classes implementing MediaCleaner that run after the built-in passes
    | (no parent, unregistered collection). Each cleaner receives the surviving
    | batch and returns the subset to delete.
    */
    'cleaners' => [],

    /*
    |--------------------------------------------------------------------------
    | File Processors
    |--------------------------------------------------------------------------
    | Maps MIME type patterns to processor classes (implement FileProcessor interface).
    | Processors run at upload time: they normalize the file (e.g. strip EXIF) and
    | extract metadata properties (e.g. width, height) stored in media.properties.
    |
    | Exact match (e.g. 'image/svg+xml') takes priority over wildcards ('image/*').
    | File types with no match fall through to PassthroughProcessor (no-op).
    |
    | Built-in processors:
    |   BitmapImageProcessor — strips EXIF and extracts dimensions for raster images
    |   SvgProcessor         — extracts dimensions from SVG viewBox/width/height attributes
    */
    'processors' => [
        'image/svg+xml' => SvgProcessor::class,
        'image/*' => BitmapImageProcessor::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Converters
    |--------------------------------------------------------------------------
    | Maps MIME type patterns to converter classes (implement Converter interface).
    | Exact match (e.g. 'application/pdf') takes priority over wildcards ('image/*').
    | Register your own converter for any file type — video, SVG, Office docs, etc.
    |
    | Built-in converters:
    |   ImageConverter — handles all raster image/* types via Intervention Image
    |   PdfConverter  — rasterizes a PDF page to an image (requires ext-imagick + Ghostscript)
    |
    | File types with no registered converter will have their conversions marked
    | as failed with a clear error message in media_conversions.error_message.
    */
    'converters' => [
        'image/svg+xml' => SvgConverter::class,
        'image/*' => ImageConverter::class,
        'application/pdf' => PdfConverter::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | PDF Converter Options
    |--------------------------------------------------------------------------
    | Configuration for PdfConverter. Requires ext-imagick and Ghostscript (gs).
    |
    | resolution — DPI used when rasterizing the PDF page. Higher values produce
    |              sharper previews but larger files. 150 is a good default.
    | page       — 0-indexed page number to render (0 = first page).
    */
    'pdf_converter' => [
        'resolution' => env('MEDIA_PDF_RESOLUTION', 150),
        'page' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    | Override the default Eloquent models if you need to extend them.
    */
    'models' => [
        'media' => Media::class,
        'media_conversion' => MediaConversion::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Path Generator
    |--------------------------------------------------------------------------
    | Class responsible for generating storage paths.
    | Default: {ModelClass}/{id}/{collection}/
    | Implement PathGenerator and register your class here to customise.
    */
    'path_generator' => PathGenerator::class,

];
