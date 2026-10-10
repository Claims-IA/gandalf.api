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

        return $version;
    }

    /**
     * Delete all versions of a deleted table.
     *
     * @param  Table $table
     */
    public function deleteForTable(Table $table)
    {
        TableVersion::where('table_id', (string) $table->getId())->delete();
    }

    /**
     * Fingerprint of a table's or a version's content: fields, variants (with
     * rules and conditions) and split mode. Audit keys are ignored, BSON ids
     * compared as strings and object keys sorted, so two saves of the same
     * content match. Lists keep their order: fields, rules and conditions are
     * ordered. A field or variant key that is missing counts as its model
     * default, as a save would store it: content stored before a default was
     * added (an old table, the migration's copy of it) matches its next save.
     *
     * @param  array $attributes  Raw attributes of a Table or a TableVersion.
     * @return string
     */
    public static function contentHash(array $attributes)
    {
        return sha1(json_encode(self::normalize([
            'fields' => self::withDefaults($attributes['fields'] ?? [], (new Field)->getAttributes()),
            'variants' => self::withDefaults($attributes['variants'] ?? [], (new Variant)->getAttributes()),
            'variants_probability' => (string) ($attributes['variants_probability'] ?? ''),
        ])));
    }

    private static function withDefaults($items, array $defaults)
    {
        if (!is_array($items)) {
            return $items;
        }
        foreach ($items as $i => $item) {
            if (is_array($item)) {
                $items[$i] = $item + $defaults;
            }
        }

        return $items;
    }

    private static function normalize($value)
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
     * Publish a version holding the table's current content, numbered after the
     * table's last published version.
     *
     * @param  Table             $table
     * @param  TableVersion|null $origin  The version active before this save.
     * @return TableVersion
     * @throws BulkWriteException  When the number is still taken after retries.
     */
    private function publishFromTable(Table $table, $origin)
    {
        $attributes = $table->getAttributes();
        $user = \Auth::guard()->user();
        $userId = $user ? (string) $user->getId() : null;

        for ($attempt = 1;; $attempt++) {
            $version = new TableVersion;
            $version->setAttribute('table_id', (string) $table->getId());
            $version->setAttribute('applications', array_values((array) $table->getApplications()));
            $version->setAttribute('status', TableVersion::STATUS_PUBLISHED);
            $version->setAttribute('number', $this->nextNumber($table));
            $version->setAttribute('origin_version_id', $origin ? (string) $origin->getId() : null);
            $version->setAttribute('autopublished', true);
            // Copied as stored, ids and audit stamps included: the version holds
            // exactly what the table holds.
            $version->setAttribute('fields', $attributes['fields'] ?? []);
            $version->setAttribute('variants', $attributes['variants'] ?? []);
            $version->setAttribute('variants_probability', $attributes['variants_probability'] ?? '');
            $version->setAttribute('created_by', $userId);
            $version->setAttribute('published_by', $userId);
            $version->setAttribute('published_at', \Carbon\Carbon::now());
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

    private function nextNumber(Table $table)
    {
        $last = TableVersion::where('table_id', (string) $table->getId())
            ->where('status', TableVersion::STATUS_PUBLISHED)
            ->orderBy('number', 'desc')
            ->first(['number']);

        return $last ? ((int) $last->number) + 1 : 1;
    }
}
