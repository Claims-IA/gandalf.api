<?php
/**
 * ConditionCellCodecTest
 *
 * Unit tests for the Excel condition-cell grammar. The key invariant is the
 * round-trip guarantee: decode(encode($op, $value)) === [$op, $value] for
 * every operator and every stored value, including values that look like
 * operators themselves (quoting escape hatch).
 */

use App\Services\Excel\ConditionCellCodec;
use App\Exceptions\ConditionCellParseException;

class ConditionCellCodecTest extends \Codeception\TestCase\Test
{
    private ConditionCellCodec $codec;

    protected function _before()
    {
        $this->codec = new ConditionCellCodec();
    }

    // -------------------------------------------------------------------------
    // Decode: canonical + alternate spellings
    // -------------------------------------------------------------------------

    public function decodeProvider(): array
    {
        return [
            // cell, expected operator, expected value
            'empty = any'          => ['', '$any', true],
            'star'                 => ['*', '$any', true],
            'dashes'               => ['---', '$any', true],
            'any keyword'          => ['any', '$any', true],
            'ANY case-insensitive' => ['ANY', '$any', true],
            'plus = is_set'        => ['+', '$is_set', true],
            'set'                  => ['set', '$is_set', true],
            'is_set'               => ['is_set', '$is_set', true],
            'is set'               => ['is set', '$is_set', true],
            'null'                 => ['null', '$is_null', true],
            'is null'              => ['is null', '$is_null', true],
            'is_null'              => ['is_null', '$is_null', true],
            'bare eq'              => ['Lyon', '$eq', 'Lyon'],
            'explicit eq'          => ['= Lyon', '$eq', 'Lyon'],
            'double eq'            => ['== 42', '$eq', '42'],
            'bare number'          => ['42', '$eq', '42'],
            'ne'                   => ['!= FR', '$ne', 'FR'],
            'ne diamond'           => ['<> FR', '$ne', 'FR'],
            'gt'                   => ['> 21', '$gt', '21'],
            'gte'                  => ['>= 21', '$gte', '21'],
            'lt'                   => ['< 21', '$lt', '21'],
            'lte'                  => ['<= 21', '$lte', '21'],
            'gte no space'         => ['>=21', '$gte', '21'],
            'gte decimal comma'    => ['>= 0,5', '$gte', '0.5'],
            'between'              => ['[18..25]', '$between', '18;25'],
            'between spaces'       => ['[ 18 .. 25 ]', '$between', '18;25'],
            'between excl'         => [']18..25[', '$between_excl', '18;25'],
            'between lexcl'        => [']18..25]', '$between_lexcl', '18;25'],
            'between rexcl'        => ['[18..25[', '$between_rexcl', '18;25'],
            'between comma bound'  => ['[0,5..1,5]', '$between', '0.5;1.5'],
            'not between'          => ['not [18..25]', '$not_between', '18;25'],
            'not between bang'     => ['![18..25]', '$not_between', '18;25'],
            'not between caps'     => ['NOT [1..2]', '$not_between', '1;2'],
            'in'                   => ['in: FR, BE', '$in', 'FR, BE'],
            'in quoted token'      => ["in: 'a, b', c", '$in', "'a, b', c"],
            'nin'                  => ['not in: visa', '$nin', 'visa'],
            'nin short'            => ['nin: visa', '$nin', 'visa'],
            'nin bang'             => ['!in: visa', '$nin', 'visa'],
            'contains'             => ['contains: bmw', '$contains', 'bmw'],
            'contains tilde'       => ['~ bmw', '$contains', 'bmw'],
            'not contains'         => ['not contains: bmw', '$not_contains', 'bmw'],
            'not contains bang'    => ['!contains: bmw', '$not_contains', 'bmw'],
            'not contains tilde'   => ['!~ bmw', '$not_contains', 'bmw'],
            'starts'               => ['starts: FR', '$starts_with', 'FR'],
            'starts with'          => ['starts with: FR', '$starts_with', 'FR'],
            'ends'                 => ['ends: 75', '$ends_with', '75'],
            'ends with'            => ['ends with: 75', '$ends_with', '75'],
            'quoted literal op'    => ["'>= 3'", '$eq', '>= 3'],
            // Quoted values after prefix operators carry exact literals
            'ne quoted spaces'     => ["!= ' x '", '$ne', ' x '],
            'contains quoted empty' => ["contains: ''", '$contains', ''],
            'in quoted whole'      => ["in: '''visa'''", '$in', "'visa'"],
            'quoted star'          => ["'*'", '$eq', '*'],
            'quoted plus'          => ["'+'", '$eq', '+'],
            'quoted null'          => ["'null'", '$eq', 'null'],
            'quoted dashes'        => ["'---'", '$eq', '---'],
            'quoted in'            => ["'in: x'", '$eq', 'in: x'],
            'quoted with escape'   => ["'it''s ok'", '$eq', "it's ok"],
            'quoted spaces kept'   => ["' x '", '$eq', ' x '],
            // "not" followed by a non-interval falls back to bare $eq
            'not word bare eq'     => ['not applicable', '$eq', 'not applicable'],
        ];
    }

    /**
     * @dataProvider decodeProvider
     */
    public function testDecode(string $cell, string $expectedOp, $expectedValue)
    {
        $result = $this->codec->decode($cell);
        $this->assertSame($expectedOp, $result['condition'], "operator for cell \"$cell\"");
        $this->assertSame($expectedValue, $result['value'], "value for cell \"$cell\"");
    }

    // -------------------------------------------------------------------------
    // Decode: rejections with hints
    // -------------------------------------------------------------------------

    public function rejectProvider(): array
    {
        return [
            'arrow typo gte'     => ['=>21', '>='],
            'arrow typo lte'     => ['=<21', '<='],
            'malformed interval' => ['[18..', 'Intervalle'],
            'non numeric bound'  => ['[a..b]', 'numérique'],
            'non numeric gt'     => ['> abc', 'numérique'],
            'empty in value'     => ['in:', 'attend une valeur'],
            'not between excl'   => ['not ]1..2[', 'bornes incluses'],
            'unbalanced quote'   => ["'a'b'", 'apostrophes'],
        ];
    }

    /**
     * @dataProvider rejectProvider
     */
    public function testDecodeRejects(string $cell, string $messageFragment)
    {
        try {
            $this->codec->decode($cell);
            $this->fail("Cell \"$cell\" should have been rejected");
        } catch (ConditionCellParseException $e) {
            // PHPUnit 4: assertContains works on strings (assertStringContainsString doesn't exist yet)
            $this->assertContains($messageFragment, $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Encode: canonical serialization
    // -------------------------------------------------------------------------

    public function encodeProvider(): array
    {
        return [
            // operator, stored value, expected cell
            ['$any', true, '*'],
            ['$any', '*', '*'],
            ['$is_set', true, '+'],
            ['$is_null', true, 'null'],
            ['$eq', 'Lyon', 'Lyon'],
            ['$eq', '42', '42'],
            ['$eq', true, 'true'],
            ['$eq', false, 'false'],
            ['$ne', 'FR', '!= FR'],
            ['$gt', '21', '> 21'],
            ['$gte', 21, '>= 21'],
            ['$lt', '21', '< 21'],
            ['$lte', '21', '<= 21'],
            ['$between', '18;25', '[18..25]'],
            ['$between_excl', '18;25', ']18..25['],
            ['$between_lexcl', '18;25', ']18..25]'],
            ['$between_rexcl', '18;25', '[18..25['],
            ['$not_between', '18;25', 'not [18..25]'],
            ['$in', 'FR, BE', 'in: FR, BE'],
            ['$nin', 'visa', 'not in: visa'],
            ['$contains', 'bmw', 'contains: bmw'],
            ['$not_contains', 'bmw', 'not contains: bmw'],
            ['$starts_with', 'FR', 'starts: FR'],
            ['$ends_with', '75', 'ends: 75'],
            // $eq values that must be quoted to survive round-trip
            ['$eq', '>= 3', "'>= 3'"],
            ['$eq', '*', "'*'"],
            ['$eq', '+', "'+'"],
            ['$eq', 'null', "'null'"],
            ['$eq', 'NULL', "'NULL'"],
            ['$eq', '---', "'---'"],
            ['$eq', 'set', "'set'"],
            ['$eq', 'any', "'any'"],
            ['$eq', 'in: x', "'in: x'"],
            // An inner apostrophe alone is unambiguous: the cell is not fully
            // quoted, so the bare form round-trips and no quoting is needed.
            ['$eq', "it's ok", "it's ok"],
            // A fully-quoted-looking value must be wrapped and escaped, else
            // decode would strip the quotes.
            ['$eq', "'quoted'", "'''quoted'''"],
            ['$eq', ' x ', "' x '"],
            ['$eq', '[1..2]', "'[1..2]'"],
            ['$eq', '', "''"],
        ];
    }

    /**
     * @dataProvider encodeProvider
     */
    public function testEncode(string $operator, $value, string $expectedCell)
    {
        $this->assertSame($expectedCell, $this->codec->encode($operator, $value));
    }

    // -------------------------------------------------------------------------
    // Round-trip invariant: decode(encode(op, value)) === [op, stringify(value)]
    // -------------------------------------------------------------------------

    public function roundTripProvider(): array
    {
        return [
            ['$eq', 'Lyon'],
            ['$eq', '42'],
            ['$eq', '>= 3'],
            ['$eq', '<= 0'],
            ['$eq', '!= x'],
            ['$eq', '*'],
            ['$eq', '+'],
            ['$eq', 'null'],
            ['$eq', 'is null'],
            ['$eq', 'set'],
            ['$eq', '---'],
            ['$eq', 'any'],
            ['$eq', 'not in: a'],
            ['$eq', 'contains: b'],
            ['$eq', 'starts: c'],
            ['$eq', "l'apostrophe"],
            ['$eq', "'quoted'"],
            ['$eq', '[18..25]'],
            ['$eq', 'not [1..2]'],
            ['$eq', '~ tilde'],
            ['$ne', 'FR'],
            // Prefix-operator values that only survive thanks to quoting
            ['$ne', ' x '],
            ['$contains', '  '],
            ['$contains', ''],
            ['$starts_with', ' FR'],
            ['$in', "'visa'"],
            ['$nin', ' a, b '],
            ['$gt', '21'],
            ['$gte', '21.5'],
            ['$lt', '0'],
            ['$lte', '100'],
            ['$between', '18;25'],
            ['$between_excl', '0;1'],
            ['$between_lexcl', '0;1'],
            ['$between_rexcl', '0;1'],
            ['$not_between', '18;25'],
            ['$in', "FR, BE, 'L U'"],
            ['$nin', 'visa,mastercard'],
            ['$contains', 'bmw'],
            ['$not_contains', 'bmw'],
            ['$starts_with', 'FR'],
            ['$ends_with', '75'],
        ];
    }

    /**
     * @dataProvider roundTripProvider
     */
    public function testRoundTrip(string $operator, $value)
    {
        $cell = $this->codec->encode($operator, $value);
        $decoded = $this->codec->decode($cell);
        $this->assertSame($operator, $decoded['condition'], "operator round-trip via \"$cell\"");
        $this->assertSame($this->codec->stringify($value), $this->codec->stringify($decoded['value']), "value round-trip via \"$cell\"");
    }

    /**
     * Valueless operators round-trip to the canonical placeholder regardless
     * of what value the DB actually stores ('', true, '*'...).
     */
    public function testValuelessOperatorsRoundTrip()
    {
        foreach (['$any', '$is_set', '$is_null'] as $op) {
            foreach ([true, '', '*', 'anything'] as $stored) {
                $cell = $this->codec->encode($op, $stored);
                $decoded = $this->codec->decode($cell);
                $this->assertSame($op, $decoded['condition'], "operator $op with stored value " . var_export($stored, true));
                $this->assertTrue($decoded['value'], "valueless placeholder for $op");
            }
        }
    }

    public function testUnknownOperatorThrows()
    {
        // PHPUnit 4 syntax (expectException() arrived in PHPUnit 5.2)
        $this->setExpectedException(\InvalidArgumentException::class);
        $this->codec->encode('$unknown', 'x');
    }

    /**
     * Legacy comma-decimal values converge to the dot form on the FIRST encode
     * and then stay stable: decode(encode(x)) must be a fixed point of the
     * encode/decode cycle (no per-import drift).
     */
    public function testLegacyCommaValuesConverge()
    {
        foreach ([
            ['$gt', '0,5', '0.5'],
            ['$between', '0,5;1,5', '0.5;1.5'],
            ['$not_between', '1,1;2,2', '1.1;2.2'],
        ] as [$op, $legacy, $normalized]) {
            $cell = $this->codec->encode($op, $legacy);
            $decoded = $this->codec->decode($cell);
            $this->assertSame($normalized, $decoded['value'], "first-pass convergence for $op");
            // Second cycle is the identity
            $cell2 = $this->codec->encode($op, $decoded['value']);
            $this->assertSame($cell, $cell2, "fixed point for $op");
            $this->assertSame($normalized, $this->codec->decode($cell2)['value']);
        }
    }
}
