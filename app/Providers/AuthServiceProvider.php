<?php
/**
 * AuthServiceProvider
 *
 * Configures Lumen's authentication system for token-based API access. The boot
 * method registers a 'token' driver that looks up users by an api_token field in
 * MongoDB. This driver is used as a fallback; primary authentication in production
 * is handled by the LumenOauth2 package's OAuth2 bearer token middleware.
 *
 * @package App\Providers
 */

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
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
     * Boot the authentication services for the application.
     *
     * @return void
     */
    public function boot()
    {
        // Here you may define how you wish users to be authenticated for your Lumen
        // application. The callback which receives the incoming request instance
        // should return either a User instance or null. You're free to obtain
        // the User instance via an API token or any other method necessary.

        $this->app['auth']->viaRequest('token', function ($request) {
            if ($request->input('api_token')) {
                return User::where('api_token', $request->input('api_token'))->first();
            }
        });
    }
}
