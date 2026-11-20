<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Schema;

use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Schema\SchemaNormalizer;
use stdClass;

class SchemaNormalizerTest extends TestCase
{
    public function testOptionalNullableStringBecomesString(): void
    {
        $out = SchemaNormalizer::normalize(['type' => 'object', 'properties' => [
            'date_from' => ['type' => ['null', 'string'], 'description' => 'First day.', 'default' => null],
        ]]);

        $this->assertSame(['type' => 'string', 'description' => 'First day.'], $out['properties']['date_from']);
    }

    public function testRequiredNullableIsKept(): void
    {
        $schema = ['type' => 'object', 'properties' => ['x' => ['type' => ['null', 'string']]], 'required' => ['x']];

        $this->assertSame($schema, SchemaNormalizer::normalize($schema));
    }

    public function testWiderUnionKeepsTheOtherTypes(): void
    {
        $out = SchemaNormalizer::normalize(['type' => 'object', 'properties' => [
            'ref' => ['type' => ['null', 'string', 'integer'], 'default' => null],
        ]]);

        $this->assertSame(['type' => ['string', 'integer']], $out['properties']['ref']);
    }

    public function testNullableEnumLosesNull(): void
    {
        $out = SchemaNormalizer::normalize(['type' => 'object', 'properties' => [
            'field' => ['type' => ['string', 'null'], 'enum' => ['signed', 'study', null], 'default' => null],
        ]]);

        $this->assertSame(['type' => 'string', 'enum' => ['signed', 'study']], $out['properties']['field']);
    }

    public function testSingleTypeWithNullDefaultLosesTheDefault(): void
    {
        $out = SchemaNormalizer::normalize(['type' => 'object', 'properties' => [
            'date_from' => ['type' => 'string', 'format' => 'date', 'default' => null],
        ]]);

        $this->assertSame(['type' => 'string', 'format' => 'date'], $out['properties']['date_from']);
    }

    public function testNullableWithARealDefaultKeepsNull(): void
    {
        // ?string $status = 'open', where null means "any status": null stays a value the client can send.
        $schema = ['type' => 'object', 'properties' => [
            'status' => ['type' => ['null', 'string'], 'enum' => ['open', 'closed', null], 'default' => 'open'],
        ]];

        $this->assertSame($schema, SchemaNormalizer::normalize($schema));
    }

    public function testRealDefaultsAreKept(): void
    {
        $schema = ['type' => 'object', 'properties' => ['field' => ['type' => 'string', 'default' => 'signed']]];

        $this->assertSame($schema, SchemaNormalizer::normalize($schema));
    }

    public function testNoPropertiesIsLeftAlone(): void
    {
        $empty = ['type' => 'object', 'properties' => []];
        $this->assertSame($empty, SchemaNormalizer::normalize($empty));

        $object = ['type' => 'object', 'properties' => new stdClass()];
        $this->assertSame($object, SchemaNormalizer::normalize($object));
    }
}
