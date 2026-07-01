<?php
/**
 * SendStatistic Command
 *
 * Artisan command (send:statistic) that counts the number of decisions made in
 * the previous minute and pushes that count to CachetHQ (a status-page service)
 * so the decisions-per-minute metric is visible on the public status dashboard.
 * Scheduled to run every minute by the Console Kernel. Uses raw cURL rather than
 * Guzzle/Http client to avoid adding a dependency for a simple POST request.
 *
 * @package App\Console\Commands
 */
namespace App\Console\Commands;

use App\Models\Decision;
use MongoDB\BSON\UTCDatetime;
use Illuminate\Console\Command;

class SendStatistic extends Command
{
    protected $signature = 'send:statistic';

    protected $description = 'Send statistic of usage to CachetHQ service';

    /**
     * Execute the statistic submission command.
     *
     * Counts Decision documents created in the last 60 seconds using a MongoDB
     * range query on the created_at field (UTCDatetime objects), then POSTs the
     * count to the CachetHQ API endpoint configured in services.status.
     *
     * @return void
     */
    public function handle()
    {
        $date = (new \DateTime('now'));

        // Count decisions within the [now-1min, now) window
        // UTCDatetime expects milliseconds, so multiply Unix timestamp by 1000
        $countDecisionsPerMinute = Decision::where([
            'created_at' => [
                '$lt' => new UTCDatetime($date->getTimestamp() * 1000),
                '$gte' => new UTCDatetime($date->modify('-1 minute')->getTimestamp() * 1000),

            ],
        ])->count();
        $data = json_encode([
            'value' => $countDecisionsPerMinute,
            'updated_at' => $date->format('Y-m-d H:i:s'),
            'id' => $date->getTimestamp(),
        ]);

        // POST to CachetHQ using raw cURL with the API token header
        $ch = curl_init();
        $headers = [
            'Content-Type: application/json',
            'X-Cachet-Token: ' . config('services.status.access_token'),
            'Content-Length: ' . strlen($data),
        ];
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_URL, config('services.status.decisions_per_minute_link'));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_exec($ch);
        curl_close($ch);
    }
}
