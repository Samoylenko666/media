<?php

namespace Jurager\Media\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Jurager\Media\Console\Commands\Concerns\ResolvesMorphTypes;
use Jurager\Media\Contracts\InteractsWithMedia;
use Jurager\Media\Contracts\MediaCleaner;
use Jurager\Media\Models\Media;

class MediaCleanCommand extends Command
{
    use ResolvesMorphTypes;

    protected $signature = 'media:clean
                            {--dry-run : List orphaned records without deleting them}
                            {--chunk=100 : Number of records to process at a time}';

    protected $description = 'Delete orphaned media records (no parent, unregistered collection, or custom cleaner rules)';

    public function handle(): int
    {
        $mediaClass = config('media.models.media', Media::class);
        $dryRun = (bool) $this->option('dry-run');
        $chunk = (int) $this->option('chunk');
        $deleted = 0;

        $mediaClass::query()
            ->select(['id', 'mediable_type', 'mediable_id', 'file_name', 'collection_name'])
            ->chunkById($chunk, function (Collection $records) use ($dryRun, &$deleted): void {
                $records->groupBy('mediable_type')->each(function (Collection $group, string $type) use ($dryRun, &$deleted): void {
                    $deleted += $this->processGroup($group, $type, $dryRun);
                });
            });

        $this->info($dryRun
            ? "Dry run: {$deleted} orphaned record(s) would be deleted."
            : "Deleted {$deleted} orphaned media record(s)."
        );

        return self::SUCCESS;
    }

    private function processGroup(Collection $group, string $type, bool $dryRun): int
    {
        $fqcn = $this->resolveModelClass($type);

        if (! class_exists($fqcn)) {
            return $this->markOrphaned($group, $dryRun, 'class does not exist');
        }

        // Pass 1 — parent entity missing.
        $existingIds = $this->fetchExistingIds($fqcn, $group->pluck('mediable_id')->unique());

        [$existing, $missing] = $group->partition(fn ($m) => isset($existingIds[$m->mediable_id]));

        $deleted = $this->markOrphaned($missing, $dryRun, 'parent entity does not exist');

        if ($existing->isEmpty() || ! is_a($fqcn, InteractsWithMedia::class, true)) {
            return $deleted;
        }

        // Pass 2 — collection not registered on the model.
        $instance = new $fqcn;

        [$existing, $unknown] = $existing->partition(
            fn ($m) => $instance->getMediaCollection($m->collection_name) !== null
        );

        $deleted += $this->markOrphaned($unknown, $dryRun, 'collection is not registered');

        // Application-defined passes via config('media.cleaners').
        foreach (config('media.cleaners', []) as $cleanerClass) {
            if ($existing->isEmpty()) {
                break;
            }

            /** @var MediaCleaner $cleaner */
            $cleaner = app($cleanerClass);
            $toDelete = $cleaner->orphaned($existing, $type, $fqcn);

            if ($toDelete->isNotEmpty()) {
                $existing = $existing->diffUsing($toDelete, fn ($a, $b) => $a->id <=> $b->id);
                $deleted += $this->markOrphaned($toDelete, $dryRun, class_basename($cleanerClass));
            }
        }

        return $deleted;
    }

    private function fetchExistingIds(string $fqcn, Collection $ids): Collection
    {
        $query = in_array(SoftDeletes::class, class_uses_recursive($fqcn), true)
            ? $fqcn::withTrashed()
            : $fqcn::query();

        return $query->whereIn('id', $ids)->pluck('id')->flip();
    }

    private function markOrphaned(Collection $records, bool $dryRun, string $reason = ''): int
    {
        foreach ($records as $media) {
            $label = "{$media->mediable_type}#{$media->mediable_id} — {$media->file_name}";

            if ($reason) {
                $label .= " ({$reason})";
            }

            $this->line($dryRun ? "  [dry-run] orphan: {$label}" : "  Deleting: {$label}");

            if (! $dryRun) {
                $media->fresh()?->delete();
            }
        }

        return $records->count();
    }
}
