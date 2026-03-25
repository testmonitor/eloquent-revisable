<?php

namespace TestMonitor\Revisable;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use TestMonitor\Revisable\Contracts\Revision as RevisionContract;
use TestMonitor\Revisable\Exceptions\InvalidConfiguration;
use TestMonitor\Revisable\Models\Revision;

class RevisableServiceProvider extends BaseServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        $this->publishConfigs();
        $this->publishMigrations();
    }

    /**
     * Register the application services.
     */
    public function register(): void
    {
        $this->registerBindings();
    }

    protected function publishConfigs(): void
    {
        $this->publishes([
            __DIR__ . '/../config/revisionable.php' => config_path('revisable.php'),
        ], 'config');
    }

    protected function publishMigrations(): void
    {
        if (empty(File::glob(database_path('migrations/*_create_revisions_table.php')))) {
            $timestamp = date('Y_m_d_His', time());
            $migration = database_path("migrations/{$timestamp}_create_revisions_table.php");

            $this->publishes([
                __DIR__ . '/../database/migrations/create_revisions_table.php.stub' => $migration,
            ], 'migrations');
        }
    }

    protected function registerBindings(): void
    {
        $this->app->bind(RevisionContract::class, config('revisable.revision_model', Revision::class));

        $this->app->singleton(UserResolver::class, fn ($app) => new UserResolver(
            $app['auth'],
            $app['config']['revisable.default_auth_driver'],
        ));
    }

    public static function determineRevisionModel(): string
    {
        $revisionModel = config('revisable.revision_model') ?? Revision::class;

        if (! is_a($revisionModel, Revision::class, true)
            || ! is_a($revisionModel, Model::class, true)) {
            throw InvalidConfiguration::invalidRevisionModel($revisionModel);
        }

        return $revisionModel;
    }

    public static function determineUserModel(): string
    {
        $userModel = config('revisable.user_model');

        if (! is_a($userModel, Model::class, true)) {
            throw InvalidConfiguration::invalidUserModel($userModel);
        }

        return $userModel;
    }
}
