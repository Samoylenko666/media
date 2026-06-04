<?php

namespace Jurager\Media\Conversions;

use Illuminate\Support\Traits\Conditionable;

class Conversion
{
    use Conditionable;

    protected int $width = 0;

    protected int $height = 0;

    protected string $fitMethod = 'scale'; // scale | cover | contain

    protected int $quality = 80;

    protected string $format = ''; // '', 'jpg', 'webp', 'png', 'gif', 'avif'

    protected bool $queued = true;

    protected string $queueName = '';

    protected array $collections = [];

    protected array $mimeTypes = [];

    public function __construct(public readonly string $name) {}

    public function width(int $width): static
    {
        $this->width = $width;

        return $this;
    }

    public function height(int $height): static
    {
        $this->height = $height;

        return $this;
    }

    /**
     * Resize to exact dimensions, cropping to cover the bounding box.
     */
    public function fit(int $width, int $height): static
    {
        $this->width = $width;
        $this->height = $height;
        $this->fitMethod = 'cover';

        return $this;
    }

    /**
     * Resize to fit within the bounding box without cropping.
     */
    public function contain(int $width, int $height): static
    {
        $this->width = $width;
        $this->height = $height;
        $this->fitMethod = 'contain';

        return $this;
    }

    public function quality(int $quality): static
    {
        $this->quality = $quality;

        return $this;
    }

    public function format(string $format): static
    {
        $this->format = strtolower($format);

        return $this;
    }

    /**
     * Run this conversion synchronously instead of queuing it.
     */
    public function nonQueued(): static
    {
        $this->queued = false;

        return $this;
    }

    /**
     * Dispatch to a specific queue name instead of the default media queue.
     * Has no effect when combined with nonQueued().
     */
    public function onQueue(string $queue): static
    {
        $this->queueName = $queue;

        return $this;
    }

    /**
     * Limit this conversion to specific collection(s).
     * When not called, the conversion runs for every collection.
     */
    public function performOnCollections(string ...$collections): static
    {
        $this->collections = $collections;

        return $this;
    }

    /**
     * Limit this conversion to specific MIME type pattern(s).
     * Supports glob-style wildcards: 'image/*', 'application/pdf'.
     * When not called, the conversion runs for every MIME type.
     */
    public function performOnMimeTypes(string ...$patterns): static
    {
        $this->mimeTypes = $patterns;

        return $this;
    }

    public function shouldBePerformedOn(string $collection): bool
    {
        return empty($this->collections) || in_array($collection, $this->collections, true);
    }

    public function shouldBePerformedOnMimeType(string $mimeType): bool
    {
        if (empty($this->mimeTypes)) {
            return true;
        }

        foreach ($this->mimeTypes as $pattern) {
            if (fnmatch($pattern, $mimeType)) {
                return true;
            }
        }

        return false;
    }

    public function isQueued(): bool
    {
        return $this->queued;
    }

    public function getQueue(): string
    {
        return $this->queueName ?: config('media.queue', 'default');
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function getFitMethod(): string
    {
        return $this->fitMethod;
    }

    public function getQuality(): int
    {
        return $this->quality;
    }

    public function getFormat(): string
    {
        return $this->format;
    }
}
