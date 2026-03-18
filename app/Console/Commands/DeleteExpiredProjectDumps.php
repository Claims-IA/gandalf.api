<?php
/**
 * DeleteExpiredProjectDumps Command
 *
 * Artisan command (dump:delete) that cleans up project export archives from the
 * public/dump/ directory. Any .tar.gz file whose name contains a UTC timestamp
 * older than 24 hours is deleted. This prevents the disk from filling up with
 * abandoned exports. Scheduled to run twice daily at 01:00 and 13:00 UTC.
 *
 * @package App\Console\Commands
 */

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;

class DeleteExpiredProjectDumps extends Command
{
    protected $signature = 'dump:delete';

    protected $description = 'Delete expired project dumps';

    /**
     * Execute the cleanup command.
     *
     * Scans the public/dump/ directory and removes any file whose name contains
     * a 'Y-m-d_H:i:s' UTC timestamp that is 24 or more hours in the past.
     * Files without a recognisable timestamp in the name are left untouched.
     *
     * @return void
     */
    public function handle()
    {
        $now = Carbon::now();
        $dir = __DIR__ . '/../../../public/dump/';
        foreach (scandir($dir) as $dump) {
            // Extract the timestamp from the filename (format: 2016-07-01_13:00:00)
            if (preg_match('/\d{4}-\d{2}-\d{2}_\d{2}:\d{2}:\d{2}/', $dump, $time) and
                $now->diffInHours(Carbon::createFromFormat('Y-m-d_H:i:s', $time[0])) >= 24
            ) {
                unlink($dir . $dump);
            }
        }
    }
}
