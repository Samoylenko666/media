<?php

namespace Jurager\Media\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Jurager\Media\Conversions\Conversion;
use Jurager\Media\MediaCollection;
use Jurager\Media\Models\Media;
use Jurager\Media\Support\FileAdder;

interface InteractsWithMedia
{
    public function media(): MorphMany;

    public function addMedia(UploadedFile|string $file): FileAdder;

    public function addMediaFromUrl(string $url, array $headers = []): FileAdder;

    public function addMediaFromBase64(string $base64, string $mimeType = ''): FileAdder;

    public function addMediaFromDisk(string $path, string $disk): FileAdder;

    // â”€â”€â”€ Copying â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function copyMediaFrom(object $source, string|array|null $collections = null): void;

    // â”€â”€â”€ Scopes â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function scopeWithMedia(Builder $query, string|array|null $collections = null): Builder;

    // â”€â”€â”€ Retrieval â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function getMedia(string $collection = 'default'): Collection;

    public function getFirstMedia(string $collection = 'default'): ?Media;

    public function getLastMedia(string $collection = 'default'): ?Media;

    public function getFirstMediaUrl(string $collection = 'default', string $conversion = ''): string;

    public function getLastMediaUrl(string $collection = 'default', string $conversion = ''): string;

    public function hasMedia(string $collection = 'default'): bool;

    // â”€â”€â”€ Ordering â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function reorderMedia(string $collection, array $orderedIds): void;

    // â”€â”€â”€ Cleanup â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function clearMediaCollection(string $collection = 'default'): static;

    public function clearMediaCollectionExcept(string $collection = 'default', Media|iterable $except = []): static;

    // â”€â”€â”€ Conversions & Collections â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function registerMediaConversions(Media $media): void;

    public function registerMediaCollections(): void;

    public function addMediaConversion(string $name): Conversion;

    public function addMediaCollection(string $name): MediaCollection;

    public function getConversionsForMedia(Media $media): array;

    public function getRegisteredMediaConversions(): array;

    /** @return array<string, MediaCollection> */
    public function getRegisteredMediaCollections(): array;

    public function getMediaCollection(string $name): ?MediaCollection;
}
