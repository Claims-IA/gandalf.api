<?php

use Illuminate\Database\Migrations\Migration;
use MongoDB\BSON\UTCDateTime;

/**
 * Table versions, release A (VERSIONS.md): each table becomes its version 1,
 * published and active. Its content is copied to table_versions and stays on the
 * table document, as the copy of its active version that release A keeps, so
 * this migration deletes nothing and its rollback only removes what it added.
 *
 * Idempotent: a table that already has an active version (migrated, or saved
 * since the code was deployed) is left as is.
 *
 * Raw collections, not Eloquent: saving a Table fires TableObserver, which needs
 * an authenticated user and would publish versions itself.
 */
class TableVersions extends Migration
{
    public function up()
    {
        // One number per published version of a table; drafts have none.
        \DB::collection('table_versions')->raw(function ($collection) {
            return $collection->createIndex(
                ['table_id' => 1, 'number' => 1],
                [
                    'name' => 'table_versions_number',
                    'unique' => true,
                    'partialFilterExpression' => ['status' => 'published'],
                ]
            );
        });

        foreach (\DB::collection('tables')->get() as $table) {
            if (!empty($table['active_version_id'])) {
                continue;
            }
            $tableId = (string) $table['_id'];
            $publishedAt = isset($table['updated_at']) ? $table['updated_at'] : new UTCDateTime;
            $versionId = \DB::collection('table_versions')->insertGetId([
                'table_id' => $tableId,
                'applications' => isset($table['applications']) ? $table['applications'] : [],
                'status' => 'published',
                'number' => 1,
                'name' => '',
                'comment' => '',
                'origin_version_id' => null,
                'autopublished' => true,
                'fields' => isset($table['fields']) ? $table['fields'] : [],
                'variants' => isset($table['variants']) ? $table['variants'] : [],
                'variants_probability' => isset($table['variants_probability']) ? $table['variants_probability'] : '',
                'created_by' => null,
                'published_by' => null,
                'published_at' => $publishedAt,
                'created_at' => $publishedAt,
                'updated_at' => $publishedAt,
            ]);
            \DB::collection('tables')->where('_id', $tableId)->update([
                '$set' => ['active_version_id' => (string) $versionId],
            ]);
        }
    }

    public function down()
    {
        \DB::collection('tables')->where('active_version_id', 'exists', true)->update([
            '$unset' => ['active_version_id' => ''],
        ]);
        \DB::collection('table_versions')->raw(function ($collection) {
            return $collection->drop();
        });
    }
}
