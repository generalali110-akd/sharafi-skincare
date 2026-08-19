<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StorageRouteBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_local_storage_is_not_served_over_http_by_default(): void
    {
        $this->assertFalse((bool) config('filesystems.disks.local.serve'));
        $this->assertNull(Route::getRoutes()->getByName('storage.local'));
        $this->assertNull(Route::getRoutes()->getByName('storage.local.upload'));
    }
}
