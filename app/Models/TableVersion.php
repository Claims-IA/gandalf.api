<?php
/**
 * TableVersion Model
 *
 * One version of a decision table's content, stored in the MongoDB
 * 'table_versions' collection: its fields, its variants (with their rules and
 * conditions) and how variants are split. A version is a draft until it is
 * published; a published version is numbered and frozen, except its split. The
 * table points to the version the API runs (Table::active_version_id).
 *
 * See VERSIONS.md for the lifecycle. During release A, versions are published
 * by TableVersioning whenever a table save changes its content.
 *
 * @package App\Models
 */

namespace App\Models;

use App\Models\Concerns\HasTableContent;
use Nebo15\LumenApplicationable\Contracts\Applicationable;
use Nebo15\LumenApplicationable\Traits\ApplicationableTrait;

/**
 * Class TableVersion
 * @package App\Models
 * @property string $table_id
 * @property string $status                draft | published
 * @property int|null $number              Assigned at publication, unique per table
 * @property string $name
 * @property string $comment
 * @property string|null $origin_version_id Version or draft this one comes from
 * @property bool $autopublished           Published by the release A compatibility
 *                                         mode or the migration, not by a person
 * @property string $variants_probability
 * @property Variant[] $variants
 * @property Field[] $fields
 * @property string|null $created_by
 * @property string|null $published_by
 */
class TableVersion extends Base implements Applicationable
{
    use ApplicationableTrait, HasTableContent;

    const STATUS_DRAFT = 'draft';
    const STATUS_PUBLISHED = 'published';

    protected $collection = 'table_versions';

    protected $attributes = [
        'table_id' => null,
        'status' => self::STATUS_DRAFT,
        'name' => '',
        'comment' => '',
        'origin_version_id' => null,
        'autopublished' => false,
        'variants_probability' => '',
        'created_by' => null,
        'published_by' => null,
        'published_at' => null,
    ];

    protected $visible = [
        '_id',
        'table_id',
        'status',
        'number',
        'name',
        'comment',
        'origin_version_id',
        'autopublished',
        'variants_probability',
        'fields',
        'variants',
        'created_by',
        'published_by',
        'published_at',
        'created_at',
        'updated_at',
    ];

    protected $fillable = [
        'name',
        'comment',
        'variants_probability',
    ];

    protected $dates = ['published_at'];

    protected $casts = [
        '_id' => 'string',
        'number' => 'integer',
        'autopublished' => 'boolean',
    ];

    /**
     * Expose fields and variants as serialisable relations for toArray().
     * @return array
     */
    protected function getArrayableRelations()
    {
        return [
            'fields' => $this->fields,
            'variants' => $this->variants,
        ];
    }

    public function isPublished()
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * The version without its content, for version lists.
     * @return array
     */
    public function toSummaryArray()
    {
        return array_diff_key($this->toArray(), array_flip(['fields', 'variants']));
    }

    /**
     * Dates as ISO-8601, like decisions (Decision::toConsumerArray).
     * @param  \DateTime $date
     * @return string
     */
    protected function serializeDate(\DateTime $date)
    {
        return $date->format(\DateTime::ATOM);
    }
}
