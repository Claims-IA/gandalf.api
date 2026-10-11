<?php
/**
 * SuperadminController
 *
 * Routes reserved to superadmins (ADMIN.md), behind the 'superadmin' route
 * middleware: the admin log, most recent first, filtered by application (its
 * creation included) or by superadmin.
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Models\AdminLogEntry;
use Illuminate\Http\Request;
use Nebo15\REST\Response;

class SuperadminController extends Controller
{
    /**
     * GET /api/v1/superadmin/log
     *
     * @param  Request  $request
     * @param  Response $response
     * @return \Illuminate\Http\JsonResponse
     */
    public function log(Request $request, Response $response)
    {
        $this->validate($request, [
            'application_id' => 'sometimes|string',
            'user_id' => 'sometimes|string',
            'size' => 'sometimes|integer|min:1|max:200',
            'page' => 'sometimes|integer|min:1',
        ]);

        $query = AdminLogEntry::query()->orderBy(AdminLogEntry::CREATED_AT, 'desc');
        if ($request->has('application_id')) {
            // Its creation included: that request has no X-Application, the
            // new id is in its details.
            $applicationId = (string) $request->input('application_id');
            $query->where(function ($query) use ($applicationId) {
                $query->where('application_id', $applicationId)->orWhere('details.application_id', $applicationId);
            });
        }
        if ($request->has('user_id')) {
            $query->where('user_id', (string) $request->input('user_id'));
        }

        return $response->jsonPaginator($query->paginate((int) $request->input('size', 50)));
    }
}
