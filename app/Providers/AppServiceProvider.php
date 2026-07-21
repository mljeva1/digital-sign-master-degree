<?php

namespace App\Providers;

use App\Support\Testing\ArtisanTestSchemaMigrator;
use App\Support\Testing\DatabaseNameResolver;
use App\Support\Testing\LiveDatabaseNameResolver;
use App\Support\Testing\TestSchemaMigrator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Seams for the fail-closed PostgreSQL test-schema preflight
        // (testing:prepare-postgres). Bound to live implementations here;
        // tests swap in fakes so the guard/command logic is provable without a
        // real PostgreSQL server and without ever migrating development data.
        $this->app->bind(DatabaseNameResolver::class, LiveDatabaseNameResolver::class);
        $this->app->bind(TestSchemaMigrator::class, ArtisanTestSchemaMigrator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
