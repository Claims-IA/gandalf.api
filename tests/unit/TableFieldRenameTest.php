<?php
/**
 * TableFieldRenameTest
 *
 * Field key renames: detection on a table update (same field _id, another
 * key), conditions following the rename (TablesRepository), and the rewrite of
 * a flow's edges (FlowRepository::renameEdges). The stored table is built in
 * memory; private helpers are reached by reflection. Loading and saving the
 * flows needs MongoDB and is not covered here.
 */

use App\Models\Field;
use App\Models\Table;
use App\Repositories\FlowRepository;
use App\Repositories\TablesRepository;
use App\Services\ConditionsTypes;
use App\Validators\TableRulesProvider;

class TableFieldRenameTest extends \Codeception\TestCase\Test
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../bootstrap/app.php';
    }

    private function call($method, ...$args)
    {
        $reflection = new ReflectionMethod(TablesRepository::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(new TablesRepository(), ...$args);
    }

    /**
     * A stored table: fields by _id, one rule whose conditions are given as
     * condition _id => field key.
     */
    private function stored(array $fields, array $conditions = []): Table
    {
        $table = new Table();
        $table->setRawAttributes([
            'fields' => array_map(function ($id, $key) {
                return ['_id' => $id, 'key' => $key, 'title' => $key, 'type' => 'string'];
            }, array_keys($fields), $fields),
            'variants' => [['_id' => 'v1', 'title' => 'v1', 'rules' => [['_id' => 'r1', 'conditions' => array_map(function ($id, $key) {
                return ['_id' => $id, 'field_key' => $key, 'condition' => '$eq', 'value' => "value of $key"];
            }, array_keys($conditions), $conditions)]]]],
        ]);

        return $table;
    }

    /** One rule whose conditions are given as [condition _id or null, field key]. */
    private function variants(array $conditions): array
    {
        return [['rules' => [['conditions' => array_map(function ($c) {
            $condition = ['field_key' => $c[1], 'condition' => '$eq', 'value' => $c[2] ?? 'x'];
            if ($c[0] !== null) {
                $condition['_id'] = $c[0];
            }
            return $condition;
        }, $conditions)]]]];
    }

    private function keys(array $variants): array
    {
        return array_column($variants[0]['rules'][0]['conditions'], 'field_key');
    }

    // -------------------------------------------------------------------------
    // Detection
    // -------------------------------------------------------------------------

    public function testRenameIsDetectedByFieldId()
    {
        $renames = TablesRepository::fieldRenamesBetween([['_id' => 'id1', 'key' => 'cause'], ['_id' => 'id2', 'key' => 'montant']], [
            ['_id' => 'id1', 'key' => 'Cause Sinistre'],   // normalized like Field::setKeyAttribute
            ['_id' => 'id2', 'key' => 'montant'],
            ['_id' => 'id3', 'key' => 'nouveau'],           // added field, not a rename
            ['key' => 'sans_id'],
        ]);
        $this->assertSame(['cause' => 'cause_sinistre'], $renames);
    }

    public function testSwapIsDetected()
    {
        $renames = TablesRepository::fieldRenamesBetween([['_id' => 'id1', 'key' => 'a'], ['_id' => 'id2', 'key' => 'b']], [
            ['_id' => 'id1', 'key' => 'b'],
            ['_id' => 'id2', 'key' => 'a'],
        ]);
        $this->assertSame(['a' => 'b', 'b' => 'a'], $renames);
    }

    public function testDuplicatedFieldIdCountsItsLastOccurrence()
    {
        // As Table::setFields stores it (EmbedsMany replaces by _id).
        $renames = TablesRepository::fieldRenamesBetween([['_id' => 'id1', 'key' => 'a']], [
            ['_id' => 'id1', 'key' => 'a'],
            ['_id' => 'id1', 'key' => 'a_copie'],
        ]);
        $this->assertSame(['a' => 'a_copie'], $renames);
    }

    public function testDuplicatedFieldIdsAreRejectedByTheValidation()
    {
        $id = '5f0c6b2e8a1b2c3d4e5f6a7b';
        $field = ['_id' => $id, 'title' => 'A', 'type' => 'string', 'source' => 'request', 'preset' => []];
        $payload = [
            'matching_type' => 'first',
            'decision_type' => 'string',
            'fields' => [$field + ['key' => 'a'], $field + ['key' => 'a_copie']],
            'variants' => [['default_decision' => 'non', 'rules' => [['than' => 'oui', 'conditions' => [
                ['field_key' => 'a', 'condition' => '$any', 'value' => true],
                ['field_key' => 'a_copie', 'condition' => '$any', 'value' => true],
            ]]]]],
        ];
        $validator = \Validator::make($payload, TableRulesProvider::rules(new ConditionsTypes()));
        $this->assertTrue($validator->fails());
        $this->assertContains('fields', array_keys($validator->errors()->toArray()));

        $payload['fields'][1]['_id'] = '5f0c6b2e8a1b2c3d4e5f6a7c';
        $this->assertFalse(\Validator::make($payload, TableRulesProvider::rules(new ConditionsTypes()))->fails());

        // An object _id escapes the per-field mongoId rule (flattened away): caught here.
        $payload['fields'][1]['_id'] = ['$oid' => '5f0c6b2e8a1b2c3d4e5f6a7c'];
        $this->assertContains('fields', array_keys(\Validator::make($payload, TableRulesProvider::rules(new ConditionsTypes()))->errors()->toArray()));
    }

    // -------------------------------------------------------------------------
    // Conditions
    // -------------------------------------------------------------------------

    public function testUnchangedStoredConditionsFollowAChain()
    {
        // API client: a -> b and b -> c, conditions sent unchanged
        $stored = $this->stored(['id1' => 'a', 'id2' => 'b'], ['c1' => 'a', 'c2' => 'b']);
        $result = $this->call('renameConditions', $stored,
            $this->variants([['c1', 'a', 'va'], ['c2', 'b', 'vb']]),
            [['_id' => 'id1', 'key' => 'b'], ['_id' => 'id2', 'key' => 'c']],
            ['a' => 'b', 'b' => 'c']);

        $this->assertSame(['b', 'c'], $this->keys($result));
        $this->assertSame(['va', 'vb'], array_column($result[0]['rules'][0]['conditions'], 'value'));
    }

    public function testUnchangedStoredConditionsFollowASwap()
    {
        $stored = $this->stored(['id1' => 'a', 'id2' => 'b'], ['c1' => 'a', 'c2' => 'b']);
        $result = $this->call('renameConditions', $stored,
            $this->variants([['c1', 'a'], ['c2', 'b']]),
            [['_id' => 'id1', 'key' => 'b'], ['_id' => 'id2', 'key' => 'a']],
            ['a' => 'b', 'b' => 'a']);

        $this->assertSame(['b', 'a'], $this->keys($result));
    }

    public function testConditionsRenamedByTheEditorAreLeftAlone()
    {
        // The editor already swapped the conditions' keys: they must not be swapped back.
        $stored = $this->stored(['id1' => 'a', 'id2' => 'b'], ['c1' => 'a', 'c2' => 'b']);
        $variants = $this->variants([['c1', 'b'], ['c2', 'a']]);
        $result = $this->call('renameConditions', $stored, $variants,
            [['_id' => 'id1', 'key' => 'b'], ['_id' => 'id2', 'key' => 'a']],
            ['a' => 'b', 'b' => 'a']);

        $this->assertSame($variants, $result);
    }

    public function testNewConditionOnAnOldKeyFollows()
    {
        $stored = $this->stored(['id1' => 'cause']);
        $result = $this->call('renameConditions', $stored,
            $this->variants([[null, 'cause']]),
            [['_id' => 'id1', 'key' => 'cause_sinistre']],
            ['cause' => 'cause_sinistre']);

        $this->assertSame(['cause_sinistre'], $this->keys($result));
    }

    public function testRenamedConditionSurvivesAlignmentWithAnUnnormalizedKey()
    {
        // Submitted key "Cause Sinistre": the renamed condition must not be replaced by $any.
        $stored = $this->stored(['id1' => 'cause'], ['c1' => 'cause']);
        $fields = [['_id' => 'id1', 'key' => 'Cause Sinistre']];
        $renamed = $this->call('renameConditions', $stored, $this->variants([['c1', 'cause', 'robinet']]), $fields, ['cause' => 'cause_sinistre']);
        $aligned = $this->call('normalizeVariantConditions', $fields, $renamed);

        $condition = $aligned[0]['rules'][0]['conditions'][0];
        $this->assertSame(['$eq', 'robinet'], [$condition['condition'], $condition['value']]);
    }

    public function testStaleConditionOfARemovedFieldIsDropped()
    {
        // API client: field b removed and a renamed to b, conditions sent unchanged.
        // b's condition must not take the place of a's.
        $stored = $this->stored(['id2' => 'b', 'id1' => 'a'], ['c2' => 'b', 'c1' => 'a']);
        $fields = [['_id' => 'id1', 'key' => 'b']];
        $renamed = $this->call('renameConditions', $stored,
            $this->variants([['c2', 'b', 'from b'], ['c1', 'a', 'from a']]), $fields, ['a' => 'b']);
        $this->assertSame(['b'], $this->keys($renamed));

        $aligned = $this->call('normalizeVariantConditions', $fields, $renamed);
        $this->assertSame('from a', $aligned[0]['rules'][0]['conditions'][0]['value']);
    }

    public function testConditionWrittenOnTheNewKeyWins()
    {
        // a renamed to b, a new field a, and a condition written on b by the client:
        // that one is b's, the stored condition stays on a (now the new field).
        $stored = $this->stored(['id1' => 'a'], ['c1' => 'a']);
        $result = $this->call('renameConditions', $stored,
            $this->variants([['c1', 'a', 'stored'], [null, 'b', 'written']]),
            [['_id' => 'id1', 'key' => 'b'], ['key' => 'a']],
            ['a' => 'b']);

        $this->assertSame(['a', 'b'], $this->keys($result));
        $this->assertSame(['stored', 'written'], array_column($result[0]['rules'][0]['conditions'], 'value'));
    }

    public function testRenamingTwiceChangesNothing()
    {
        // TablesController::update renames before the validation, createOrUpdate again.
        $stored = $this->stored(['id1' => 'a', 'id2' => 'b', 'id3' => 'c'], ['c1' => 'a', 'c2' => 'b', 'c3' => 'c']);
        $values = [
            'fields' => [['_id' => 'id1', 'key' => 'b'], ['_id' => 'id2', 'key' => 'c']],
            'variants' => $this->variants([['c1', 'a'], ['c2', 'b'], ['c3', 'c'], [null, 'z']]),
        ];
        list($once, $renames) = $this->call('applyFieldRenames', $stored, $values);
        $this->assertSame(['a' => 'b', 'b' => 'c'], $renames);
        $this->assertSame(['b', 'c', 'z'], $this->keys($once['variants']));
        $this->assertSame($once, $this->call('applyFieldRenames', $stored, $once)[0]);
    }

    public function testMalformedPayloadIsLeftToTheValidation()
    {
        // Runs on the raw request body: no warning (a 500), entries left as they are.
        $stored = $this->stored(['id1' => 'a'], ['c1' => 'a']);
        $variants = ['x', ['rules' => 'x'], ['rules' => [5, ['conditions' => 5], ['conditions' => [
            7,
            ['field_key' => ['a']],
            ['_id' => ['x'], 'field_key' => 'a'],
        ]]]]];
        list($values, $renames) = $this->call('applyFieldRenames', $stored, [
            'fields' => [['_id' => ['$oid' => 'x'], 'key' => 'x'], ['_id' => 'id1', 'key' => ['x']], 'garbage', ['_id' => 'id1', 'key' => 'b']],
            'variants' => $variants,
        ]);
        $this->assertSame(['a' => 'b'], $renames);
        $this->assertSame('b', $values['variants'][2]['rules'][2]['conditions'][2]['field_key']);
        $this->assertSame(array_slice($variants, 0, 2), array_slice($values['variants'], 0, 2));

        $this->assertSame([['fields' => 'x'], []], $this->call('applyFieldRenames', $stored, ['fields' => 'x']));
    }

    public function testNeutralConditionCarriesAValue()
    {
        $aligned = $this->call('normalizeVariantConditions', [['key' => 'a'], ['key' => 'b']], $this->variants([['c1', 'a']]));
        $this->assertSame(['field_key' => 'b', 'condition' => '$any', 'value' => true], $aligned[0]['rules'][0]['conditions'][1]);
    }

    public function testKeyNormalization()
    {
        $this->assertSame('card_bin', Field::normalizeKey(' Card BIN '));
    }

    // -------------------------------------------------------------------------
    // Flow edges
    // -------------------------------------------------------------------------

    private function edge($from, $node, $field): array
    {
        $source = strpos($from, 'node:') === 0 ? ['node' => substr($from, 5), 'output' => 'final_decision'] : ['input' => $from];
        return ['from' => $source, 'into' => ['node' => $node, 'field' => $field]];
    }

    public function testWiredEdgeIsRetargeted()
    {
        $edges = FlowRepository::renameEdges([$this->edge('x', 'n1', 'cause'), $this->edge('y', 'n2', 'cause')], ['n1'], [], ['cause' => 'cause_sinistre']);
        // n2 uses another table: untouched
        $this->assertSame([$this->edge('x', 'n1', 'cause_sinistre'), $this->edge('y', 'n2', 'cause')], $edges);
    }

    public function testImplicitInputFeedBecomesAnExplicitEdge()
    {
        $edges = FlowRepository::renameEdges([$this->edge('montant', 'n1', 'montant')], ['n1'], ['cause' => 'string', 'montant' => 'numeric'], ['cause' => 'cause_sinistre']);
        $this->assertSame([$this->edge('montant', 'n1', 'montant'), $this->edge('cause', 'n1', 'cause_sinistre')], $edges);
    }

    public function testSwapRetargetsBothEdges()
    {
        $edges = FlowRepository::renameEdges([$this->edge('p', 's1', 'a'), $this->edge('q', 's1', 'b')], ['s1'], [], ['a' => 'b', 'b' => 'a']);
        $this->assertSame([$this->edge('p', 's1', 'b'), $this->edge('q', 's1', 'a')], $edges);
    }

    public function testStaleEdgeIntoATakenKeyIsDropped()
    {
        // Field b removed and a renamed to b in the same update: the edge into the old b goes.
        $edges = FlowRepository::renameEdges([$this->edge('p', 's1', 'a'), $this->edge('node:up', 's1', 'b')], ['s1'], [], ['a' => 'b']);
        $this->assertSame([$this->edge('p', 's1', 'b')], $edges);
    }

    public function testEdgeNoRenamedFieldReplacesIsKept()
    {
        // A flow left on "cause" (save failed, or older than renames): renaming
        // cause_sinistre back to cause makes its edge valid again, it stays.
        $this->assertNull(FlowRepository::renameEdges([$this->edge('x', 'n1', 'cause')], ['n1'], [], ['cause_sinistre' => 'cause']));
    }

    public function testIntegerNodeIdsAreMatched()
    {
        $edges = FlowRepository::renameEdges([
            ['from' => ['input' => 'p'], 'into' => ['node' => 1, 'field' => 'a']],
            ['from' => ['input' => 'q'], 'into' => ['node' => 1, 'field' => 'b']],
        ], ['1'], [], ['a' => 'b']);
        $this->assertSame([['from' => ['input' => 'p'], 'into' => ['node' => 1, 'field' => 'b']]], $edges);
    }

    public function testNumericIdsAndKeysStayStrings()
    {
        $edges = FlowRepository::renameEdges([], ['1'], ['2024' => 'string'], ['2024' => 'y2024']);
        $this->assertSame([['from' => ['input' => '2024'], 'into' => ['node' => '1', 'field' => 'y2024']]], $edges);
    }

    public function testNothingToChangeReturnsNull()
    {
        $this->assertNull(FlowRepository::renameEdges([$this->edge('x', 'n1', 'other')], ['n1'], [], ['cause' => 'cause_sinistre']));
    }
}
