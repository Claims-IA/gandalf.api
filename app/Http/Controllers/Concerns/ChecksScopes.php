<?php
/**
 * ChecksScopes
 *
 * Scope checks inside a controller, for the rules the ACL cannot express
 * (config/applicationable.php matches a route, not the version it targets):
 * a draft, a published or an inactive version may need another scope than the
 * route's. The caller is resolved as ApplicationAclMiddleware does: the
 * member entry of the authenticated user (an admin entry for a superadmin),
 * or the API key of the application.
 *
 * Also validates input outside the route's own rules.
 *
 * @package App\Http\Controllers\Concerns
 */

namespace App\Http\Controllers\Concerns;

use App\Http\Middleware\ApplicationAclMiddleware;
use Illuminate\Contracts\Validation\ValidationException;
use Nebo15\LumenApplicationable\Exceptions\AccessDeniedException;

trait ChecksScopes
{
    /**
     * Whether the caller holds a scope.
     *
     * @param  string $scope
     * @return bool
     */
    protected function callerCan($scope)
    {
        $caller = ApplicationAclMiddleware::callerFrom(app('auth'));

        return $caller !== null && (bool) $caller->{'can' . ucfirst($scope)}();
    }

    /**
     * @param  string $scope
     * @return void
     * @throws AccessDeniedException  403 access_denied, the scope in meta.scopes.
     */
    protected function requireScope($scope)
    {
        if (!$this->callerCan($scope)) {
            throw new AccessDeniedException('', 0, null, [$scope]);
        }
    }

    /**
     * @param  array $data
     * @param  array $rules
     * @return void
     * @throws ValidationException
     */
    protected function validateInput(array $data, array $rules)
    {
        $validator = \Validator::make($data, $rules);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }
}
