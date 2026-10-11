<?php
/**
 * VersionCalls
 *
 * Calls per API key (VERSIONS.md, batches 3 and 4): one row per key, table (or
 * flow) and requested version in the `version_calls` collection, with the date
 * of the last call and a count. A row names its table in `table_id` or its flow
 * in `flow_id`. A call without a version has `requested_version` null: it runs
 * whichever version is active.
 *
 * The rows feed the activation screen (keys calling without a version, whose
 * behaviour an activation changes) and the guard against deleting a published
 * version a key still calls. A key counts as calling while its last call is
 * within the window (TABLE_VERSIONS_CALL_WINDOW_DAYS, 30 days by default) and
 * it is still a key of the application: a deleted key calls nothing.
 *
 * Only API keys are tracked. A user calling with an OAuth token has no code
 * pinned to a version.
 *
 * @package App\Services
 */

namespace App\Services;

use App\Models\Flow;
use App\Models\Table;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\BulkWriteException;
use Nebo15\LumenApplicationable\Models\Application;

class VersionCalls
{
    const COLLECTION = 'version_calls';

    const DEFAULT_WINDOW_DAYS = 30;

    // Two first calls of a key may race to insert the same row: the unique index
    // rejects the loser, whose retry updates the winner's row.
    const RECORD_ATTEMPTS = 2;

    const DUPLICATE_KEY = 11000;

    public static function windowDays()
    {
        $days = (int) env('TABLE_VERSIONS_CALL_WINDOW_DAYS', self::DEFAULT_WINDOW_DAYS);

        return $days > 0 ? $days : self::DEFAULT_WINDOW_DAYS;
    }

    /**
     * client_id of the API key making the current request, or null when the
     * caller is a user (OAuth token).
     *
     * @return string|null
     */
    public static function currentKey()
    {
        return app()->bound('applicationable.consumer')
            ? (string) app('applicationable.consumer')->client_id
            : null;
    }

    /**
     * Count a call of the current API key to a table; a user's call is not tracked.
     *
     * @param  string   $tableId
     * @param  int|null $requestedVersion  Version number the call named, null for none.
     * @param  string   $applicationId     Application the key belongs to.
     */
    public function record($tableId, $requestedVersion, $applicationId)
    {
        $this->recordCall('table_id', $tableId, $requestedVersion, $applicationId);
    }

    /**
     * Count a call of the current API key to a flow.
     *
     * @param  string   $flowId
     * @param  int|null $requestedVersion
     * @param  string   $applicationId
     */
    public function recordFlow($flowId, $requestedVersion, $applicationId)
    {
        $this->recordCall('flow_id', $flowId, $requestedVersion, $applicationId);
    }

    private function recordCall($idKey, $id, $requestedVersion, $applicationId)
    {
        $key = self::currentKey();
        if ($key === null) {
            return;
        }
        // Both ids in the filter: the unique index covers them, a missing one
        // being null.
        $filter = [
            'consumer' => $key,
            'table_id' => $idKey === 'table_id' ? (string) $id : null,
            'flow_id' => $idKey === 'flow_id' ? (string) $id : null,
            'requested_version' => $requestedVersion === null ? null : (int) $requestedVersion,
        ];
        $update = [
            '$set' => ['last_call_at' => new UTCDateTime, 'applications' => [(string) $applicationId]],
            '$inc' => ['count' => 1],
        ];
        for ($attempt = 1;; $attempt++) {
            try {
                \DB::collection(self::COLLECTION)->raw(function ($collection) use ($filter, $update) {
                    return $collection->updateOne($filter, $update, ['upsert' => true]);
                });

                return;
            } catch (BulkWriteException $e) {
                if ($attempt >= self::RECORD_ATTEMPTS || !self::isDuplicateKey($e)) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Recent calls of the application's keys to a table or a flow, latest first.
     *
     * @param  Table|Flow  $model
     * @param  Application $application  Its application.
     * @return array  [['consumer', 'description', 'requested_version', 'last_call_at', 'count'], …]
     */
    public function recentFor(Table|Flow $model, Application $application)
    {
        $keys = self::keysOf($application);
        if (!$keys) {
            return [];
        }
        $rows = \DB::collection(self::COLLECTION)
            ->where(self::idKey($model), (string) $model->getId())
            ->whereIn('consumer', array_keys($keys))
            ->where('last_call_at', '>=', $this->windowStart())
            ->orderBy('last_call_at', 'desc')
            ->get();

        return array_map(function ($row) use ($keys) {
            return [
                'consumer' => $row['consumer'],
                'description' => $keys[$row['consumer']],
                'requested_version' => $row['requested_version'],
                'last_call_at' => $row['last_call_at']->toDateTime()->format(\DateTime::ATOM),
                'count' => (int) $row['count'],
            ];
        }, $rows);
    }

    /**
     * Recent calls without a version: the keys an activation affects.
     *
     * @param  Table|Flow  $model
     * @param  Application $application
     * @return array  Same rows as recentFor().
     */
    public function withoutVersion(Table|Flow $model, Application $application)
    {
        return array_values(array_filter($this->recentFor($model, $application), function ($call) {
            return $call['requested_version'] === null;
        }));
    }

    /**
     * Whether a key of the application called a published version of the table
     * (or flow) by its number within the window.
     *
     * @param  Table|Flow  $model
     * @param  int         $number
     * @param  Application $application
     * @return bool
     */
    public function isCalled(Table|Flow $model, $number, Application $application)
    {
        $keys = self::keysOf($application);

        return $keys && \DB::collection(self::COLLECTION)
            ->where(self::idKey($model), (string) $model->getId())
            ->where('requested_version', (int) $number)
            ->whereIn('consumer', array_keys($keys))
            ->where('last_call_at', '>=', $this->windowStart())
            ->count() > 0;
    }

    public function deleteForTable($tableId)
    {
        \DB::collection(self::COLLECTION)->where('table_id', (string) $tableId)->delete();
    }

    public function deleteForFlow($flowId)
    {
        \DB::collection(self::COLLECTION)->where('flow_id', (string) $flowId)->delete();
    }

    public function deleteForApplication($applicationId)
    {
        \DB::collection(self::COLLECTION)->where('applications', (string) $applicationId)->delete();
    }

    /**
     * The application's API keys.
     *
     * @param  Application $application
     * @return array  client_id => description
     */
    public static function keysOf(Application $application)
    {
        $keys = [];
        foreach ($application->consumers as $consumer) {
            $keys[(string) $consumer->client_id] = (string) $consumer->description;
        }

        return $keys;
    }

    public static function isDuplicateKey(BulkWriteException $e)
    {
        foreach ($e->getWriteResult()->getWriteErrors() as $error) {
            if ($error->getCode() === self::DUPLICATE_KEY) {
                return true;
            }
        }

        return false;
    }

    private static function idKey(Table|Flow $model)
    {
        return $model instanceof Flow ? 'flow_id' : 'table_id';
    }

    private function windowStart()
    {
        return new UTCDateTime((time() - self::windowDays() * 86400) * 1000);
    }
}
