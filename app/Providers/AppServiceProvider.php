<?php
/**
 * AppServiceProvider
 *
 * Registers core application service bindings in the IoC container. Notably it
 * binds DbTransfer as a singleton and, when Intercom or Mixpanel integrations are
 * disabled via environment variables, replaces their real implementations with
 * inline no-op anonymous classes. This allows the EventListener to be constructed
 * and injected normally without runtime errors in environments that do not have
 * those integrations configured.
 *
 * @package App\Providers
 */

namespace App\Providers;

use App\Services\DbTransfer;
use App\Models\Decision;
use App\Models\User;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    /**
     * Register application service bindings.
     *
     * Binds DbTransfer as a shared singleton (expensive to instantiate) and
     * conditionally registers no-op stubs for Intercom and Mixpanel so the
     * EventListener can always be resolved by the IoC container even when those
     * third-party integrations are turned off.
     *
     * @return void
     */
    public function register()
    {
        // Bind DbTransfer as a singleton to avoid re-creating it on every injection
        $this->app->singleton('\App\Services\DbTransfer', function () {
            return new DbTransfer;
        });

        // When Intercom/Mixpanel are disabled, bind no-op stubs so EventListener can be resolved.
        // Using anonymous classes that extend the real classes means type checks still pass.
        if (!env('INTERCOM_ENABLED', false)) {
            $this->app->singleton(\App\Services\Intercom::class, function () {
                return new class extends \App\Services\Intercom {
                    public function __construct() {}
                    public function userCreateOrUpdate(User $user) {}
                    public function decisionMake(Decision $decision, array $user_ids) {}
                    public function generateSecureCode($user_id) { return ''; }
                };
            });
        }

        if (!env('MIXPANEL_ENABLED', false)) {
            $this->app->singleton(\App\Services\Mixpanel::class, function () {
                return new class extends \App\Services\Mixpanel {
                    public function __construct() {}
                    public function userCreate(User $user) {}
                    public function userUpdate(User $user) {}
                    public function decisionMake(Decision $decision, array $user_ids) {}
                };
            });
        }
    }
}
