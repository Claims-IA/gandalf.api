<?php
/**
 * VersionCalls
 *
 * Calls per API key (VERSIONS.md, batch 3): one row per key, table and
 * requested version in the `version_calls` collection, with the date of the
 * last call and a count. A call without a version has `requested_version`
 * null: it runs whichever version is active.
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
     * Count a call of the current API key; a user's call is not tracked.
     *
     * @param  string   $tableId
     * @param  int|null $requestedVersion  Version number the call named, null for none.
     * @param  string   $applicationId     Application the key belongs to.
     */
    public function record($tableId, $requestedVersion, $applicationId)
    {
        $key = self::currentKey();
        if ($key === null) {
            return;
        }
        $filter = [
            'consumer' => $key,
            'table_id' => (string) $tableId,
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
     * Recent calls of the application's keys to a table, latest first.
     *
     * @param  Table       $table
     * @param  Application $application  The table's application.
     * @return array  [['consumer', 'description', 'requested_version', 'last_call_at', 'count'], …]
     */
    public function recentFor(Table $table, Application $application)
    {
        $keys = self::keysOf($application);
        if (!$keys) {
            return [];
        }
        $rows = \DB::collection(self::COLLECTION)
            ->where('table_id', (string) $table->getId())
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
     * @param  Table       $table
     * @param  Application $application
     * @return array  Same rows as recentFor().
     */
    public function withoutVersion(Table $table, Application $application)
    {
        return array_values(array_filter($this->recentFor($table, $application), function ($call) {
            return $call['requested_version'] === null;
        }));
    }

    /**
     * Whether a key of the application called a published version of the table
     * by its number within the window.
     *
     * @param  Table       $table
     * @param  int         $number
     * @param  Application $application
     * @return bool
     */
    public function isCalled(Table $table, $number, Application $application)
    {
        $keys = self::keysOf($application);

        return $keys && \DB::collection(self::COLLECTION)
            ->where('table_id', (string) $table->getId())
            ->where('requested_version', (int) $number)
            ->whereIn('consumer', array_keys($keys))
            ->where('last_call_at', '>=', $this->windowStart())
            ->count() > 0;
    }

    public function deleteForTable($tableId)
    {
        \DB::collection(self::COLLECTION)->where('table_id', (string) $tableId)->delete();
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

    private function windowStart()
    {
        return new UTCDateTime((time() - self::windowDays() * 86400) * 1000);
    }
}
