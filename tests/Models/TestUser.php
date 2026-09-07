<?php

namespace Innoboxrr\SearchSurge\Tests\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class TestUser extends Authenticatable
{
    protected $table = 'test_users';

    protected $guarded = [];
}
