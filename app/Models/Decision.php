<?php
/**
 * Decision Model
 *
 * Represents an immutable audit record of a single decision evaluation stored in
 * the MongoDB 'decisions' collection. Every time the Scoring service evaluates a
 * table, it creates a Decision document capturing the request inputs, the matched
 * rules and conditions with their individual results, the final decision value, and
 * an optional metadata bag. The Applicationable trait scopes all queries to the
 * current application for multi-tenant isolation.
 *
 * @package App\Models
 */

namespace App\Models;

use Nebo15\LumenApplicationable\Contracts\Applicationable;
use Nebo15\LumenApplicationable\Traits\ApplicationableTrait;

/**
 * Class Decision
 * @package App\Models
 * @property string $title
 * @property string $description
 * @property string $default_decision
 * @property string $final_decision
 * @property string $application
 * @property array $request
 * @property array $table
 * @property array $meta
 * @property array $group
 * @property Rule[] $rules
 * @property Field[] $fields
 * @method static Decision findById($id)
 * @method Decision save(array $options = [])
 * @method static \Illuminate\Pagination\LengthAwarePaginator paginate($perPage = 15, $columns = ['*'], $pageName = 'page', $page = null)
 */
class Decision extends Base implements Applicationable
{
    use ApplicationableTrait;

    protected $visible = [
        '_id',
        'title',
        'description',
        'meta',
        'table',
        'application',
        'fields',
        'request',
        'rules',
        'default_decision',
        'final_decision',
        self::CREATED_AT,
        self::UPDATED_AT,
    ];

    protected $fillable = [
        'title',
        'description',
        'meta',
        'table',
        'application',
        'fields',
        'request',
        'rules',
        'default_decision',
        'final_decision',
        'applications',
    ];

    protected $attributes = [
        'title' => '',
        'description' => '',
        'meta' => [],
        'table' => [],
        'fields' => [],
        'request' => [],
        'rules' => [],
        'default_decision' => '',
        'final_decision' => '',
    ];

    public function __construct(array $attributes = [])
    {
        $this->attributes = array_merge($this->attributes, ['applications' => []]);
        parent::__construct($attributes);
    }

    protected $hidden = ['applications'];

    protected $dateFormat = \DateTime::ISO8601;

    protected $dates = ['created_at', 'updated_at'];

    protected $perPage = 20;

    /**
     * Define the embedded-many relationship for rule snapshots.
     *
     * Rules stored on a Decision are a snapshot taken at decision time, not live
     * references to the Table's current rules, so changes to the Table do not
     * alter historical decisions.
     *
     * @return \Jenssegers\Mongodb\Relations\EmbedsMany
     */
    public function rules()
    {
        return $this->embedsMany('App\Models\Rule');
    }

    /**
     * Define the embedded-many relationship for field snapshots.
     *
     * @return \Jenssegers\Mongodb\Relations\EmbedsMany
     */
    public function fields()
    {
        return $this->embedsMany('App\Models\Field');
    }

    /**
     * Return a consumer-safe subset of the decision suitable for API consumers.
     *
     * Omits internal fields (applications array, meta) and reduces the rules list
     * to just title, description, and decision outcome. Timestamps are formatted
     * as ISO-8601 strings to ensure consistent serialisation regardless of driver.
     *
     * The `rules` breakdown is gated by the application's `show_meta` setting, in
     * line with the live decision endpoint (POST /tables/{id}/decisions): when
     * `show_meta` is disabled the key is omitted entirely.
     *
     * @param  bool $showMeta  Whether to include the per-rule summary.
     * @return array
     */
    public function toConsumerArray($showMeta = false)
    {
        $data = [
            '_id' => $this->getId(),
            'table' => $this->getTableArray(),
            'application' => $this->application,
            'title' => $this->title,
            'description' => $this->description,
            'final_decision' => $this->final_decision,
            'request' => $this->request,
            self::CREATED_AT => $this->getAttribute(self::CREATED_AT)->toIso8601String(),
            self::UPDATED_AT => $this->getAttribute(self::UPDATED_AT)->toIso8601String(),
        ];

        if ($showMeta) {
            // Only expose the summary columns for each rule — not the full condition breakdown
            $data['rules'] = $this->rules()->get()->map(function (Rule $rule) {
                return [
                    'title' => $rule->title,
                    'description' => $rule->description,
                    'decision' => $rule->decision,
                ];
            })->toArray();
        }

        return $data;
    }

    /**
     * Serialize the decision to an array, coercing the embedded table ObjectIDs to strings.
     *
     * MongoDB ObjectID objects are not JSON-serialisable by default; this override
     * ensures the table._id and table.variant._id values are always plain strings.
     *
     * @return array
     */
    public function toArray()
    {
        // The 'table' property stores ObjectID objects from MongoDB; force them to strings
        $data = parent::toArray();
        $data['table'] = $this->getTableArray();

        return $data;
    }

    /**
     * Return the table reference with MongoDB ObjectIDs cast to strings.
     *
     * Centralised here so both toArray() and toConsumerArray() use the same logic.
     *
     * @return array
     */
    public function getTableArray()
    {
        $data = $this->getAttribute('table');
        $data['_id'] = strval($data['_id']);
        $data['variant']['_id'] = strval($data['variant']['_id']);

        return $data;
    }
}
