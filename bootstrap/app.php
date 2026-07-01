<?php
/**
 * Application Bootstrap
 *
 * Entry point for the Gandalf API Lumen application. Defines the application name
 * and version constants, suppresses E_DEPRECATED errors for PHP 8.1+ compatibility
 * with illuminate/5.2 packages, loads the Composer autoloader and .env file,
 * registers all service providers (MongoDB, REST, Changelog, OAuth2, Applicationable,
 * Bugsnag, Mixpanel, Intercom, and all custom app providers), configures global
 * middleware (JsonMiddleware, NewRelicMiddleware, and conditionally analytics
 * terminable middleware), binds the exception handler and console kernel, and
 * loads the application routes. Returns the configured application instance.
 *
 * @package App
 */

const APPLICATION_NAME = "gandalf.api";
const APPLICATION_VERSION = "1.1.4";

// PHP 8.1+ generates E_DEPRECATED for ArrayAccess/interface implementations in illuminate/5.2
// packages. Must be set before autoloading to avoid conversion to exceptions during class loading.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once __DIR__.'/../vendor/autoload.php';

Dotenv\Dotenv::createUnsafeMutable(__DIR__ . '/../')->safeLoad();

/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
|
| Here we will load the environment and create the application instance
| that serves as the central piece of this framework. We'll use this
| application as an "IoC" container and router for this framework.
|
*/

// Instantiate the custom Application class (extends Lumen to fix deprecated-error handling and logging)
$app = new App\Application(
    realpath(__DIR__ . '/../')
);
// Remove the default validator binding so ValidationServiceProvider can register the custom one
unset($app->availableBindings['validator']);

$app->register('Jenssegers\Mongodb\MongodbServiceProvider');

$app->withFacades();

$app->withEloquent();

$app->configure('database');
$app->configure('tokens');
$app->configure('applicationable');
$app->configure('services');
$app->configure('errors');
/*
|--------------------------------------------------------------------------
| Register Container Bindings
|--------------------------------------------------------------------------
|
| Now we will register a few bindings in the service container. We will
| register the exception handler and the console kernel. You may add
| your own bindings here if you like or you can make another file.
|
*/

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\Exceptions\Handler::class
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);

/*
|--------------------------------------------------------------------------
| Register Middleware
|--------------------------------------------------------------------------
|
| Next, we will register the middleware with the application. These can
| be global middleware that run before and after each request into a
| route or middleware that'll be assigned to some specific routes.
|
*/

$middlewares = [
    App\Http\Middleware\JsonMiddleware::class,
    App\Http\Middleware\NewRelicMiddleware::class,
];
if (env('INTERCOM_ENABLED', false)) {
    $middlewares[] = \Nebo15\LumenIntercom\Middleware\TerminableMiddleware::class;
}
if (env('MIXPANEL_ENABLED', false)) {
    $middlewares[] = \Nebo15\LumenMixpanel\Middleware\TerminableMiddleware::class;
}
$app->middleware($middlewares);
/*
|--------------------------------------------------------------------------
| Register Service Providers
|--------------------------------------------------------------------------
|
| Here we will register all of the application's service providers which
| are used to bind services into the container. Service providers are
| totally optional, so you are not required to uncomment this line.
|
*/

$app->register(App\Providers\ObserverServiceProvider::class);
$app->register(Nebo15\REST\ServiceProvider::class);
$app->register(Nebo15\Changelog\ServiceProvider::class);
$app->register(App\Providers\ValidationServiceProvider::class);
if (env('APP_ENV') !== 'prod' && class_exists(Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider::class)) {
    $app->register(Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider::class);
}
$app->register(App\Providers\BugsnagServiceProvider::class);
if (env('MIXPANEL_ENABLED', false)) {
    $app->register(\Nebo15\LumenMixpanel\MixpanelServiceProvider::class);
}
if (env('INTERCOM_ENABLED', false)) {
    $app->register(\Nebo15\LumenIntercom\IntercomServiceProvider::class);
}

$app->register(Nebo15\LumenOauth2\Providers\ServiceProvider::class);
$app->register(Nebo15\LumenApplicationable\ServiceProvider::class);
$app->register(App\Providers\AppServiceProvider::class);
$app->register(App\Providers\EventServiceProvider::class);

/*
|--------------------------------------------------------------------------
| Load The Application Routes
|--------------------------------------------------------------------------
|
| Next we will include the routes file so that they can all be added to
| the application. This will provide all of the URLs the application
| can respond to, as well as the controllers that may handle them.
|
*/

$app->group([], function ($app) {
    require __DIR__ . '/../app/Http/routes.php';
});

return $app;
