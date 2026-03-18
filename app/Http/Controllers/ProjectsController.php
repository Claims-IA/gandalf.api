<?php
/**
 * ProjectsController
 *
 * Handles project (application) level operations that go beyond user management.
 * Provides two endpoints: one to permanently delete an application and all of its
 * associated decision tables, and one to export the application's data as a
 * compressed archive (tables, decisions, changelogs) using mongoexport.
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Models\Table;
use App\Services\DbTransfer;
use Nebo15\REST\AbstractController;
use Nebo15\LumenApplicationable\Models\Application;

class ProjectsController extends AbstractController
{
    protected $repositoryClassName = '';

    protected $validationRules = [];

    /**
     * Permanently delete the application and all of its decision tables.
     *
     * Uses a MongoDB $in query to delete every Table document associated with
     * the application before removing the application document itself. This is
     * a hard delete with no recovery path.
     *
     * @param  Application $application  The current application resolved from the X-Application header.
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteProject(Application $application)
    {
        // Remove all decision tables that belong to this application first
        Table::where(['applications' => ['$in' => [$application->_id]]])->delete();
        // Then delete the application document itself
        $application->delete();

        return $this->response->json();
    }

    /**
     * Export all application data to a downloadable archive and return its URL.
     *
     * The DbTransfer service runs mongoexport for the tables, decisions, and
     * changelogs collections filtered by application ID, then packages the JSON
     * files into a .tar.gz stored in public/dump/. Returns the public download URL.
     *
     * @param  DbTransfer  $dbTransfer
     * @param  Application $application  The current application resolved from the X-Application header.
     * @return \Illuminate\Http\JsonResponse
     */
    public function export(DbTransfer $dbTransfer, Application $application)
    {
        return $this->response->json(['url' => $dbTransfer->export($application->_id)]);
    }
}
