<?php
/**
 * Console Kernel
 *
 * Registers the application's Artisan commands and defines the scheduled task
 * timetable. Three recurring jobs keep the system healthy: send:statistic runs
 * every minute to push decision throughput metrics to CachetHQ, tokens:delete
 * runs hourly to purge expired OAuth and email tokens from user documents, and
 * dump:delete runs twice daily to remove project export archives older than 24 hours.
 *
 * @package App\Console
 */

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Laravel\Lumen\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        Commands\SendStatistic::class,
        Commands\DeleteExpiredTokens::class,
        Commands\DeleteExpiredProjectDumps::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('send:statistic')->everyMinute();
        $schedule->command('tokens:delete')->hourly();
        $schedule->command('dump:delete')->twiceDaily(1, 13);
    }
}
