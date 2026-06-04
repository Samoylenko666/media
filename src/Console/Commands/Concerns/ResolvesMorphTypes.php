<?php

namespace Jurager\Media\Console\Commands\Concerns;

use Illuminate\Database\Eloquent\Relations\Relation;

trait ResolvesMorphTypes
{
    protected function resolveModelClass(string $type): string
    {
        return Relation::getMorphedModel($type) ?? $type;
    }
}
