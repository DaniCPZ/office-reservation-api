<?php

namespace App\Providers;

use App\Models\User;
use App\Models\Office;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Disable masss assignment protection on all models
        Model::unguard(); 

        // Change "resource_type": "App\Models\Office" for
        // "resource_type": "office"
        
        Relation::enforceMorphMap([
            'office' => Office::class,
            'user'   => User::class,
        ]);
    }
}
