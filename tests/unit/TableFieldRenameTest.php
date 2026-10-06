<?php
/**
 * TableFieldRenameTest
 *
 * Detection of renamed fields on a table update (same _id, another key) and
 * the rewrite of conditions still using an old key (TablesRepository). The
 * stored table is built in memory; private helpers are reached by reflection.
 * Flows following a rename need MongoDB and are covered by integration.
 */

use App\Models\Field;
use App\Models\Table;
use App\Repositories\TablesRepository;

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

    private function stored(array $fields): Table
    {
        $table = new Table();
        $table->setRawAttributes(['fields' => array_map(function ($id, $key) {
            return ['_id' => $id, 'key' => $key, 'title' => $key, 'type' => 'string'];
        }, array_keys($fields), $fields)]);

        return $table;
    }

    public function testRenameIsDetectedByFieldId()
    {
        $renames = $this->call('fieldRenames', $this->stored(['id1' => 'cause', 'id2' => 'montant']), [
            ['_id' => 'id1', 'key' => 'Cause Sinistre'],   // normalized like Field::setKeyAttribute
            ['_id' => 'id2', 'key' => 'montant'],
            ['_id' => 'id3', 'key' => 'nouveau'],           // added field, not a rename
            ['key' => 'sans_id'],
        ]);
        $this->assertSame(['cause' => 'cause_sinistre'], $renames);
    }

    public function testOrphanConditionsFollowTheRename()
    {
        $variants = [['rules' => [['conditions' => [
            ['field_key' => 'cause', 'condition' => '$eq', 'value' => 'robinet'],
            ['field_key' => 'montant', 'condition' => '$gt', 'value' => 10],
        ]]]]];
        $result = $this->call('renameOrphanConditions', $variants,
            [['key' => 'cause_sinistre'], ['key' => 'montant']], ['cause' => 'cause_sinistre']);

        $this->assertSame('cause_sinistre', $result[0]['rules'][0]['conditions'][0]['field_key']);
        $this->assertSame('montant', $result[0]['rules'][0]['conditions'][1]['field_key']);
        $this->assertSame('robinet', $result[0]['rules'][0]['conditions'][0]['value']);
    }

    public function testAlreadyRenamedConditionsAreLeftAlone()
    {
        // The editor renames conditions itself; a swap a <-> b must not be undone.
        $variants = [['rules' => [['conditions' => [
            ['field_key' => 'b', 'condition' => '$eq', 'value' => 'was a'],
            ['field_key' => 'a', 'condition' => '$eq', 'value' => 'was b'],
        ]]]]];
        $result = $this->call('renameOrphanConditions', $variants,
            [['key' => 'b'], ['key' => 'a']], ['a' => 'b', 'b' => 'a']);

        $this->assertSame($variants, $result);
    }

    public function testKeyNormalization()
    {
        $this->assertSame('card_bin', Field::normalizeKey(' Card BIN '));
    }
}
