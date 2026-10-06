<?php
/**
 * FlowTableReuseTest
 *
 * Rules deciding whether a table already present in the target project can
 * stand in for a flow's table on copy or move (FlowRepository::canStandIn).
 * Tables are built in memory; the private method is reached by reflection.
 * The database part (candidate lookup by origin) is covered by integration.
 */

use App\Models\Table;
use App\Repositories\FlowRepository;

class FlowTableReuseTest extends \Codeception\TestCase\Test
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../bootstrap/app.php';
    }

    private function table(array $fields, $matching = 'first', $decision = 'string'): Table
    {
        $table = new Table();
        $table->setRawAttributes([
            'matching_type' => $matching,
            'decision_type' => $decision,
            'fields' => array_map(function ($key, $type) {
                return ['_id' => (string) new MongoDB\BSON\ObjectID(), 'key' => $key, 'title' => $key, 'type' => $type];
            }, array_keys($fields), $fields),
        ]);

        return $table;
    }

    /**
     * canStandIn for node n1, with the source table's fields and output family.
     */
    private function canStandIn(Table $candidate, array $sourceFields, array $edges, array $inputTypes, $sourceOutput = 'string'): bool
    {
        $method = new ReflectionMethod(FlowRepository::class, 'canStandIn');
        $method->setAccessible(true);
        $family = $sourceOutput === 'numeric' ? 'numeric' : 'text';

        return $method->invoke(new FlowRepository(), $candidate, $sourceFields, $family, ['n1'], $edges, $inputTypes);
    }

    private function wire($field)
    {
        return ['from' => ['input' => $field], 'into' => ['node' => 'n1', 'field' => $field]];
    }

    public function testSameShapeIsReused()
    {
        $this->assertTrue($this->canStandIn(
            $this->table(['age' => 'numeric', 'country' => 'string']),
            ['age' => 'numeric', 'country' => 'string'],
            [$this->wire('age')],
            ['age' => 'numeric', 'country' => 'string']
        ));
    }

    public function testWiredFieldTypeChangeIsRejected()
    {
        $this->assertFalse($this->canStandIn(
            $this->table(['age' => 'string']),
            ['age' => 'numeric'],
            [$this->wire('age')],
            ['age' => 'numeric']
        ));
    }

    public function testMissingWiredFieldIsRejected()
    {
        $this->assertFalse($this->canStandIn(
            $this->table(['country' => 'string']),
            ['age' => 'numeric', 'country' => 'string'],
            [$this->wire('age')],
            ['age' => 'numeric', 'country' => 'string']
        ));
    }

    public function testExtraFieldWithoutInputIsRejected()
    {
        $this->assertFalse($this->canStandIn(
            $this->table(['age' => 'numeric', 'extra' => 'string']),
            ['age' => 'numeric'],
            [$this->wire('age')],
            ['age' => 'numeric']
        ));
    }

    public function testInputFedFieldMustAcceptTheInputType()
    {
        // 'country' is not wired: it is fed by the same-named flow input.
        $candidate = $this->table(['age' => 'numeric', 'country' => 'numeric']);
        $this->assertFalse($this->canStandIn(
            $candidate,
            ['age' => 'numeric', 'country' => 'string'],
            [$this->wire('age')],
            ['age' => 'numeric', 'country' => 'string']
        ));
    }

    public function testOutputFamilyMattersOnlyWhenWiredDownstream()
    {
        $scoring = $this->table(['age' => 'numeric'], 'scoring_sum', 'numeric');
        $downstream = [$this->wire('age'), ['from' => ['node' => 'n1', 'output' => 'final_decision'], 'into' => ['node' => 'n2', 'field' => 'score']]];

        // Source outputs text, candidate outputs a number: rejected when n1 feeds n2...
        $this->assertFalse($this->canStandIn($scoring, ['age' => 'numeric'], $downstream, ['age' => 'numeric'], 'string'));
        // ...accepted when n1's output is not wired to another node.
        $this->assertTrue($this->canStandIn($scoring, ['age' => 'numeric'], [$this->wire('age')], ['age' => 'numeric'], 'string'));
    }

    public function testIntegerNodeIdsAreChecked()
    {
        // nodesUsingTable gives '1'; a flow stored through the API may use node 1.
        $method = new ReflectionMethod(FlowRepository::class, 'canStandIn');
        $method->setAccessible(true);
        $scoring = $this->table(['age' => 'numeric'], 'scoring_sum', 'numeric');
        $edges = [
            ['from' => ['input' => 'age'], 'into' => ['node' => 1, 'field' => 'age']],
            ['from' => ['node' => 1, 'output' => 'final_decision'], 'into' => ['node' => 2, 'field' => 'score']],
        ];

        $this->assertFalse($method->invoke(new FlowRepository(), $scoring, ['age' => 'numeric'], 'text', ['1'], $edges, ['age' => 'numeric']));
    }
}
