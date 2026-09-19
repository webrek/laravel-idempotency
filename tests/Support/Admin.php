<?php

namespace Webrek\Idempotency\Tests\Support;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A second Authenticatable model, backed by its own table, so tests can
 * verify that two user classes sharing the same id do not collide.
 */
class Admin extends Authenticatable
{
    protected $guarded = [];

    public $timestamps = false;

    protected $table = 'admins';
}
