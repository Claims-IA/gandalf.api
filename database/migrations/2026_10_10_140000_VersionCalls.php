<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Table versions, batches 3 and 4 (VERSIONS.md): calls per API key, one row per
 * key, table or flow, and requested version (App\Services\VersionCalls). The
 * unique index keeps that one row: two first calls racing to insert it end up
 * as one row and one update. A row names a table (`table_id`) or a flow
 * (`flow_id`), the other id being null.
 *
 * Idempotent. The rollback drops the index and keeps the rows.
 */
class VersionCalls extends Migration
{
    const INDEX = 'version_calls_key';

    public function up()
    {
        \DB::collection('version_calls')->raw(function ($collection) {
            return $collection->createIndex(
                ['consumer' => 1, 'table_id' => 1, 'flow_id' => 1, 'requested_version' => 1],
                ['name' => self::INDEX, 'unique' => true]
            );
        });
    }

    public function down()
    {
        \DB::collection('version_calls')->raw(function ($collection) {
            foreach ($collection->listIndexes() as $index) {
                if ($index->getName() === self::INDEX) {
                    return $collection->dropIndex(self::INDEX);
                }
            }

            return null;
        });
    }
}
