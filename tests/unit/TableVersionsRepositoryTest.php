<?php
/**
 * TableVersionsRepositoryTest
 *
 * Pure rules of the table version lifecycle (VERSIONS.md, batch 2), without a
 * database:
 * - a split change applies new percentages and reports unknown variants;
 * - a version "descends from" the active one when the active version is in its
 *   chain of origins, which decides the divergence warning.
 */

use App\Repositories\TableVersionsRepository;
use MongoDB\BSON\ObjectID;

class TableVersionsRepositoryTest extends \Codeception\TestCase\Test
{
    const A = '5f0000000000000000000001';
    const B = '5f0000000000000000000002';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../bootstrap/app.php';
    }

    public function testASplitChangeAppliesTheNewPercentages()
    {
        $stored = [
            ['_id' => new ObjectID(self::A), 'title' => 'A', 'probability' => 50],
            ['_id' => self::B, 'title' => 'B', 'probability' => 50],
        ];

        list($merged, $unknown) = TableVersionsRepository::mergeSplit($stored, [
            ['_id' => self::A, 'probability' => 100],
            ['_id' => self::B, 'probability' => '0'],
        ]);

        $this->assertSame([], $unknown);
        $this->assertSame(100, $merged[0]['probability']);
        $this->assertSame(0, $merged[1]['probability']);
        $this->assertSame('A', $merged[0]['title']);
    }

    public function testASplitChangeKeepsUnlistedVariantsAndReportsUnknownOnes()
    {
        $stored = [
            ['_id' => self::A, 'probability' => 70],
            ['_id' => self::B, 'probability' => 30],
        ];

        list($merged, $unknown) = TableVersionsRepository::mergeSplit($stored, [
            ['_id' => self::A, 'probability' => 60],
            ['_id' => '5f0000000000000000000099', 'probability' => 40],
        ]);

        $this->assertSame(['5f0000000000000000000099'], $unknown);
        $this->assertSame(60, $merged[0]['probability']);
        $this->assertSame(30, $merged[1]['probability']);
    }

    private function origins(array $chain)
    {
        return function ($id) use ($chain) {
            return $chain[$id] ?? null;
        };
    }

    public function testAVersionBranchedFromTheActiveOneDescendsFromIt()
    {
        // v4 (active) <- draft <- v5
        $origins = $this->origins(['v5' => 'draft', 'draft' => 'v4', 'v4' => 'v3']);

        $this->assertTrue(TableVersionsRepository::descendsFrom('v5', 'v4', $origins));
        $this->assertTrue(TableVersionsRepository::descendsFrom('v4', 'v4', $origins), 'The active version itself');
    }

    public function testAnOlderVersionDoesNotDescendFromTheActiveOne()
    {
        // v3 <- v4 <- v5 (active): rolling back to v3 drops v4 and v5's changes.
        $origins = $this->origins(['v5' => 'v4', 'v4' => 'v3', 'v3' => null]);

        $this->assertFalse(TableVersionsRepository::descendsFrom('v3', 'v5', $origins));
    }

    public function testAVersionFromAnotherBranchDoesNotDescendFromTheActiveOne()
    {
        // v3 <- v4 (active); v3 <- draft "B" (branched before v4)
        $origins = $this->origins(['v4' => 'v3', 'B' => 'v3']);

        $this->assertFalse(TableVersionsRepository::descendsFrom('B', 'v4', $origins));
    }

    public function testACycleInOriginsEnds()
    {
        $origins = $this->origins(['x' => 'y', 'y' => 'x']);

        $this->assertFalse(TableVersionsRepository::descendsFrom('x', 'v1', $origins));
    }
}
