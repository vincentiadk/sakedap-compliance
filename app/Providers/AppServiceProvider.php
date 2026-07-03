<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Database\OdbcConnectionFactory;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton('db.factory', function ($app) {
            return new OdbcConnectionFactory($app);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Database\Connection::resolverFor('odbc', function ($connection, $database, $prefix, $config) {
            return new \App\Database\Connections\OdbcConnection($connection, $database, $prefix, $config);
        });
    }
}
