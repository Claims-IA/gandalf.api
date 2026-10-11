<?php
/**
 * TableVersioning
 *
 * Release A of table versioning (VERSIONS.md, "Migration and compatibility
 * mode"). The editor still saves tables directly, and the table document keeps
 * a copy of its active version's content. After every table save this service
 * keeps that invariant: when the saved content differs from the active
 * version's, it publishes a new version holding it and activates it. It also
 * resolves the version a decision runs.
 *
 * The compatibility mode is driven by TABLE_VERSIONS_LEGACY_AUTOPUBLISH (on by
 * default) and disappears with release B.
 *
 * @package App\Services
 */

namespace App\Services;

use App\Models\Field;
use App\Models\Table;
use App\Models\Variant;
use App\Models\TableVersion;
use MongoDB\BSON\ObjectID;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\BulkWriteException;

class TableVersioning
{
    // Stamped on every save (Table::setVariants): never a content difference.
    const AUDIT_KEYS = ['created_at', 'created_by', 'updated_at', 'updated_by'];

    // Stored by saves before batch 2b (the default variant flag), no longer
    // content: a table saved since must match a version stored before.
    const DROPPED_VARIANT_KEYS = ['is_default'];

    // Two saves of the same table may race for the next version number; the
    // unique (table_id, number) index rejects the loser, which retries.
    const PUBLISH_ATTEMPTS = 3;

    public static function legacyAutoPublish()
    {
        return (bool) env('TABLE_VERSIONS_LEGACY_AUTOPUBLISH', true);
    }

    /**
     * The active version of a table, or null while it has none (a table saved
     * before the migration ran and since).
     *
     * @param  Table $table
     * @return TableVersion|null
     */
    public function activeVersion(Table $table)
    {
        $id = $table->active_version_id;
        if (!$id) {
            return null;
        }

        return TableVersion::where('_id', $id)
            ->where('table_id', (string) $table->getId())
            ->first();
    }

    /**
     * Keep a table's versions in step with it after a save (TableObserver):
     * - the versions follow the table when it moves to another application;
     * - in compatibility mode, a content change publishes and activates a
     *   version holding the saved content.
     *
     * @param  Table $table
     * @return TableVersion|null  The version published, if any.
     */
    public function syncFromTable(Table $table)
    {
        $active = $this->activeVersion($table);
        $applications = array_values((array) $table->getApplications());
        if ($active && array_values((array) $active->getApplications()) !== $applications) {
            TableVersion::where('table_id', (string) $table->getId())
                ->update(['applications' => $applications]);
        }

        if (!self::legacyAutoPublish()) {
            return null;
        }
        if ($active && self::contentHash($active->getAttributes()) === self::contentHash($table->getAttributes())) {
            return null;
        }

        $version = $this->publishFromTable($table, $active);
        $versionId = (string) $version->getId();
        // A query update fires no model event, so the table is not saved again.
        Table::where('_id', $table->getId())->update(['active_version_id' => $versionId]);
        $table->setAttribute('active_version_id', $versionId);
        $table->syncOriginalAttribute('active_version_id');
        // Flows run the live table in compatibility mode: they follow.
        app(FlowVersioning::class)->followTable($table);

        return $version;
    }

    /**
     * Delete all versions of a deleted table, and the calls counted on it.
     *
     * @param  Table $table
     */
    public function deleteForTable(Table $table)
    {
        TableVersion::where('table_id', (string) $table->getId())->delete();
        (new VersionCalls)->deleteForTable($table->getId());
    }

    /**
     * Fingerprint of a table's or a version's content: fields, variants (with
     * rules and conditions) and split mode. Audit keys are ignored, BSON ids
     * compared as strings and object keys sorted, so two saves of the same
     * content match. Lists keep their order: fields, rules and conditions are
     * ordered. A field or variant key that is missing counts as its model
     * default, as a save would store it: content stored before a default was
     * added (an old table, the migration's copy of it) matches its next save,
     * and a variant key that is no longer stored (`is_default`) is ignored.
     *
     * @param  array $attributes  Raw attributes of a Table or a TableVersion.
     * @return string
     */
    public static function contentHash(array $attributes)
    {
        return sha1(json_encode(self::normalize([
            'fields' => self::withDefaults($attributes['fields'] ?? [], (new Field)->getAttributes()),
            'variants' => self::withDefaults(
                $attributes['variants'] ?? [],
                (new Variant)->getAttributes(),
                self::DROPPED_VARIANT_KEYS
            ),
            'variants_probability' => (string) ($attributes['variants_probability'] ?? ''),
        ])));
    }

    private static function withDefaults($items, array $defaults, array $dropped = [])
    {
        if (!is_array($items)) {
            return $items;
        }
        foreach ($items as $i => $item) {
            if (is_array($item)) {
                $items[$i] = array_diff_key($item, array_flip($dropped)) + $defaults;
            }
        }

        return $items;
    }

    /**
     * A value made comparable for fingerprints: audit keys dropped, BSON ids and
     * dates as strings, object keys sorted, lists kept in order. Shared with
     * FlowVersioning.
     *
     * @param  mixed $value
     * @return mixed
     */
    public static function normalize($value)
    {
        if ($value instanceof ObjectID) {
            return (string) $value;
        }
        if ($value instanceof UTCDateTime) {
            return (string) $value;
        }
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }
        if (!is_array($value)) {
            return $value;
        }
        $normalized = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, self::AUDIT_KEYS, true)) {
                continue;
            }
            $normalized[$key] = self::normalize($item);
        }
        if ($normalized !== array_values($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }

    /**
     * Publish a version holding the table's current content (compatibility
     * mode), numbered after the table's last published version.
     *
     * @param  Table             $table
     * @param  TableVersion|null $origin  The version active before this save.
     * @return TableVersion
     */
    private function publishFromTable(Table $table, $origin)
    {
        $version = new TableVersion;
        $version->setAttribute('table_id', (string) $table->getId());
        $version->setAttribute('applications', array_values((array) $table->getApplications()));
        $version->setAttribute('origin_version_id', $origin ? (string) $origin->getId() : null);
        $version->setAttribute('created_by', self::currentUserId());
        // Copied as stored, ids and audit stamps included: the version holds
        // exactly what the table holds.
        self::copyContent($table, $version);

        return $this->publish($version, '', true);
    }

    /**
     * Publish a version: freeze it and number it after the table's last
     * published version. The active version does not change.
     *
     * @param  TableVersion $version        A draft, or a new version.
     * @param  string       $comment
     * @param  bool         $autopublished  Published by the compatibility mode.
     * @return TableVersion
     * @throws BulkWriteException  When the number is still taken after retries.
     */
    public function publish(TableVersion $version, $comment = '', $autopublished = false)
    {
        $version->setAttribute('status', TableVersion::STATUS_PUBLISHED);
        $version->setAttribute('comment', (string) $comment);
        $version->setAttribute('autopublished', (bool) $autopublished);
        $version->setAttribute('published_by', self::currentUserId());
        $version->setAttribute('published_at', \Carbon\Carbon::now());

        for ($attempt = 1;; $attempt++) {
            $version->setAttribute('number', $this->nextNumber($version->table_id));
            try {
                $version->save();

                return $version;
            } catch (BulkWriteException $e) {
                if ($attempt >= self::PUBLISH_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Release A: make a published version the one its table runs, and copy its
     * content onto the table (the table holds its active version's content).
     * The table save (TableObserver) then finds both contents equal and
     * publishes nothing. In compatibility mode the flows using the table follow
     * (FlowVersioning::followTable); otherwise they keep their pinned versions.
     *
     * @param  Table        $table
     * @param  TableVersion $version
     * @return Table
     */
    public function copyIntoTable(Table $table, TableVersion $version)
    {
        self::copyContent($version, $table);
        $table->setAttribute('active_version_id', (string) $version->getId());
        $table->save();
        app(FlowVersioning::class)->followTable($table);

        return $table;
    }

    /**
     * Copy the content (fields, variants, split) of a table or a version to
     * another, as stored: ids and audit stamps included.
     *
     * @param  Table|TableVersion $from
     * @param  Table|TableVersion $to
     */
    public static function copyContent($from, $to)
    {
        $attributes = $from->getAttributes();
        $to->setAttribute('fields', $attributes['fields'] ?? []);
        $to->setAttribute('variants', $attributes['variants'] ?? []);
        $to->setAttribute('variants_probability', $attributes['variants_probability'] ?? '');
    }

    public static function currentUserId()
    {
        $user = \Auth::guard()->user();

        return $user ? (string) $user->getId() : null;
    }

    private function nextNumber($tableId)
    {
        $last = TableVersion::where('table_id', (string) $tableId)
            ->where('status', TableVersion::STATUS_PUBLISHED)
            ->orderBy('number', 'desc')
            ->first(['number']);

        return $last ? ((int) $last->number) + 1 : 1;
    }
}
