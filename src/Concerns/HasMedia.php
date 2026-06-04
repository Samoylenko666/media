<?php

namespace Jurager\Media\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Jurager\Media\Conversions\Conversion;
use Jurager\Media\MediaCollection;
use Jurager\Media\Models\Media;
use Jurager\Media\Models\MediaConversion;
use Jurager\Media\Support\FileAdder;
use Jurager\Media\Support\PathGenerator;
use PHPUnit\Framework\Assert;
use Throwable;

trait HasMedia
{
    /** @var Conversion[] */
    protected array $mediaConversions = [];

    /** @var array<string, MediaCollection> */
    protected array $mediaCollections = [];

    protected bool $mediaCollectionsRegistered = false;

    /** Cached result of registerMediaConversions() — null means not yet built. */
    protected ?array $registeredConversionsCache = null;

    /**
     * When true, all media is deleted automatically when the model is deleted.
     * Set to false if you need custom cleanup logic.
     */
    protected bool $deleteMediaOnDelete = true;

    /**
     * Automatically clean up media when the model is deleted.
     * Called by Laravel during model boot via the boot{TraitName}() convention.
     */
    public static function bootHasMedia(): void
    {
        static::deleting(static function (self $model): void {
            if (! $model->deleteMediaOnDelete) {
                return;
            }

            // For models using SoftDeletes, only clean up on permanent (force) deletion.
            if (in_array(SoftDeletes::class, class_uses_recursive($model), true) && ! $model->isForceDeleting()) {
                return;
            }

            $model->media()
                ->chunkById(100, fn (Collection $chunk) => $chunk->each->delete());
        });
    }

    public function media(): MorphMany
    {
        $mediaClass = config('media.models.media', Media::class);

        return $this->morphMany($mediaClass, 'mediable')->orderBy('order_column');
    }

    public function addMedia(UploadedFile|string $file): FileAdder
    {
        return $this->makeFileAdder()->setFile($file);
    }

    /**
     * Download a file from a remote URL and attach it as media.
     *
     * @param  array<string, string>  $headers
     */
    public function addMediaFromUrl(string $url, array $headers = []): FileAdder
    {
        return $this->makeFileAdder()->setFileFromUrl($url, $headers);
    }

    /**
     * Decode a base64-encoded string and attach it as media.
     * Accepts both raw base64 and data URIs (data:image/jpeg;base64,...).
     */
    public function addMediaFromBase64(string $base64, string $mimeType = ''): FileAdder
    {
        return $this->makeFileAdder()->setFileFromBase64($base64, $mimeType);
    }

    /**
     * Copy a file from an existing storage disk and attach it as media.
     * Useful for importing files already on S3 without downloading them locally first.
     *
     * Example: $product->addMediaFromDisk('exports/logo.png', 's3-archive')->toMediaCollection('images');
     */
    public function addMediaFromDisk(string $path, string $disk): FileAdder
    {
        return $this->makeFileAdder()->setFileFromDisk($path, $disk);
    }

    private function makeFileAdder(): FileAdder
    {
        return app(FileAdder::class)->for($this);
    }

    /**
     * Copy media from another model using S3 server-side copy.
     *
     * @param  string|string[]|null  $collections  Collection name(s) to copy; null copies all.
     *
     * @throws Throwable
     */
    public function copyMediaFrom(object $source, string|array|null $collections = null): void
    {
        if (! method_exists($source, 'media')) {
            throw new InvalidArgumentException('Source model must use the HasMedia trait.');
        }

        $query = $source->media();

        if ($collections !== null) {
            $query->whereIn('collection_name', (array) $collections);
        }

        $query->get()->each(fn (Media $media) => $this->copyMediaRecord($media));
    }

    /**
     * @throws Throwable
     */
    protected function copyMediaRecord(Media $original): Media
    {
        /** @var PathGenerator $generator */
        $generator = app(PathGenerator::class);

        $copy = $original->replicate(['conversions']);
        $copy->uuid = (string) Str::uuid();
        $copy->mediable_type = $this->getMorphClass();
        $copy->mediable_id = $this->getKey();
        $copy->order_column = $this->nextOrderColumnFor($original->collection_name);
        $copy->save();

        Storage::disk($original->disk)->copy(
            $generator->getPath($original).$original->file_name,
            $generator->getPath($copy).$copy->file_name,
        );

        $mediaConversionClass = config('media.models.media_conversion', MediaConversion::class);

        foreach ($original->conversions()->where('status', 'done')->get() as $conversion) {
            $conversionFileName = $original->getConversionFileName($conversion->name);

            Storage::disk($conversion->disk)->copy(
                $generator->getPathForConversions($original).$conversionFileName,
                $generator->getPathForConversions($copy).$conversionFileName,
            );

            $mediaConversionClass::create([
                'media_id' => $copy->id,
                'name' => $conversion->name,
                'status' => 'done',
                'disk' => $conversion->disk,
                'extension' => $conversion->extension,
                'size' => $conversion->size,
                'properties' => $conversion->properties,
                'completed_at' => $conversion->completed_at,
            ]);
        }

        $this->unsetRelation('media');

        return $copy;
    }

    /**
     * @throws Throwable
     */
    protected function nextOrderColumnFor(string $collection): int
    {
        $mediaClass = config('media.models.media', Media::class);

        return DB::transaction(function () use ($mediaClass, $collection): int {
            $max = $mediaClass::query()
                ->where('mediable_type', $this->getMorphClass())
                ->where('mediable_id', $this->getKey())
                ->where('collection_name', $collection)
                ->lockForUpdate()
                ->max('order_column');

            return ($max ?? 0) + 1;
        });
    }

    /**
     * Eager-load the media relation to avoid N+1 queries.
     *
     * @param  string|string[]|null  $collections
     */
    public function scopeWithMedia(Builder $query, string|array|null $collections = null): Builder
    {
        if ($collections === null) {
            return $query->with('media');
        }

        return $query->with(['media' => fn ($q) => $q->whereIn('collection_name', (array) $collections)]);
    }

    public function getMedia(string $collection = 'default'): Collection
    {
        if (! $this->relationLoaded('media')) {
            $this->load('media');
        }

        return $this->media->where('collection_name', $collection)->values();
    }

    public function getFirstMedia(string $collection = 'default'): ?Media
    {
        return $this->getMedia($collection)->first();
    }

    public function getLastMedia(string $collection = 'default'): ?Media
    {
        return $this->getMedia($collection)->last();
    }

    /**
     * Return the URL for the first item in a collection.
     * Falls back to the collection's useFallbackUrl() when no media exists.
     */
    public function getFirstMediaUrl(string $collection = 'default', string $conversion = ''): string
    {
        $media = $this->getFirstMedia($collection);

        if ($media !== null) {
            return $media->getUrl($conversion);
        }

        return $this->getMediaCollection($collection)?->getFallbackUrl($conversion) ?? '';
    }

    public function getLastMediaUrl(string $collection = 'default', string $conversion = ''): string
    {
        return $this->getLastMedia($collection)?->getUrl($conversion) ?? '';
    }

    public function hasMedia(string $collection = 'default'): bool
    {
        return $this->getMedia($collection)->isNotEmpty();
    }

    /**
     * Reorder media within a collection by providing Media IDs in the desired order.
     *
     * @param  array<int>  $orderedIds
     *
     * @throws Throwable
     */
    public function reorderMedia(string $collection, array $orderedIds): void
    {
        $mediaClass = config('media.models.media', Media::class);

        DB::transaction(function () use ($mediaClass, $collection, $orderedIds): void {
            foreach ($orderedIds as $position => $id) {
                $mediaClass::query()
                    ->where('id', $id)
                    ->where('mediable_type', $this->getMorphClass())
                    ->where('mediable_id', $this->getKey())
                    ->where('collection_name', $collection)
                    ->update(['order_column' => $position + 1]);
            }
        });

        $this->unsetRelation('media');
    }

    public function clearMediaCollection(string $collection = 'default'): static
    {
        $this->getMedia($collection)->each->delete();
        $this->unsetRelation('media');

        return $this;
    }

    /**
     * Delete all media in a collection except the given item(s).
     */
    public function clearMediaCollectionExcept(string $collection = 'default', Media|iterable $except = []): static
    {
        if ($except instanceof Media) {
            $except = [$except];
        }

        $exceptIds = collect($except)->map(fn (Media $m) => $m->getKey())->all();

        $this->getMedia($collection)
            ->reject(fn (Media $m) => in_array($m->getKey(), $exceptIds, true))
            ->each->delete();

        $this->unsetRelation('media');

        return $this;
    }

    public function registerMediaConversions(Media $media): void {}

    public function registerMediaCollections(): void {}

    public function addMediaConversion(string $name): Conversion
    {
        $conversion = new Conversion($name);
        $this->mediaConversions[] = $conversion;

        return $conversion;
    }

    public function addMediaCollection(string $name): MediaCollection
    {
        $collection = new MediaCollection($name);
        $this->mediaCollections[$name] = $collection;

        return $collection;
    }

    /** @return Conversion[] */
    public function getRegisteredMediaConversions(): array
    {
        if ($this->registeredConversionsCache === null) {
            $this->mediaConversions = [];
            $this->registerMediaConversions(new Media);
            $this->registeredConversionsCache = $this->mediaConversions;
        }

        return $this->registeredConversionsCache;
    }

    /**
     * Return all statically registered MediaCollection instances.
     * Dynamic collections (resolved via resolveDynamicMediaCollection) are not included.
     *
     * @return array<string, MediaCollection>
     */
    public function getRegisteredMediaCollections(): array
    {
        if (! $this->mediaCollectionsRegistered) {
            $this->mediaCollections = [];
            $this->registerMediaCollections();
            $this->mediaCollectionsRegistered = true;
        }

        return $this->mediaCollections;
    }

    /**
     * Return the conversions that apply to a specific collection.
     *
     * If the collection was registered with withConversions() callbacks, those take priority —
     * their conversions are returned without consulting registerMediaConversions() at all.
     * Otherwise, falls back to registerMediaConversions() filtered by performOnCollections().
     *
     * @return Conversion[]
     */
    /**
     * Return the conversions that apply to a specific Media item, filtered by both
     * its collection and its MIME type (via performOnMimeTypes()).
     *
     * @return Conversion[]
     */
    public function getConversionsForMedia(Media $media): array
    {
        return array_values(array_filter(
            $this->getConversionsForCollection($media->collection_name),
            static fn (Conversion $c) => $c->shouldBePerformedOnMimeType($media->mime_type),
        ));
    }

    public function getConversionsForCollection(string $collectionName): array
    {
        $collection = $this->getMediaCollection($collectionName);
        $callbacks = $collection?->getConversionCallbacks() ?? [];

        if (! empty($callbacks)) {
            $this->mediaConversions = [];

            foreach ($callbacks as $callback) {
                $callback(new Media);
            }

            return $this->mediaConversions;
        }

        return array_values(array_filter(
            $this->getRegisteredMediaConversions(),
            static fn (Conversion $c) => $c->shouldBePerformedOn($collectionName),
        ));
    }

    public function getMediaCollection(string $name): ?MediaCollection
    {
        if (! $this->mediaCollectionsRegistered) {
            $this->mediaCollections = [];
            $this->registerMediaCollections();
            $this->mediaCollectionsRegistered = true;
        }

        if (isset($this->mediaCollections[$name])) {
            return $this->mediaCollections[$name];
        }

        $dynamic = $this->resolveDynamicMediaCollection($name);

        if ($dynamic !== null) {
            $this->mediaCollections[$name] = $dynamic;
        }

        return $dynamic;
    }

    /**
     * Fallback hook for resolving a media collection that was not statically
     * registered in registerMediaCollections().
     *
     * Models can override this to construct collections on demand — for example,
     * a model with a dynamic attribute schema can derive a MediaCollection from
     * an external definition (config, database, etc.).
     *
     * Returning null falls through to the default behavior (collection not found).
     */
    protected function resolveDynamicMediaCollection(string $name): ?MediaCollection
    {
        return null;
    }

    public function assertHasMedia(string $collection = 'default', ?int $count = null): void
    {
        $media = $this->getMedia($collection);

        Assert::assertTrue(
            $media->isNotEmpty(),
            "Expected [{$collection}] collection to have media, but it is empty.",
        );

        if ($count !== null) {
            Assert::assertCount(
                $count,
                $media,
                "Expected [{$collection}] to have {$count} item(s), got {$media->count()}.",
            );
        }
    }

    public function assertHasNoMedia(string $collection = 'default'): void
    {
        $media = $this->getMedia($collection);

        Assert::assertTrue(
            $media->isEmpty(),
            "Expected [{$collection}] to be empty, but it has {$media->count()} item(s).",
        );
    }

    public function assertMediaCount(string $collection, int $count): void
    {
        $media = $this->getMedia($collection);

        Assert::assertCount(
            $count,
            $media,
            "Expected [{$collection}] to have {$count} item(s), got {$media->count()}.",
        );
    }
}
