<?php
/**
 * TableVersionsRepository
 *
 * Lifecycle of a table's versions (VERSIONS.md, batch 2):
 * - drafts, created from a version or another draft and edited like a table;
 * - publication: a draft is frozen and numbered;
 * - activation: the published version the API runs;
 * - variant split changes, allowed on published versions;
 * - deletion, refused for the active version and for a version an API key
 *   still calls.
 *
 * During release A the table document holds a copy of its active version's
 * content: activating a version, or changing the split of the active one,
 * copies the content onto the table (TableVersioning::copyIntoTable).
 *
 * @package App\Repositories
 */

namespace App\Repositories;

use App\Models\Table;
use App\Models\TableVersion;
use App\Services\VersionCalls;
use App\Services\TableVersioning;
use Illuminate\Contracts\Validation\ValidationException;
use Nebo15\LumenApplicationable\Models\Application;

class TableVersionsRepository
{
    // Bounds the walk up a version's origins (see descendsFrom).
    const MAX_ORIGIN_DEPTH = 100;

    private $tables;

    private $versioning;

    private $calls;

    public function __construct(TablesRepository $tables, TableVersioning $versioning, VersionCalls $calls)
    {
        $this->tables = $tables;
        $this->versioning = $versioning;
        $this->calls = $calls;
    }

    /**
     * A table's versions without their content: drafts first (last edited
     * first), then published versions (newest first).
     *
     * @param  Table $table
     * @return TableVersion[]
     */
    public function listFor(Table $table)
    {
        $versions = TableVersion::where('table_id', (string) $table->getId())
            ->get(['_id', 'table_id', 'applications', 'status', 'number', 'name', 'comment', 'origin_version_id',
                'autopublished', 'variants_probability', 'created_by', 'published_by', 'published_at',
                'created_at', 'updated_at'])
            ->all();
        usort($versions, function (TableVersion $a, TableVersion $b) {
            if ($a->isPublished() !== $b->isPublished()) {
                return $a->isPublished() ? 1 : -1;
            }
            if ($a->isPublished()) {
                return $b->number <=> $a->number;
            }

            return strcmp((string) $b->updated_at, (string) $a->updated_at);
        });

        return $versions;
    }

    /**
     * @param  Table  $table
     * @param  string $versionId
     * @return TableVersion
     */
    public function find(Table $table, $versionId)
    {
        $version = TableVersion::where('_id', $versionId)->where('table_id', (string) $table->getId())->first();
        if (!$version) {
            abort(404, 'version_not_found');
        }

        return $version;
    }

    public function isActive(Table $table, TableVersion $version)
    {
        return (string) $table->active_version_id === (string) $version->getId();
    }

    /**
     * Create a draft holding the content of a version or a draft of the table,
     * by default its active version (or the table itself before it has one).
     *
     * @param  Table       $table
     * @param  string|null $fromVersionId
     * @param  string      $name
     * @return TableVersion
     */
    public function createDraft(Table $table, $fromVersionId = null, $name = '')
    {
        $source = $fromVersionId ? $this->find($table, $fromVersionId) : $this->versioning->activeVersion($table);

        $draft = new TableVersion;
        $draft->setAttribute('table_id', (string) $table->getId());
        $draft->setAttribute('applications', array_values((array) $table->getApplications()));
        $draft->setAttribute('status', TableVersion::STATUS_DRAFT);
        $draft->setAttribute('name', (string) $name);
        $draft->setAttribute('origin_version_id', $source ? (string) $source->getId() : null);
        $draft->setAttribute('created_by', TableVersioning::currentUserId());
        // Variant ids are kept: a client forcing a variant keeps working across
        // versions, as long as the variant exists.
        TableVersioning::copyContent($source ?: $table, $draft);
        $draft->save();

        return $draft;
    }

    /**
     * Replace a draft's content, with the same rename and realignment rules as
     * a table update (TablesRepository::writeContent).
     *
     * @param  TableVersion $draft
     * @param  array        $values  Validated content payload, plus an optional name.
     * @return TableVersion
     */
    public function updateDraft(TableVersion $draft, array $values)
    {
        if ($draft->isPublished()) {
            abort(409, 'version_published');
        }
        $draft->fill(array_intersect_key($values, array_flip(['name', 'variants_probability'])));
        $this->tables->writeContent($draft, $values);
        $draft->save();

        return $draft;
    }

    /**
     * @param  TableVersion $draft
     * @param  string       $comment
     * @return TableVersion
     */
    public function publish(TableVersion $draft, $comment = '')
    {
        if ($draft->isPublished()) {
            abort(409, 'version_published');
        }

        return $this->versioning->publish($draft, $comment);
    }

    /**
     * Make a published version the one the API runs. In release A its content
     * is copied onto the table, and the application's flows follow the field
     * renames between the previous active version and this one, as they follow
     * a table update.
     *
     * @param  Table        $table
     * @param  TableVersion $version
     * @return array  Response meta: field_renames, flows_updated, flows_failed
     *                when fields were renamed.
     */
    public function activate(Table $table, TableVersion $version)
    {
        if (!$version->isPublished()) {
            abort(409, 'version_not_published');
        }
        $previous = $this->versioning->activeVersion($table);
        $renames = $previous ? TablesRepository::fieldRenamesBetween(
            $previous->getAttributes()['fields'] ?? [],
            $version->getAttributes()['fields'] ?? []
        ) : [];

        $this->versioning->copyIntoTable($table, $version);

        if (!$renames) {
            return [];
        }

        return ['field_renames' => (object) $renames]
            + (new FlowRepository())->followFieldRenames((string) $table->getId(), $renames);
    }

    /**
     * Change the variant split of a version, published or not. The split is an
     * operating setting: it changes without a new version.
     *
     * @param  Table        $table
     * @param  TableVersion $version
     * @param  string       $mode      first | random | percent
     * @param  array        $variants  [['_id' => …, 'probability' => int], …]
     * @return TableVersion
     * @throws ValidationException  When percentages do not add up to 100.
     */
    public function updateSplit(Table $table, TableVersion $version, $mode, array $variants)
    {
        list($merged, $unknown) = self::mergeSplit($version->getAttributes()['variants'] ?? [], $variants);
        if ($unknown) {
            abort(422, 'variant_not_found');
        }
        $validator = \Validator::make(
            ['variants_probability' => $mode, 'variants' => $merged],
            ['variants_probability' => 'required|in:first,random,percent|probabilitySum']
        );
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $version->setAttribute('variants', $merged);
        $version->setAttribute('variants_probability', $mode);
        $version->save();
        if ($this->isActive($table, $version)) {
            $this->versioning->copyIntoTable($table, $version);
        }

        return $version;
    }

    /**
     * Delete a version. The active version stays: activate another one first.
     * A published version an API key of the application called by its number
     * within the window stays too (VersionCalls). Flows pin versions from batch
     * 4; their guard arrives with them.
     *
     * @param  Table        $table
     * @param  TableVersion $version
     * @param  Application  $application  The table's application, whose keys count.
     */
    public function delete(Table $table, TableVersion $version, Application $application)
    {
        if ($this->isActive($table, $version)) {
            abort(409, 'version_in_use');
        }
        if ($version->isPublished() && $this->calls->isCalled($table, $version->number, $application)) {
            abort(409, 'version_in_use');
        }
        $version->delete();
    }

    /**
     * Warning for a version that does not descend from the table's active
     * version: it lacks the changes published since it was branched off.
     *
     * @param  Table        $table
     * @param  TableVersion $version
     * @return array|null  Response meta, null when the version is up to date.
     */
    public function divergence(Table $table, TableVersion $version)
    {
        $active = $this->versioning->activeVersion($table);
        if (!$active) {
            return null;
        }
        $origins = function ($id) {
            $origin = TableVersion::where('_id', $id)->first(['origin_version_id']);

            return $origin ? $origin->origin_version_id : null;
        };
        if (self::descendsFrom((string) $version->getId(), (string) $active->getId(), $origins)) {
            return null;
        }

        return [
            'warning' => 'version_diverges',
            'active_version' => ['_id' => (string) $active->getId(), 'number' => $active->number],
        ];
    }

    /**
     * Apply new split percentages to stored variants.
     *
     * @param  array $stored   Stored variants (each with an '_id').
     * @param  array $updates  [['_id' => …, 'probability' => int], …]
     * @return array  [variants with the new probabilities, ids of unknown variants]
     */
    public static function mergeSplit(array $stored, array $updates)
    {
        $probabilities = [];
        foreach ($updates as $update) {
            $probabilities[(string) $update['_id']] = (int) $update['probability'];
        }
        foreach ($stored as $i => $variant) {
            $id = (string) $variant['_id'];
            if (array_key_exists($id, $probabilities)) {
                $stored[$i]['probability'] = $probabilities[$id];
                unset($probabilities[$id]);
            }
        }

        return [$stored, array_keys($probabilities)];
    }

    /**
     * Whether a version is, or comes from, another one, following origins.
     *
     * @param  string   $versionId
     * @param  string   $ancestorId
     * @param  callable $originOf   version id => origin version id (or null)
     * @return bool
     */
    public static function descendsFrom($versionId, $ancestorId, callable $originOf)
    {
        $seen = [];
        for ($id = $versionId, $depth = 0; $id && $depth < self::MAX_ORIGIN_DEPTH; $depth++) {
            if ($id === $ancestorId) {
                return true;
            }
            if (isset($seen[$id])) {
                return false;
            }
            $seen[$id] = true;
            $id = $originOf($id);
            $id = $id === null ? null : (string) $id;
        }

        return false;
    }
}
