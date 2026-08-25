<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    /** Authenticates the given (or a fresh) user against the sanctum guard. */
    protected function actingAsApiUser(?User $user = null): User
    {
        $user = $user ?: User::factory()->create();

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Within one test the application instance is reused, so a guard that has
     * already resolved a user keeps returning it. Real requests each get a
     * fresh container; this restores that behaviour when a single test makes
     * several calls as different identities.
     */
    protected function forgetResolvedUser(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }
}
