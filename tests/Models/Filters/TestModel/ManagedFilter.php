<?php

namespace Innoboxrr\SearchSurge\Tests\Models\Filters\TestModel;

use Innoboxrr\SearchSurge\Search\Utils\Managed;

class ManagedFilter extends Managed
{
    public static function canView($query, $user, array $args = [])
    {
        return $query->where('owner_id', $user->getAuthIdentifier());
    }
}
