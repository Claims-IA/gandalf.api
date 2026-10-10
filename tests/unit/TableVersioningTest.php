<?php
/**
 * TableVersioningTest
 *
 * Table versions, release A (VERSIONS.md), without a database:
 * - TableVersioning::contentHash decides whether a table save publishes a new
 *   version, so it must ignore what every save restamps and catch any real
 *   content change;
 * - a TableVersion runs like a table (HasTableContent);
 * - a decision exposes the version it ran.
 * Publishing and activating against MongoDB are covered by the API suite.
 */

use App\Models\Decision;
use App\Models\TableVersion;
use App\Services\TableVersioning;
use App\Exceptions\VariantNotFound;
use MongoDB\BSON\ObjectID;
use MongoDB\BSON\UTCDateTime;

class TableVersioningTest extends \Codeception\TestCase\Test
{
    const VARIANT_A = '5f0000000000000000000001';
    const VARIANT_B = '5f0000000000000000000002';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../bootstrap/app.php';
    }

    private function content(array $override = []): array
    {
        return array_replace_recursive([
            'fields' => [
                ['_id' => '5f00000000000000000000f1', 'key' => 'age', 'title' => 'Age', 'type' => 'numeric'],
            ],
            'variants_probability' => 'first',
            'variants' => [
                [
                    '_id' => self::VARIANT_A,
                    'title' => 'A',
                    'default_decision' => 'NON',
                    'probability' => 100,
                    'created_at' => new UTCDateTime(1000),
                    'updated_by' => 'u1',
                    'rules' => [
                        [
                            '_id' => '5f00000000000000000000a1',
                            'than' => 'OUI',
                            'conditions' => [
                                ['_id' => '5f00000000000000000000c1', 'field_key' => 'age', 'condition' => '$gte', 'value' => 18],
                                ['_id' => '5f00000000000000000000c2', 'field_key' => 'age', 'condition' => '$lt', 'value' => 65],
                            ],
                        ],
                    ],
                ],
            ],
        ], $override);
    }

    public function testRestampedAuditKeysMakeNoDifference()
    {
        $saved = $this->content();
        $resaved = $this->content(['variants' => [0 => [
            'created_at' => new UTCDateTime(2000),
            'updated_at' => new UTCDateTime(3000),
            'updated_by' => 'u2',
        ]]]);

        $this->assertSame(TableVersioning::contentHash($saved), TableVersioning::contentHash($resaved));
    }

    public function testKeyOrderAndIdTypesMakeNoDifference()
    {
        $saved = $this->content();
        $variant = $saved['variants'][0];
        $reordered = $saved;
        $reordered['variants'][0] = array_reverse($variant, true);
        $reordered['variants'][0]['_id'] = new ObjectID(self::VARIANT_A);

        $this->assertSame(TableVersioning::contentHash($saved), TableVersioning::contentHash($reordered));
    }

    public function testRuleChangeIsAContentChange()
    {
        $changed = $this->content(['variants' => [0 => ['rules' => [0 => ['than' => 'PEUT-ETRE']]]]]);

        $this->assertNotSame(TableVersioning::contentHash($this->content()), TableVersioning::contentHash($changed));
    }

    public function testConditionOrderIsAContentChange()
    {
        $saved = $this->content();
        $swapped = $saved;
        $swapped['variants'][0]['rules'][0]['conditions'] = array_reverse($saved['variants'][0]['rules'][0]['conditions']);

        $this->assertNotSame(TableVersioning::contentHash($saved), TableVersioning::contentHash($swapped));
    }

    public function testSplitModeIsAContentChange()
    {
        $this->assertNotSame(
            TableVersioning::contentHash($this->content()),
            TableVersioning::contentHash($this->content(['variants_probability' => 'random']))
        );
    }

    public function testAMissingKeyCountsAsItsModelDefault()
    {
        $old = $this->content();
        $saved = $this->content(['fields' => [0 => ['preset' => null]], 'variants' => [0 => [
            'description' => '', 'default_title' => '', 'default_description' => '', 'is_default' => false,
        ]]]);
        unset($old['variants'][0]['probability']);
        $saved['variants'][0]['probability'] = 0;

        $this->assertSame(TableVersioning::contentHash($old), TableVersioning::contentHash($saved));
    }

    public function testAValueOtherThanTheDefaultIsAContentChange()
    {
        $old = $this->content();
        unset($old['variants'][0]['probability']);

        $this->assertNotSame(TableVersioning::contentHash($old), TableVersioning::contentHash($this->content()));
    }

    public function testFalsyValuesAreKept()
    {
        $zero = $this->content(['variants' => [0 => ['rules' => [0 => ['than' => 0]]]]]);
        $empty = $this->content(['variants' => [0 => ['rules' => [0 => ['than' => '']]]]]);
        $null = $this->content(['variants' => [0 => ['rules' => [0 => ['than' => null]]]]]);

        $hashes = array_map([TableVersioning::class, 'contentHash'], [$zero, $empty, $null]);
        $this->assertCount(3, array_unique($hashes));
    }

    private function version(array $variants, $split = 'first'): TableVersion
    {
        $version = new TableVersion();
        $version->setRawAttributes([
            'status' => TableVersion::STATUS_PUBLISHED,
            'number' => 2,
            'variants_probability' => $split,
            'fields' => [],
            'variants' => $variants,
        ]);

        return $version;
    }

    public function testAVersionPicksItsVariantLikeATable()
    {
        $version = $this->version([
            ['_id' => self::VARIANT_A, 'title' => 'A', 'rules' => []],
            ['_id' => self::VARIANT_B, 'title' => 'B', 'rules' => []],
        ]);

        $this->assertSame('A', $version->getVariantForCheck()->title);
        $this->assertSame('B', $version->getVariantForCheck(self::VARIANT_B)->title);
        $this->assertTrue($version->isPublished());
    }

    public function testAVersionRejectsAnUnknownVariant()
    {
        $version = $this->version([['_id' => self::VARIANT_A, 'title' => 'A', 'rules' => []]]);
        try {
            $version->getVariantForCheck('5f0000000000000000000099');
            $this->fail('An unknown variant must not run.');
        } catch (VariantNotFound $e) {
            $this->assertInstanceOf(VariantNotFound::class, $e);
        }
    }

    public function testADecisionExposesItsVersion()
    {
        $versionId = new ObjectID();
        $decision = new Decision();
        $decision->setRawAttributes(['table' => [
            '_id' => new ObjectID(),
            'version' => ['_id' => $versionId, 'number' => 3],
            'variant' => ['_id' => new ObjectID(self::VARIANT_A), 'title' => 'A'],
        ]]);

        $table = $decision->getTableArray();
        $this->assertSame((string) $versionId, $table['version']['_id']);
        $this->assertSame(3, $table['version']['number']);
    }

    public function testADecisionMadeBeforeVersioningHasNoVersion()
    {
        $decision = new Decision();
        $decision->setRawAttributes(['table' => [
            '_id' => new ObjectID(),
            'version' => null,
            'variant' => ['_id' => new ObjectID(self::VARIANT_A), 'title' => 'A'],
        ]]);

        $this->assertNull($decision->getTableArray()['version']);

        $legacy = new Decision();
        $legacy->setRawAttributes(['table' => [
            '_id' => new ObjectID(),
            'variant' => ['_id' => new ObjectID(self::VARIANT_A), 'title' => 'A'],
        ]]);
        $this->assertArrayNotHasKey('version', $legacy->getTableArray());
    }
}
