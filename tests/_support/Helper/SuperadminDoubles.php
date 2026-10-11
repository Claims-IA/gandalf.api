<?php
/**
 * In-memory doubles for tests/unit/SuperadminTest.php (two-level
 * administration, ADMIN.md).
 *
 * Kept out of the test file: Codeception 2.1 reads every `class` keyword of a
 * test file as a test class name.
 */

namespace Helper;

use App\Services\AdminLog;
use Nebo15\LumenApplicationable\Models\Application;

/** Application whose member lookup needs no database. */
class FakeApplication extends Application
{
    public $member;

    public function getUser($user_id)
    {
        return $this->member;
    }
}

/** Admin log keeping its entries in memory. */
class MemoryAdminLog extends AdminLog
{
    public $entries = [];

    protected function write(array $attributes)
    {
        $this->entries[] = $attributes;

        return null;
    }
}
