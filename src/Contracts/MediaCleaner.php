<?php

namespace Jurager\Media\Contracts;

use Illuminate\Support\Collection;

interface MediaCleaner
{
    /**
     * Identify application-specific orphans from a batch of media records that
     * have already passed the package's built-in checks (parent exists, collection
     * is registered). Return only the subset that should be deleted.
     *
     * @param  Collection  $candidates  Media records surviving the built-in passes.
     * @param  string  $type  The morph alias (e.g. 'product').
     * @param  string  $fqcn  The fully-qualified model class name.
     */
    public function orphaned(Collection $candidates, string $type, string $fqcn): Collection;
}
