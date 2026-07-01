<?php
/**
 * TablesController
 *
 * Manages decision table resources via RESTful CRUD endpoints registered by the
 * Nebo15/REST package. Extends AbstractController to gain standard create, read,
 * update, delete, and list actions. Adds a readList override for filter support
 * and an analytics endpoint that aggregates decision hit rates per rule/condition
 * for a given table variant.
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use Nebo15\REST\Response;
use Illuminate\Http\Request;
use App\Services\ConditionsTypes;
use App\Services\TableExportService;
use App\Services\TableImportService;
use Nebo15\REST\AbstractController;
use Nebo15\REST\Interfaces\ListableInterface;

/**
 * Class TablesController
 * @package App\Http\Controllers
 * @method \App\Repositories\TablesRepository getRepository()
 */
class TablesController extends AbstractController
{
    protected $repositoryClassName = 'App\Repositories\TablesRepository';

    private TableExportService $exportService;
    private TableImportService $importService;

    protected $validationRules = [
        'create' => [],
        'update' => [],
        'readList' => [
            'title' => 'sometimes|min:1',
            'description' => 'sometimes|min:1',
            'matching_type' => 'sometimes|in:first,scoring_sum,scoring_max,scoring_min,scoring_count',
        ]
    ];

    /**
     * Build validation rules and call the parent constructor.
     *
     * Condition operator values (e.g. $eq, $gt) are generated dynamically from
     * the ConditionsTypes service so the allowed list stays in sync with the
     * engine without duplicating definitions here.
     *
     * @param Request         $request
     * @param Response        $response
     * @param ConditionsTypes $conditionsTypes
     */
    public function __construct(
        Request $request,
        Response $response,
        ConditionsTypes $conditionsTypes,
        TableExportService $exportService,
        TableImportService $importService
    ) {
        $this->exportService = $exportService;
        $this->importService = $importService;
        // Build the comma-separated list of valid condition operators for use in "in:" rules
        $condRules = $conditionsTypes->getConditionsRules();
        $rules = [
            'title' => 'sometimes|string',
            'description' => 'sometimes|string',
            'matching_type' => 'required|in:first,scoring_sum,scoring_max,scoring_min,scoring_count',
            'decision_type' => 'required|in:alpha_num,numeric,string,json|decision_type',
            'fields' => 'required|array',
            'fields.*._id' => 'sometimes|mongoId',
            'fields.*.title' => 'required|string',
            'fields.*.key' => 'required|string|not_in:variant_id',
            'fields.*.type' => 'required|in:numeric,boolean,string',
            'fields.*.source' => 'required|in:request',
            'fields.*.preset' => 'present|array',
            'fields.*.preset._id' => 'mongoId',
            'fields.*.preset.value' => 'required_with:fields.*.preset',
            'fields.*.preset.condition' => 'required_with:fields.*.preset|in:' . $condRules,
            'variants_probability' => 'sometimes|in:first,random,percent|probabilitySum',
            'variants' => 'required|array',
            'variants.*._id' => 'mongoId',
            'variants.*.is_default' => 'sometimes|boolean',
            'variants.*.default_decision' => 'required|ruleThanType',
            'variants.*.title' => 'sometimes|string|between:2,128',
            'variants.*.description' => 'sometimes|string|between:2,128',
            'variants.*.default_title' => 'sometimes|string|between:2,128',
            'variants.*.default_description' => 'sometimes|string|between:2,512',
            'variants.*.probability,' => 'sometimes|integer|between:1,100',
            'variants.*.rules' => 'required|array',
            'variants.*.rules.*._id' => 'mongoId',
            'variants.*.rules.*.than' => 'required|ruleThanType',
            'variants.*.rules.*.description' => 'string|between:2,128',
            'variants.*.rules.*.conditions' => 'required|array|conditionsCount',
            'variants.*.rules.*.conditions.*._id' => 'mongoId',
            'variants.*.rules.*.conditions.*.field_key' => 'required|string',
            'variants.*.rules.*.conditions.*.condition' => 'required|in:' . $condRules,
            'variants.*.rules.*.conditions.*.value' => 'required|conditionType',
        ];

        $this->validationRules['create'] = $rules;
        $this->validationRules['update'] = $rules;

        parent::__construct($request, $response);
    }

    /**
     * Return a paginated list of decision tables for the current application.
     *
     * Supports optional query filters: title and description are matched with a
     * case-insensitive regex, matching_type can be 'first', 'scoring_sum', 'scoring_max', 'scoring_min', or 'scoring_count'. Only
     * tables belonging to the authenticated application are returned.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function readList()
    {
        $this->validateRoute();

        return $this->response->jsonPaginator(
            $this->getRepository()->readListWithFilters($this->request->all()),
            [],
            function (ListableInterface $model) {
                return $model->toListArray();
            }
        );
    }

    /**
     * Return rule and condition analytics for a specific table variant.
     *
     * Queries all historical Decision documents for the table/variant combination
     * (since the table's last update) and calculates the probability (hit rate)
     * for each rule and each condition. This lets administrators see which rules
     * fire most often and tune the table accordingly.
     *
     * @param  string $id         MongoDB ObjectID of the decision table.
     * @param  string $variant_id MongoDB ObjectID of the variant to analyse.
     * @return \Illuminate\Http\JsonResponse
     */
    /**
     * Copy a decision table into a different project.
     *
     * Duplicates the table identified by $id (from the current application) and
     * saves the copy under the application identified by $project_id. All fields,
     * variants, rules, and conditions are preserved; only the owning application
     * is changed.
     *
     * @param  string $id         MongoDB ObjectID of the source table.
     * @param  string $project_id MongoDB ObjectID of the target project/application.
     * @return \Illuminate\Http\JsonResponse
     */
    public function copyTo($id, $project_id)
    {
        return $this->response->json(
            $this->getRepository()->copyTo($id, $project_id)->toArray()
        );
    }

    public function analytics($id, $variant_id)
    {
        $this->validateRoute();

        return $this->response->json(
            $this->getRepository()->analyzeTableDecisions($id, $variant_id)->toArray()
        );
    }

    /**
     * Export a decision table as a downloadable file.
     *
     * Accepts a ?format=csv|excel|json query parameter (defaults to json).
     * For CSV and Excel only the first variant is exported. JSON exports all variants.
     *
     * @param  string $id  MongoDB ObjectID of the table
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function export($id)
    {
        $format   = strtolower($this->request->query('format', 'json'));
        $table    = $this->getRepository()->read($id);
        $filename = $this->sanitizeFilename($table->title);

        switch ($format) {
            case 'csv':
                $content = $this->exportService->toCsv($table);
                return response($content, 200, [
                    'Content-Type'        => 'text/csv; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename="' . $filename . '.csv"',
                ]);

            case 'excel':
                $tmpPath = $this->exportService->toExcel($table);
                return response()->download($tmpPath, $filename . '.xlsx', [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ])->deleteFileAfterSend(true);

            case 'json':
            default:
                $content = $this->exportService->toJson($table);
                return response($content, 200, [
                    'Content-Type'        => 'application/json; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename="' . $filename . '.json"',
                ]);
        }
    }

    /**
     * Import a decision table from an uploaded CSV, Excel, or JSON file.
     *
     * The file is imported into the current project (X-Application header).
     * A new table is always created — existing tables are never overwritten.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function import()
    {
        $this->validate($this->request, [
            'file' => 'required|file|max:10240',
        ]);

        $file = $this->request->file('file');
        $ext  = strtolower($file->getClientOriginalExtension());

        if (!in_array($ext, ['json', 'csv', 'xlsx', 'xls'])) {
            return $this->response->json([
                'message' => 'Format de fichier non supporté. Formats acceptés: json, csv, xlsx, xls.',
            ], 422);
        }

        try {
            $table = $this->importService->fromFile($file);
        } catch (\RuntimeException $e) {
            // Structural validation errors from the import service
            return $this->response->json([
                'message' => 'Erreurs de validation dans le fichier importé.',
                'errors'  => json_decode($e->getMessage(), true) ?? [$e->getMessage()],
            ], 422);
        } catch (\InvalidArgumentException $e) {
            return $this->response->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return $this->response->json($table->toArray(), 201);
    }

    /**
     * Sanitize a table title for use as a filename.
     * Replaces anything that is not alphanumeric, dash, or underscore with "_".
     *
     * @param  string $title
     * @return string
     */
    private function sanitizeFilename(string $title): string
    {
        $safe = preg_replace('/[^a-z0-9_\-]/i', '_', $title ?? 'table');
        return mb_substr($safe ?: 'table', 0, 64);
    }
}
