<?php

namespace Jurager\Media\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Jurager\Media\Conversions\Conversion;
use Jurager\Media\Events\MediaConversionGenerated;
use Jurager\Media\Models\Media;
use Jurager\Media\Models\MediaConversion;
use Jurager\Media\Support\ConverterRegistry;
use Jurager\Media\Support\PathGenerator;

class PerformConversionsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public bool $deleteWhenMissingModels = true;

    /**
     * @param  Conversion[]  $conversions
     */
    public function __construct(
        public readonly Media $media,
        public readonly array $conversions,
    ) {}

    public function uniqueId(): string
    {
        $names = implode('_', array_map(static fn (Conversion $c) => $c->name, $this->conversions));

        return "media_{$this->media->id}_{$names}";
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function backoff(): array
    {
        return [30, 60, 120];
    }

    /**
     * Dependencies are injected via handle() so they are not serialized with the job
     * and are always resolved fresh from the container on each execution.
     *
     * @throws \Throwable
     */
    public function handle(ConverterRegistry $registry, PathGenerator $generator): void
    {
        $mediaConversionClass = config('media.models.media_conversion', MediaConversion::class);
        $names = array_map(static fn (Conversion $c) => $c->name, $this->conversions);

        $mediaConversionClass::where('media_id', $this->media->id)
            ->whereIn('name', $names)
            ->whereIn('status', ['pending', 'failed'])
            ->update(['status' => 'processing', 'error_message' => null]);

        $conversionRecords = $mediaConversionClass::where('media_id', $this->media->id)
            ->whereIn('name', $names)
            ->get()
            ->keyBy('name');

        $originalPath = $generator->getPath($this->media).$this->media->file_name;
        $stream = Storage::disk($this->media->disk)->readStream($originalPath);

        if ($stream === null) {
            $mediaConversionClass::where('media_id', $this->media->id)
                ->whereIn('name', $names)
                ->where('status', 'processing')
                ->update(['status' => 'failed', 'error_message' => 'Original file not found on disk.']);

            return;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'jurager_media_orig_');

        try {
            $dest = fopen($tmpFile, 'wb');
            stream_copy_to_stream($stream, $dest);
            fclose($dest);
            fclose($stream);

            foreach ($this->conversions as $conversion) {
                $record = $conversionRecords->get($conversion->name);
                $conversionTmp = null;

                try {
                    $converter = $registry->resolve($this->media->mime_type);

                    if ($converter === null) {
                        $mediaConversionClass::where('media_id', $this->media->id)
                            ->where('name', $conversion->name)
                            ->update([
                                'status' => 'failed',
                                'error_message' => "No converter registered for [{$this->media->mime_type}].",
                            ]);

                        continue;
                    }

                    $result = $converter->convert($tmpFile, $conversion, $this->media);
                    $conversionTmp = $result->path;

                    $basename = pathinfo($this->media->file_name, PATHINFO_FILENAME);
                    $conversionFileName = "{$basename}-{$conversion->name}.{$result->extension}";
                    $conversionPath = $generator->getPathForConversions($this->media).$conversionFileName;

                    $content = file_get_contents($conversionTmp);
                    $resultSize = strlen($content);

                    $convDisk = $record?->disk ?? config('media.conversions_disk') ?? $this->media->disk;
                    Storage::disk($convDisk)->put($conversionPath, $content);

                    $properties = array_filter([
                        'width' => $result->width,
                        'height' => $result->height,
                    ]);

                    $this->media->markConversionAsGenerated(
                        $conversion->name,
                        $result->extension,
                        $properties,
                        $resultSize,
                    );

                    event(new MediaConversionGenerated($this->media, $conversion));
                } catch (\Throwable $e) {
                    $mediaConversionClass::where('media_id', $this->media->id)
                        ->where('name', $conversion->name)
                        ->update(['status' => 'failed', 'error_message' => $e->getMessage()]);

                    throw $e;
                } finally {
                    if ($conversionTmp !== null && is_file($conversionTmp)) {
                        @unlink($conversionTmp);
                    }
                }
            }
        } finally {
            @unlink($tmpFile);
        }
    }
}
