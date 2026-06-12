<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->fixDatabaseConfig();
        $this->configureDefaults();
    }

    /**
     * Re-load .env with a mutable loader so our values overwrite anything a
     * co-hosted WAMP app may have injected into the shared PHP process via
     * putenv() on a previous request (e.g. DB_DATABASE from travel-aivlabs).
     */
    private function fixDatabaseConfig(): void
    {
        Dotenv::createMutable(base_path())->safeLoad();

        config(['database.connections.mysql.database' => env('DB_DATABASE', 'gratitude_main_local')]);

        DB::purge('mysql');
    }

    private function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(app()->isProduction());

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
