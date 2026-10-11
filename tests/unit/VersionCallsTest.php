<?php
/**
 * VersionCallsTest
 *
 * Table versions, batch 3 (VERSIONS.md), without a database:
 * - the keys of an application and the call window that VersionCalls counts;
 * - input errors of the route v2 under `data.*`;
 * - the Deprecation and Sunset headers of the route v1.
 */

use App\Http\Controllers\ConsumerController;
use App\Services\Scoring;
use App\Services\VersionCalls;
use Nebo15\LumenApplicationable\Models\Application;

class VersionCallsTest extends \Codeception\TestCase\Test
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../bootstrap/app.php';
    }

    protected function tearDown(): void
    {
        putenv('TABLE_VERSIONS_CALL_WINDOW_DAYS');
        parent::tearDown();
    }

    public function testTheKeysOfAnApplicationAreItsConsumers()
    {
        $application = new Application;
        $application->setConsumer(['client_id' => 'key-a', 'client_secret' => 's', 'description' => 'Website', 'scope' => []]);
        $application->setConsumer(['client_id' => 'key-b', 'client_secret' => 's', 'scope' => []]);

        $this->assertSame(['key-a' => 'Website', 'key-b' => ''], VersionCalls::keysOf($application));
        $this->assertSame([], VersionCalls::keysOf(new Application));
    }

    public function testTheCallWindowDefaultsTo30Days()
    {
        $this->assertSame(30, VersionCalls::windowDays());
        putenv('TABLE_VERSIONS_CALL_WINDOW_DAYS=7');
        $this->assertSame(7, VersionCalls::windowDays());
        putenv('TABLE_VERSIONS_CALL_WINDOW_DAYS=0');
        $this->assertSame(30, VersionCalls::windowDays(), 'A window of 0 days would let any version go');
    }

    public function testRouteV2InputErrorsAreUnderData()
    {
        $rules = Scoring::prefixRules(['age' => 'present|numeric', 'name' => 'present|string'], 'data');
        $this->assertSame(['data.age' => 'present|numeric', 'data.name' => 'present|string'], $rules);

        $validator = \Validator::make(['data' => ['age' => 'old']], $rules);
        $this->assertTrue($validator->fails());
        $this->assertSame(['data.age', 'data.name'], array_keys($validator->errors()->toArray()));

        $this->assertFalse(\Validator::make(['data' => ['age' => null, 'name' => 'Ann']], $rules)->fails(), 'Null is present');
    }

    public function testRouteV1AnnouncesItsEndOnlyOnceDated()
    {
        $this->assertSame([], ConsumerController::v1DeprecationHeaders('abc', null, null));
        $this->assertSame([], ConsumerController::v1DeprecationHeaders('abc', 'not a date', ''));

        $headers = ConsumerController::v1DeprecationHeaders('abc', '2026-11-01', '2027-06-30');
        $this->assertSame('@1793491200', $headers['Deprecation']);
        $this->assertSame('</api/v2/tables/abc/decisions>; rel="successor-version"', $headers['Link']);
        $this->assertSame('Wed, 30 Jun 2027 00:00:00 GMT', $headers['Sunset']);
    }
}
