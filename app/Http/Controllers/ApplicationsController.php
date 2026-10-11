<?php
/**
 * ApplicationsController
 *
 * Replaces three routes of the nebo15/lumen.applicationable package (ADMIN.md),
 * registered after the package routes in app/Http/routes.php so they win:
 *
 *   - POST /projects: only a superadmin creates an application (route
 *     middleware 'superadmin'), and does not become a member of it;
 *   - GET /projects: a superadmin lists every application;
 *   - PUT /projects/consumers: the scopes given to an API key must be held by
 *     the user who gives them, as on POST /projects/consumers.
 *
 * The other application routes stay the package's.
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Services\AdminLog;
use Nebo15\LumenApplicationable\Controllers\ApplicationController;
use Nebo15\LumenApplicationable\Models\Application;
use Nebo15\LumenApplicationable\Response;

class ApplicationsController extends ApplicationController
{
    /**
     * Create an application, superadmin only. The superadmin acts in every
     * application without being a member; they name its admins by inviting
     * them with the admin role (POST /invite with X-Application).
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function create()
    {
        $this->validateRoute();
        $application = $this->getRepository()->createOrUpdate($this->request->all());
        app(AdminLog::class)->note([
            'application_id' => (string) $application->_id,
            'title' => $application->title,
        ]);

        return $this->response->json($application->toArray(), Response::HTTP_CREATED);
    }

    /**
     * The caller's applications; every application for a superadmin.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function projectsList()
    {
        if (!$this->request->user()->isSuperadmin()) {
            return parent::projectsList();
        }

        return $this->response->json(Application::orderBy('title')->get()->toArray());
    }

    /**
     * Update an API key: its scopes must be held by the caller, as on creation.
     *
     * @param  Application $application
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function updateConsumer(Application $application)
    {
        $currentUser = $this->request->user()->getApplicationUser();
        $this->validationRules['updateConsumer']['scope'] = 'required|array|in:' . join(',', $currentUser->scope);

        return parent::updateConsumer($application);
    }
}
