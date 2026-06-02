<?php /** @noinspection PhpComposerExtensionStubsInspection */
/** @noinspection ALL */
/** @noinspection ALL */
/** @noinspection ALL */
/** @noinspection ALL */
/** @noinspection ALL */
/** @noinspection ALL */
/** @noinspection ALL */
/** @noinspection ALL */
/** @noinspection ALL */
/** @noinspection ALL */
/** @noinspection ALL */
/** @noinspection ALL */
/** @noinspection ALL */
/** @noinspection ALL */

/** @noinspection ALL */

namespace Jurager\Media\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Jurager\Media\Jobs\PerformConversionsJob;
use Jurager\Media\Models\Media;
use Jurager\Media\Models\MediaConversion;
use RuntimeException;

class FileAdder
{
    protected UploadedFile|string $file;

    /** 'upload' | 'url' | 'base64' | 'disk' */
    protected string $sourceType = 'upload';

    protected array $urlHeaders = [];
    protected string $base64MimeType = '';
    protected string $sourceDisk = '';

    protected string $collection = 'default';
    protected string $customName = '';
    protected string $customFileName = '';
    protected bool $preservingOriginal = false;

    /** Temp files created during processing; all cleaned up after upload. */
    protected array $tempFiles = [];

    public function __construct(protected Model $subject) {}

    public function setFile(UploadedFile|string $file): static
    {
        $this->file = $file;
        $this->sourceType = 'upload';

        return $this;
    }

    public function setFileFromUrl(string $url, array $headers = []): static
    {
        $this->file = $url;
        $this->sourceType = 'url';
        $this->urlHeaders = $headers;

        return $this;
    }

    public function setFileFromBase64(string $base64, string $mimeType): static
    {
        $this->file = $base64;
        $this->sourceType = 'base64';
        $this->base64MimeType = $mimeType;

        return $this;
    }

    public function setFileFromDisk(string $path, string $disk): static
    {
        $this->file = $path;
        $this->sourceType = 'disk';
        $this->sourceDisk = $disk;

        return $this;
    }

    public function usingName(string $name): static
    {
        $this->customName = $name;

        return $this;
    }

    public function usingFileName(string $fileName): static
    {
        $this->customFileName = $fileName;

        return $this;
    }

    public function preservingOriginal(): static
    {
        $this->preservingOriginal = true;

        return $this;
    }

    public function toMediaCollection(string $collection = 'default'): Media
    {
        $this->collection = $collection;

        $this->guardAgainstCollectionConstraints();

        if ($this->isSingleFileCollection()) {
            $this->subject->clearMediaCollection($collection);
        }

        $media = $this->uploadAndCreate();

        // onlyKeepLatest(n > 1): purge oldest files that exceed the limit
        $limit = $this->getCollectionSizeLimit();
        if ($limit > 1) {
            $this->subject->media()
                ->where('collection_name', $collection)
                ->orderByDesc('order_column')
                ->skip($limit)
                ->get()
                ->each->delete();

            $this->subject->unsetRelation('media');
        }

        return $media;
    }

    protected function uploadAndCreate(): Media
    {
        try {
            return $this->doUpload();
        } finally {
            $this->cleanupTempFiles();
        }
    }

    protected function doUpload(): Media
    {
        $sourcePath = $this->resolveSourcePath();

        $processor = new ImageProcessor;
        [$uploadPath, $properties] = $processor->process($sourcePath);

        if ($uploadPath !== $sourcePath) {
            $this->tempFiles[] = $uploadPath;
        }

        // For URL/base64/disk sources, validate actual MIME after download
        $this->guardAgainstActualMimeType($uploadPath);

        $hash = md5_file($uploadPath);

        if (config('media.deduplication', true)) {
            $existing = $this->findDuplicate($hash);

            if ($existing) {
                return $existing;
            }
        }

        [$fileName, $name, $mimeType, $size] = $this->resolveFileInfo($uploadPath);
        $safeFileName = $this->sanitizeFileName($fileName);

        $collectionDef = method_exists($this->subject, 'getMediaCollection')
            ? $this->subject->getMediaCollection($this->collection)
            : null;

        $disk = $collectionDef?->getDisk() ?? config('media.disk', 's3');

        /** @var PathGenerator $generator */
        $generator = app(config('media.path_generator', PathGenerator::class));

        $mediaClass = config('media.models.media', Media::class);

        /** @var Media $media */
        $media = new $mediaClass;
        $media->uuid = (string) Str::uuid();
        $media->mediable_type = $this->subject->getMorphClass();
        $media->mediable_id = $this->subject->getKey();
        $media->collection_name = $this->collection;
        $media->name = $name;
        $media->file_name = $safeFileName;
        $media->mime_type = $mimeType;
        $media->disk = $disk;
        $media->size = $size;
        $media->hash = $hash;
        $media->order_column = $this->getNextOrderColumn();
        $media->properties = $properties ?: null;
        $media->save();

        $handle = fopen($uploadPath, 'rb');

        try {
            Storage::disk($disk)->put(
                $generator->getPath($media) . $safeFileName,
                $handle,
            );
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

        }

        $this->dispatchConversions($media, $collectionDef);

        return $media;
    }

    protected function resolveSourcePath(): string
    {
        return match ($this->sourceType) {
            'url'    => $this->downloadFromUrl(),
            'base64' => $this->decodeBase64(),
            'disk'   => $this->downloadFromDisk(),
            default  => $this->resolveUploadPath(),
        };
    }

    protected function resolveUploadPath(): string
    {
        if ($this->file instanceof UploadedFile) {
            return $this->file->getPathname();
        }

        if (! file_exists($this->file)) {
            throw new InvalidArgumentException("File not found: {$this->file}");
        }

        if ($this->preservingOriginal) {
            $tmpFile = tempnam(sys_get_temp_dir(), 'jurager_media_copy_');
            $this->tempFiles[] = $tmpFile;
            copy($this->file, $tmpFile);

            return $tmpFile;
        }

        return $this->file;
    }

    /**
     * @throws ConnectionException
     */
    protected function downloadFromUrl(): string
    {
        $this->guardAgainstSsrf($this->file);

        $tmpFile = tempnam(sys_get_temp_dir(), 'jurager_media_url_');
        $this->tempFiles[] = $tmpFile;

        $response = Http::withHeaders($this->urlHeaders)
            ->withOptions(['sink' => $tmpFile, 'timeout' => config('media.download_timeout', 60)])
            ->get($this->file);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Failed to download file from [{$this->file}]: HTTP {$response->status()}"
            );
        }

        if (! $this->customFileName) {
            $urlPath = parse_url($this->file, PHP_URL_PATH);
            $this->customFileName = basename($urlPath ?: 'file');
        }

        return $tmpFile;
    }

    protected function decodeBase64(): string
    {
        $data = preg_replace('/^data:[^;]+;base64,/', '', $this->file);
        $content = base64_decode($data, strict: true);

        if ($content === false) {
            throw new InvalidArgumentException('Invalid base64 string.');
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'jurager_media_b64_');
        $this->tempFiles[] = $tmpFile;
        file_put_contents($tmpFile, $content);

        return $tmpFile;
    }

    protected function downloadFromDisk(): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'jurager_media_disk_');
        $this->tempFiles[] = $tmpFile;

        $stream = Storage::disk($this->sourceDisk)->readStream($this->file);

        if ($stream === null) {
            throw new RuntimeException(
                "File [{$this->file}] not found on disk [{$this->sourceDisk}]."
            );
        }

        $dest = fopen($tmpFile, 'wb');

        try {
            stream_copy_to_stream($stream, $dest);
        } finally {
            fclose($dest);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $this->customFileName) {
            $this->customFileName = basename($this->file);
        }

        return $tmpFile;
    }

    protected function resolveFileInfo(string $uploadPath): array
    {
        if ($this->file instanceof UploadedFile) {
            $fileName = $this->customFileName ?: $this->file->getClientOriginalName();
            $mimeType = mime_content_type($uploadPath) ?: $this->file->getClientMimeType();
        } else {
            $fileName = $this->customFileName ?: 'file';
            $mimeType = mime_content_type($uploadPath) ?: 'application/octet-stream';
        }

        $name = $this->customName ?: pathinfo($fileName, PATHINFO_FILENAME);
        $size = filesize($uploadPath);

        return [$fileName, $name, $mimeType, $size];
    }

    protected function findDuplicate(string $hash): ?Media
    {
        $mediaClass = config('media.models.media', Media::class);

        return $mediaClass::query()
            ->where('mediable_type', $this->subject->getMorphClass())
            ->where('mediable_id', $this->subject->getKey())
            ->where('collection_name', $this->collection)
            ->where('hash', $hash)
            ->first();
    }

    protected function dispatchConversions(Media $media, mixed $collectionDef): void
    {
        if (! method_exists($this->subject, 'getConversionsForCollection')) {
            return;
        }

        if ($collectionDef && ! $collectionDef->shouldPerformConversions()) {
            return;
        }

        $all = $this->subject->getConversionsForCollection($this->collection);

        if (empty($all)) {
            return;
        }

        $convDisk = $collectionDef?->getConversionsDisk()
            ?? config('media.conversions_disk')
            ?? $media->disk;

        $mediaConversionClass = config('media.models.media_conversion', MediaConversion::class);

        // Create a pending record for every applicable conversion
        foreach ($all as $conversion) {
            $mediaConversionClass::create([
                'media_id'  => $media->id,
                'name'      => $conversion->name,
                'status'    => 'pending',
                'disk'      => $convDisk,
                'extension' => $conversion->getFormat() ?: pathinfo($media->file_name, PATHINFO_EXTENSION),
            ]);
        }

        $sync  = array_values(array_filter($all, static fn ($c) => ! $c->isQueued()));
        $async = array_values(array_filter($all, static fn ($c) => $c->isQueued()));

        if (! empty($sync)) {
            PerformConversionsJob::dispatchSync($media, $sync);
        }

        if (! empty($async)) {
            collect($async)
                ->groupBy(fn ($c) => $c->getQueue())
                ->each(function ($group, string $queue) use ($media): void {
                    PerformConversionsJob::dispatch($media, $group->values()->all())
                        ->onQueue($queue);
                });
        }
    }

    private const array BLOCKED_EXTENSIONS = [
        'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7',
        'pl', 'py', 'rb', 'cgi',
        'exe', 'bat', 'cmd', 'sh', 'bash', 'ps1',
        'htaccess', 'htpasswd',
    ];

    protected function sanitizeFileName(string $fileName): string
    {
        $ext  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $base = Str::slug(pathinfo($fileName, PATHINFO_FILENAME)) ?: 'file';

        if ($ext && in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
            throw new InvalidArgumentException(
                "File extension [{$ext}] is not allowed for security reasons."
            );
        }

        return $ext ? "{$base}.{$ext}" : $base;
    }

    /**
     * @throws \Throwable
     */
    protected function getNextOrderColumn(): int
    {
        $mediaClass = config('media.models.media', Media::class);

        return DB::transaction(function () use ($mediaClass): int {
            $rows = $mediaClass::query()
                ->where('mediable_type', $this->subject->getMorphClass())
                ->where('mediable_id', $this->subject->getKey())
                ->where('collection_name', $this->collection)
                ->lockForUpdate()
                ->get(['order_column']);

            return (int) $rows->max('order_column');
        });
    }

    protected function isSingleFileCollection(): bool
    {
        if (! method_exists($this->subject, 'getMediaCollection')) {
            return false;
        }

        return $this->subject->getMediaCollection($this->collection)?->isSingleFile() ?? false;
    }

    protected function getCollectionSizeLimit(): int
    {
        if (! method_exists($this->subject, 'getMediaCollection')) {
            return 0;
        }

        return $this->subject->getMediaCollection($this->collection)?->getCollectionSizeLimit() ?? 0;
    }

    protected function guardAgainstSsrf(string $url): void
    {
        $allowed = config('media.allowed_domains', []);

        if ($allowed === ['*']) {
            return;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! $host || ! in_array($host, (array) $allowed, true)) {
            throw new InvalidArgumentException(
                "Domain [{$host}] is not in the media.allowed_domains list."
            );
        }
    }

    protected function guardAgainstActualMimeType(string $uploadPath): void
    {
        if ($this->file instanceof UploadedFile) {
            return;
        }

        if (! method_exists($this->subject, 'getMediaCollection')) {
            return;
        }

        $collection = $this->subject->getMediaCollection($this->collection);

        if (! $collection) {
            return;
        }

        $allowed = $collection->getAllowedMimeTypes();

        if (empty($allowed)) {
            return;
        }

        $actualMime = mime_content_type($uploadPath) ?: 'application/octet-stream';

        if (! in_array($actualMime, $allowed, true)) {
            throw new InvalidArgumentException(
                "File type [{$actualMime}] is not allowed in collection [{$this->collection}]. "
                . 'Allowed: ' . implode(', ', $allowed)
            );
        }
    }

    protected function guardAgainstCollectionConstraints(): void
    {
        if (! method_exists($this->subject, 'getMediaCollection')) {
            return;
        }

        $collection = $this->subject->getMediaCollection($this->collection);

        if (! $collection) {
            return;
        }

        $allowed = $collection->getAllowedMimeTypes();

        if (! empty($allowed) && $this->file instanceof UploadedFile) {
            $mimeType = $this->file->getMimeType() ?? $this->file->getClientMimeType();

            if (! in_array($mimeType, $allowed, true)) {
                throw new InvalidArgumentException(
                    "File type [{$mimeType}] is not allowed in collection [{$this->collection}]. "
                    . 'Allowed: ' . implode(', ', $allowed)
                );
            }
        }

        $maxSize = $collection->getMaxFileSize();

        if ($maxSize > 0 && $this->file instanceof UploadedFile && $this->file->getSize() > $maxSize) {
            throw new InvalidArgumentException(
                "File size [{$this->file->getSize()} bytes] exceeds the maximum [{$maxSize} bytes] "
                . "for collection [{$this->collection}]."
            );
        }

        $acceptor = $collection->getFileAcceptor();

        if ($acceptor !== null && $this->file instanceof UploadedFile && !$acceptor($this->file, $collection)) {
            throw new InvalidArgumentException(
                "The uploaded file was rejected by the custom validator for collection [{$this->collection}]."
            );
        }
    }

    protected function cleanupTempFiles(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
