<?php

namespace Modules\PayfastGateway\Providers;

use Illuminate\Support\ServiceProvider;

class PayfastGatewayServiceProvider extends ServiceProvider
{
    /**
     * @var string $moduleName
     */
    protected $moduleName = 'PayfastGateway';

    /**
     * @var string $moduleNameLower
     */
    protected $moduleNameLower = 'payfastgateway';

    /**
     * Boot the application events.
     *
     * @return void
     */
    public function boot()
    {

    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {

    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }

}
