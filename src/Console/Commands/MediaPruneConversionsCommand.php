<?php

namespace Jurager\Media\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Jurager\Media\Console\Commands\Concerns\ResolvesMorphTypes;
use Jurager\Media\Contracts\InteractsWithMedia;
use Jurager\Media\Models\Media;
use Jurager\Media\Models\MediaConversion;
use Jurager\Media\Support\PathGenerator;

class MediaPruneConversionsCommand extends Command
{
    use ResolvesMorphTypes;

    protected $signature = 'media:prune-conversions
                            {--dry-run : List stale conversions without deleting them}
                            {--chunk=100 : Number of media records to process at a time}';

    protected $description = 'Delete conversion files and records that are no longer defined on the model';

    public function handle(PathGenerator $generator): int
    {
        $mediaClass = config('media.models.media', Media::class);
        $mediaConversionClass = config('media.models.media_conversion', MediaConversion::class);
        $dryRun = (bool) $this->option('dry-run');
        $chunk = (int) $this->option('chunk');
        $pruned = 0;

        $mediaClass::query()
            ->with('conversions')
            ->chunkById($chunk, function ($records) use ($mediaConversionClass, $generator, $dryRun, &$pruned): void {
                foreach ($records as $media) {
                    $fqcn = $this->resolveModelClass($media->mediable_type);

                    if (! class_exists($fqcn) || ! is_a($fqcn, InteractsWithMedia::class, true)) {
                        continue;
                    }

                    $instance = new $fqcn;
                    $defined = array_map(
                        static fn ($c) => $c->name,
                        $instance->getConversionsForCollection($media->collection_name),
                    );

                    $stale = $media->conversions->filter(
                        fn ($conv) => ! in_array($conv->name, $defined, true)
                    );

                    foreach ($stale as $conv) {
                        $basename = pathinfo($media->file_name, PATHINFO_FILENAME);
                        $conversionFile = "{$basename}-{$conv->name}.{$conv->extension}";
                        $conversionPath = $generator->getPathForConversions($media).$conversionFile;

                        $this->line($dryRun
                            ? "  [dry-run] stale: {$media->mediable_type}#{$media->mediable_id} — {$conv->name}"
                            : "  Pruning: {$media->mediable_type}#{$media->mediable_id} — {$conv->name}"
                        );

                        if (! $dryRun) {
                            $disk = $conv->disk ?? config('media.conversions_disk') ?? $media->disk;
                            Storage::disk($disk)->delete($conversionPath);
                            $mediaConversionClass::where('id', $conv->id)->delete();
                        }

                        $pruned++;
                    }
                }
            });

        $this->info($dryRun
            ? "Dry run: {$pruned} stale conversion(s) would be pruned."
            : "Pruned {$pruned} stale conversion(s)."
        );

        return self::SUCCESS;
    }
}
