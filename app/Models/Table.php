<?php
/**
 * Table Model
 *
 * Represents a decision table stored in the MongoDB 'tables' collection. A table
 * defines the schema (fields) and evaluation logic (one or more variants, each
 * containing ordered rules with conditions) used by the Scoring service. Tables
 * support two matching types: 'decision' (first matching rule wins) and 'scoring'
 * (all matching rules contribute a numeric value). The Applicationable trait scopes
 * queries to the currently authenticated application for multi-tenant isolation.
 *
 * Its content (fields, variants) lives in its versions (TableVersion). During
 * release A the table document keeps a copy of its active version's content,
 * kept in step by TableVersioning (see VERSIONS.md).
 *
 * @package App\Models
 */

namespace App\Models;

use App\Models\Concerns\HasTableContent;
use Nebo15\REST\Traits\ListableTrait;
use Nebo15\REST\Interfaces\ListableInterface;
use Nebo15\LumenApplicationable\Contracts\Applicationable;
use Nebo15\LumenApplicationable\Traits\ApplicationableTrait;

/**
 * Class Table
 * @package App\Models
 * @property string $title
 * @property string $description
 * @property string $matching_type
 * @property string $variants_probability
 * @property Variant[] $variants
 * @property Field[] $fields
 * @property string|null $origin_table_id  Root table this one was copied from (internal,
 *                                         set by TablesRepository::duplicateInto)
 * @property string|null $active_version_id  Published TableVersion run by default (internal,
 *                                           set by TableVersioning)
 * @method static Decision findById($id)
 * @method static Decision create(array $attributes = [])
 * @method Decision save(array $options = [])
 * @method static \Illuminate\Pagination\LengthAwarePaginator paginate($perPage = 15, $columns = ['*'], $pageName = 'page', $page = null)
 */
class Table extends Base implements ListableInterface, Applicationable
{
    use ApplicationableTrait, HasTableContent;

    protected $perPage = 20;

    protected $listable = ['_id', 'title', 'description', 'matching_type', 'category_id'];

    protected $attributes = [
        'title' => '',
        'description' => '',
        'matching_type' => 'first',
        'variants_probability' => '',
        // Optional reference to a category defined in the owning application's
        // settings.categories list. Null means "uncategorised".
        'category_id' => null,
    ];

    protected $visible = [
        '_id',
        'title',
        'description',
        'matching_type',
        'decision_type',
        'variants_probability',
        'category_id',
        'fields',
        'variants',
        'active_version_id',
    ];

    protected $fillable = [
        'title',
        'description',
        'matching_type',
        'decision_type',
        'variants_probability',
        'category_id',
    ];

    protected $casts = [
        '_id' => 'string',
        'title' => 'string',
        'description' => 'string',
        'default_title' => 'string',
        'default_description' => 'string',
    ];

    /**
     * Expose fields and variants as serialisable relations for toArray().
     *
     * @return array
     */
    protected function getArrayableRelations()
    {
        return [
            'fields' => $this->fields,
            'variants' => $this->variants,
        ];
    }

    /**
     * Id of the table this one originally comes from: its own id when it is not
     * a copy. A copy of a copy keeps the root, so all copies of a table — in any
     * application — share one origin id (used to reuse a table already present
     * in an application instead of copying it again, see FlowRepository).
     *
     * @return string
     */
    public function originId()
    {
        return (string) ($this->origin_table_id ?: $this->getKey());
    }

    /**
     * Return the minimal summary representation used in list endpoints.
     *
     * Includes the listable scalar fields plus a reduced variant list
     * (id, title, description only) to avoid returning the full rule set.
     *
     * @return array
     */
    public function toListArray()
    {
        $array = [];
        foreach ($this->listable as $field) {
            $array[$field] = $this->$field;
        }
        // Include variant summaries so the frontend can show variant picker without fetching full table
        $array['variants'] = $this->variants()->get()->map(function (Variant $variant) {
            return [
                '_id'        => $variant->_id,
                'title'      => $variant->title,
                'description'=> $variant->description,
                'is_default' => (bool)$variant->is_default,
            ];
        });

        return $array;
    }
}
