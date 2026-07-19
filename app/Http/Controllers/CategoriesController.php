<?php
/**
 * CategoriesController
 *
 * Manages the list of categories owned by the current application. Categories
 * are a purely organisational attribute — they group and classify decision
 * tables and flows and have no effect on decision logic. Each application keeps
 * its own list; a category is a { id, name, color } triple stored in the
 * application document's `settings.categories` bag (the same settings mechanism
 * used for other per-application configuration).
 *
 * Endpoints:
 *   GET  /api/v1/admin/categories  — return the current list.
 *   PUT  /api/v1/admin/categories  — replace the whole list.
 *
 * The PUT is a full replace (not a partial patch): the client sends the desired
 * final list. Server-side, each entry's `id` is preserved when supplied and
 * still valid, or generated when new, so a category can be renamed/recoloured
 * without breaking the tables and flows that reference it by id. After saving,
 * any table or flow whose category_id no longer exists is reset to null so no
 * orphaned coloured pill can ever be shown.
 *
 * Writing the list mutates the project, so the route is guarded by the
 * `project_update` ACL scope (project admin), consistent with other project
 * settings.
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Models\Flow;
use App\Models\Table;
use Nebo15\REST\Response;
use Illuminate\Http\Request;
use Nebo15\LumenApplicationable\Models\Application;

class CategoriesController extends Controller
{
    protected $request;

    protected $response;

    protected $validationRules = [
        'update' => [
            'categories'         => 'present|array',
            'categories.*.id'    => 'sometimes|string|min:1',
            'categories.*.name'  => 'required|string|between:1,64',
            'categories.*.color' => 'required|hexColor',
        ],
    ];

    public function __construct(Request $request, Response $response)
    {
        $this->request = $request;
        $this->response = $response;
    }

    /**
     * Return the current application's category list.
     *
     * @param  Application $application  Resolved from the X-Application header.
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Application $application)
    {
        return $this->response->json(
            ['categories' => $this->readCategories($application)]
        );
    }

    /**
     * Replace the current application's category list.
     *
     * Normalises the submitted list (preserving/assigning ids, rejecting
     * duplicate names), persists it into settings.categories, then cleans up any
     * table or flow that referenced a category which no longer exists.
     *
     * @param  Application $application  Resolved from the X-Application header.
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Application $application)
    {
        $this->validate($this->request, $this->validationRules['update']);

        $existingIds = array_map(function ($category) {
            return $category['id'];
        }, $this->readCategories($application));

        $normalised = [];
        $seenIds = [];
        $seenNames = [];

        foreach ($this->request->input('categories', []) as $incoming) {
            $name = trim($incoming['name']);

            // Names must be unique within an application (case-insensitive): the
            // list is a human-facing legend, and two identical pills would be
            // indistinguishable to the user.
            $nameKey = mb_strtolower($name);
            if (isset($seenNames[$nameKey])) {
                return $this->response->json(
                    ['message' => "Duplicate category name '{$name}'."],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
            $seenNames[$nameKey] = true;

            // Preserve a supplied id only when it belonged to this application and
            // has not already been used in this payload; otherwise mint a new one.
            // This keeps table/flow references intact across a rename or recolour,
            // while preventing a client from smuggling in a foreign or duplicate id.
            $id = isset($incoming['id']) ? $incoming['id'] : null;
            if (!$id || !in_array($id, $existingIds, true) || isset($seenIds[$id])) {
                $id = $this->generateCategoryId();
            }
            $seenIds[$id] = true;

            $normalised[] = [
                'id'    => $id,
                'name'  => $name,
                'color' => strtoupper($incoming['color']),
            ];
        }

        $settings = (array) $application->settings;
        $settings['categories'] = $normalised;
        $application->fill(['settings' => $settings])->save();

        $this->pruneOrphanReferences($application, array_column($normalised, 'id'));

        return $this->response->json(['categories' => $normalised]);
    }

    /**
     * Read the stored category list, always as a list of {id,name,color} arrays.
     *
     * @param  Application $application
     * @return array
     */
    private function readCategories(Application $application)
    {
        $categories = $application->getSettingsElem('categories', []);

        // settings may come back as an object cast; normalise to a plain list.
        return array_values(array_map(function ($category) {
            $category = (array) $category;
            return [
                'id'    => isset($category['id']) ? (string) $category['id'] : '',
                'name'  => isset($category['name']) ? (string) $category['name'] : '',
                'color' => isset($category['color']) ? (string) $category['color'] : '',
            ];
        }, (array) $categories));
    }

    /**
     * Reset category_id to null on any table or flow of this application whose
     * referenced category is no longer in $validIds.
     *
     * Runs after every list save so a deleted category can never leave a table
     * or flow pointing at a category that does not exist. Uses a single bulk
     * update per collection (category_id not in the valid set, and not already
     * null) scoped to the current application.
     *
     * By design this is a bulk update, which does NOT fire the Table/Flow
     * observers: detaching an orphaned category is a system side effect, so it
     * intentionally creates no changelog entry (and needs no authenticated user).
     * An empty $validIds (the last category was deleted) nulls every reference,
     * which is the intended outcome.
     *
     * @param  Application $application
     * @param  string[]    $validIds
     * @return void
     */
    private function pruneOrphanReferences(Application $application, array $validIds)
    {
        $appId = $application->_id;

        foreach ([Table::class, Flow::class] as $modelClass) {
            $modelClass::where('applications', $appId)
                ->whereNotNull('category_id')
                ->whereNotIn('category_id', $validIds)
                ->update(['category_id' => null]);
        }
    }

    /**
     * Generate a short, opaque, collision-resistant category id.
     *
     * Prefixed so it is recognisable in stored documents and never mistaken for a
     * MongoDB ObjectID. Uniqueness comes from a CSPRNG; the list is small so a
     * short id is plenty.
     *
     * @return string
     */
    private function generateCategoryId()
    {
        return 'cat_' . bin2hex(random_bytes(8));
    }
}
