<?php
/**
 * FlowVersioningTest
 *
 * Flow versions (VERSIONS.md, batch 4), without a database:
 * - the graph fingerprint, pins included, which decides whether a flow save
 *   publishes a version in compatibility mode;
 * - pins kept from the current version when a node comes without one (the
 *   current flow editor does not know them).
 */

use App\Models\FlowVersion;
use App\Services\FlowVersioning;

class FlowVersioningTest extends \Codeception\TestCase\Test
{
    const TABLE = '5f0000000000000000000001';
    const V1 = '5f00000000000000000000a1';
    const V2 = '5f00000000000000000000a2';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../bootstrap/app.php';
    }

    private function graph(array $nodes)
    {
        return [
            'inputs' => [['key' => 'age', 'type' => 'numeric']],
            'outputs' => [['name' => 'decision', 'from_node' => 'n1', 'from_output' => 'final_decision']],
            'nodes' => $nodes,
            'edges' => [],
        ];
    }

    private function version(array $nodes)
    {
        $version = new FlowVersion;
        $version->setAttribute('nodes', $nodes);

        return $version;
    }

    public function testThePinIsPartOfTheGraph()
    {
        $v1 = $this->graph([['node_id' => 'n1', 'table_id' => self::TABLE, 'table_version_id' => self::V1]]);
        $v2 = $this->graph([['node_id' => 'n1', 'table_id' => self::TABLE, 'table_version_id' => self::V2]]);

        $this->assertNotSame(FlowVersioning::contentHash($v1), FlowVersioning::contentHash($v2));
    }

    public function testKeyOrderIsNotAChange()
    {
        $a = $this->graph([['node_id' => 'n1', 'table_id' => self::TABLE, 'table_version_id' => self::V1]]);
        $b = $this->graph([['table_version_id' => self::V1, 'table_id' => self::TABLE, 'node_id' => 'n1']]);

        $this->assertSame(FlowVersioning::contentHash($a), FlowVersioning::contentHash($b));
    }

    public function testANodeWithoutAPinKeepsItsCurrentOne()
    {
        $current = $this->version([['node_id' => 'n1', 'table_id' => self::TABLE, 'table_version_id' => self::V1]]);

        $nodes = (new FlowVersioning)->pinNodes([['node_id' => 'n1', 'table_id' => self::TABLE]], $current);

        $this->assertSame(self::V1, $nodes[0]['table_version_id']);
    }

    public function testAnExplicitPinWins()
    {
        $current = $this->version([['node_id' => 'n1', 'table_id' => self::TABLE, 'table_version_id' => self::V1]]);

        $nodes = (new FlowVersioning)->pinNodes(
            [['node_id' => 'n1', 'table_id' => self::TABLE, 'table_version_id' => self::V2]],
            $current
        );

        $this->assertSame(self::V2, $nodes[0]['table_version_id']);
    }

    public function testThePinOfAnotherTableIsNotKept()
    {
        // n1 now uses another table: its pin belonged to the previous table.
        $current = $this->version([['node_id' => 'n1', 'table_id' => self::TABLE, 'table_version_id' => self::V1]]);

        $nodes = (new FlowVersioning)->pinNodes([['node_id' => 'n1', 'table_id' => null]], $current);

        $this->assertNull($nodes[0]['table_version_id']);
    }
}
