<?php
/**
 * SuperadminController
 *
 * Routes reserved to superadmins (ADMIN.md), behind the 'superadmin' route
 * middleware: the admin log, most recent first, filtered by application or
 * by superadmin.
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
        foreach (['application_id', 'user_id'] as $filter) {
            if ($request->has($filter)) {
                $query->where($filter, (string) $request->input($filter));
            }
        }

        return $response->jsonPaginator($query->paginate((int) $request->input('size', 50)));
    }
}
