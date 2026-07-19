<?php
/**
 * Application Routes
 *
 * Defines all HTTP routes for the Gandalf Decision Engine API. Routes are
 * organised into groups by authentication middleware: public endpoints
 * (OAuth basic client only), authenticated user endpoints (full OAuth token),
 * admin endpoints (OAuth + application scope), and consumer endpoints (API
 * key or OAuth client). The REST and Changelog packages register their own
 * sub-routes via their routers.
 *
 * @package App\Http
 */

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/

// Health-check endpoint — returns a plain "ok" with no authentication required
$app->get('/', function () {
    return response('ok');
});

// Register full REST CRUD routes for the tables resource under api/v1/admin/tables
// Protected by OAuth token, application context, and ACL middleware
/** @var Nebo15\REST\Router $api */
$api = $app->make('Nebo15\REST\Router');
$api->api('tables', 'TablesController', ['oauth', 'applicationable', 'applicationable.acl']);

// Register full REST CRUD routes for the flows (Decision Requirement Graph)
// resource under api/v1/admin/flows, same protection as tables
$api->api('flows', 'FlowsController', ['oauth', 'applicationable', 'applicationable.acl']);


// Register changelog routes (list, diff, rollback) for all admin-scoped resources
/** @var Nebo15\Changelog\Router $changelog */
$changelog = $app->make('Nebo15\Changelog\Router');
$changelog->api(
    'api/v1/admin',
    ['oauth', 'applicationable', 'applicationable.acl'],
    'App\Http\Controllers\ChangelogController'
);

// Register OAuth2 token endpoints (grant, refresh, revoke) provided by LumenOauth2
$app->make('oauth.routes')->makeRestRoutes();
// Register application management routes (create, list consumers, manage users)
$app->make('Applicationable.routes')->makeRoutes();

// Public user-management routes — require only a valid OAuth client credential
// (no user token needed) so that unauthenticated users can register and recover passwords
$app->group(
    [
        'prefix' => 'api/v1',
        'namespace' => 'App\Http\Controllers',
        'middleware' => ['oauth.basic.client'],
    ],
    function ($app) {
        /** @var Laravel\Lumen\Application $app */
        // Check whether a given username is already taken
        $app->get('/users/username', ['uses' => 'UsersController@validateUsername']);
        // Create a new user account
        $app->post('/users', ['uses' => 'UsersController@create']);
        // Verify an email address using the token sent by email
        $app->post('/users/verify/email', ['uses' => 'UsersController@verifyEmail']);
        // Re-send the email verification token
        $app->post('/users/verify/email/resend', ['uses' => 'UsersController@resendVerifyEmailToken']);
        // Initiate a password reset (sends reset email)
        $app->post('/users/password/reset', ['uses' => 'UsersController@createResetPasswordToken']);
        // Complete the password reset using the emailed token
        $app->put('/users/password/reset', ['uses' => 'UsersController@changePassword']);
    }
);

// Authenticated user routes — require a valid OAuth user access token
$app->group(
    [
        'prefix' => 'api/v1',
        'namespace' => 'App\Http\Controllers',
        'middleware' => ['oauth'],
    ],
    function ($app) {
        /** @var Laravel\Lumen\Application $app */
        // Update the currently authenticated user's profile
        $app->put('/users/current', ['uses' => 'UsersController@updateUser']);
        // Retrieve the currently authenticated user's profile and Intercom secure code
        $app->get('/users/current', ['uses' => 'UsersController@getUserInfo']);
        // Get list of users (filtered by name/email — used for invitation autocomplete)
        $app->get('/users', ['uses' => 'UsersController@readListWithFilters']);

    }
);

// Project management routes — require OAuth token and application context
// DELETE removes the application and all associated tables
$app->delete('api/v1/projects', [
    'uses' => 'App\Http\Controllers\ProjectsController@deleteProject',
    'middleware' => ['oauth', 'applicationable', 'applicationable.acl'],
]);
// GET triggers a mongoexport of the application's data and returns a download URL
$app->get('api/v1/projects/export', [
    'uses' => 'App\Http\Controllers\ProjectsController@export',
    'middleware' => ['oauth', 'applicationable', 'applicationable.acl'],
]);
// GET lists project collaborators enriched with a confirmation status
// (active / pending / invited), merging in unaccepted invitations
$app->get('api/v1/projects/collaborators', [
    'uses' => 'App\Http\Controllers\ProjectsController@collaborators',
    'middleware' => ['oauth', 'applicationable', 'applicationable.acl'],
]);
// POST confirms a collaborator on behalf of the invitee (project admin only):
// activates a pending user, or creates an account from a pending invitation
$app->post('api/v1/projects/collaborators/confirm', [
    'uses' => 'App\Http\Controllers\ProjectsController@confirmCollaborator',
    'middleware' => ['oauth', 'applicationable', 'applicationable.acl'],
]);
// DELETE cancels a pending invitation by email (project admin only)
$app->delete('api/v1/projects/collaborators/invitation', [
    'uses' => 'App\Http\Controllers\ProjectsController@cancelInvitation',
    'middleware' => ['oauth', 'applicationable', 'applicationable.acl'],
]);
// POST resends the invitation email for a pending invitation (project admin only)
$app->post('api/v1/projects/collaborators/invitation/resend', [
    'uses' => 'App\Http\Controllers\ProjectsController@resendInvitation',
    'middleware' => ['oauth', 'applicationable', 'applicationable.acl'],
]);
// DELETE permanently deletes a user account (project admin only); refused when
// the account still belongs to other projects
$app->delete('api/v1/projects/collaborators/account', [
    'uses' => 'App\Http\Controllers\ProjectsController@deleteAccount',
    'middleware' => ['oauth', 'applicationable', 'applicationable.acl'],
]);

// Admin-only routes — full OAuth token, application context, and ACL middleware required
// These endpoints expose internal decision records and table analytics to project admins
$app->group(
    [
        'prefix' => 'api/v1/admin',
        'namespace' => 'App\Http\Controllers',
        'middleware' => ['oauth', 'applicationable', 'applicationable.acl'],
    ],
    function ($app) {
        /** @var Laravel\Lumen\Application $app */
        // List the current application's categories (organisational labels for
        // tables and flows). PUT replaces the whole list (project admin only).
        $app->get('/categories', ['uses' => 'CategoriesController@index']);
        $app->put('/categories', ['uses' => 'CategoriesController@update']);
        // List all decisions for this application (paginated, filterable by table/variant)
        $app->get('/decisions', ['uses' => 'DecisionsController@readList']);
        // Retrieve a single decision record by its MongoDB ObjectID
        $app->get('/decisions/{id:[0-9a-z]{24}}', ['uses' => 'DecisionsController@read']);
        // Attach arbitrary key-value metadata to a decision (e.g. customer reference)
        $app->put('/decisions/{id:[0-9a-z]{24}}/meta', ['uses' => 'DecisionsController@updateMeta']);
        // Paginated run history for a decision-requirement-graph flow
        $app->get('/flows/{id:[0-9a-z]{24}}/runs', ['uses' => 'FlowsController@runs']);
        // Return rule/condition hit-rate analytics for a specific table variant
        $app->get('/tables/{id:[0-9a-z]{24}}/{variant_id:[0-9a-z]{24}}/analytics', ['uses' => 'TablesController@analytics']);
        // Copy a decision table into a different project
        $app->post('/tables/{id:[0-9a-z]{24}}/copyto/{project_id:[0-9a-z]{24}}', ['uses' => 'TablesController@copyTo']);
        // Export a decision table as CSV, Excel or JSON (?format=csv|excel|json)
        $app->get('/tables/{id:[0-9a-z]{24}}/export', ['uses' => 'TablesController@export']);
        // Import a decision table from an uploaded CSV, Excel or JSON file (multipart/form-data, field: file)
        // NOTE: must be declared before any wildcard {id} POST routes to avoid routing conflicts
        $app->post('/tables/import', ['uses' => 'TablesController@import']);
    }
);

// Consumer-facing routes — accessible to both OAuth users and API consumers (client credentials)
// These are the primary endpoints used by end applications to run decisions
$app->group(
    [
        'prefix' => 'api/v1',
        'namespace' => 'App\Http\Controllers',
        'middleware' => ['applicationable', 'applicationable.user_or_client', 'applicationable.acl'],
    ],
    function ($app) {
        /** @var Laravel\Lumen\Application $app */
        // Retrieve a decision result by ID (consumer-safe subset of fields)
        $app->get('/decisions/{id:[0-9a-z]{24}}', ['uses' => 'ConsumerController@decision']);
        // Submit field values to a decision table and receive a decision result
        $app->post('/tables/{id:[0-9a-z]{24}}/decisions', ['uses' => 'ConsumerController@tableCheck']);
        // Run a Decision Requirement Graph (flow) against its inputs
        $app->post('/flows/{id:[0-9a-z]{24}}/decisions', ['uses' => 'ConsumerController@flowCheck']);
        // Invite another user to join the current application/project
        $app->post('/invite', ['uses' => 'UsersController@invite']);
    }
);
