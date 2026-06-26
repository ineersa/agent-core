<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Mcp\Catalog;

use Ineersa\CodingAgent\Mcp\Catalog\McpToolNameMapper;
use PHPUnit\Framework\TestCase;

/**
 * Test thesis 1: Name mapping produces namespaced Hatfield tool identifiers
 * using the default `{server}_{tool}` pattern.
 *
 * Test thesis 2: Sanitization replaces invalid characters with underscore,
 * collapses consecutive underscores, trims leading/trailing underscores,
 * and ensures non-empty results.
 *
 * Test thesis 3: Reverse mapping via reverseKey() produces consistent
 * `{server}:{tool}` keys for catalog lookups.
 */
class McpToolNameMapperTest extends TestCase
{
    private McpToolNameMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new McpToolNameMapper();
    }

    public function testMapsSimpleNames(): void
    {
        $result = $this->mapper->mapHatfieldName('filesystem', 'read_file');
        self::assertSame('filesystem_read_file', $result);
    }

    public function testMapsNamesWithHyphens(): void
    {
        $result = $this->mapper->mapHatfieldName('my-server', 'get-data');
        self::assertSame('my-server_get-data', $result);
    }

    public function testMapsCamelCaseServerNames(): void
    {
        $result = $this->mapper->mapHatfieldName('GitHub', 'search_issues');
        self::assertSame('GitHub_search_issues', $result);
    }

    public function testSanitizeReplacesSpecialCharacters(): void
    {
        // Dots, spaces, and slashes should be replaced with underscores
        $result = $this->mapper->sanitize('my.server/name with space');
        self::assertSame('my_server_name_with_space', $result);
    }

    public function testSanitizeCollapsesConsecutiveUnderscores(): void
    {
        // Multiple special chars adjacent → single underscore
        $result = $this->mapper->sanitize('a..b//c');
        self::assertSame('a_b_c', $result);
    }

    public function testSanitizeTrimsLeadingAndTrailingUnderscores(): void
    {
        $result = $this->mapper->sanitize('...trim-me...');
        self::assertSame('trim-me', $result);
    }

    public function testSanitizeEmptyStringReturnsUnknown(): void
    {
        $result = $this->mapper->sanitize('');
        self::assertSame('unknown', $result);
    }

    public function testSanitizeAllSpecialCharsReturnsUnknown(): void
    {
        $result = $this->mapper->sanitize('...///...');
        self::assertSame('unknown', $result);
    }

    public function testSanitizePreservesLettersNumbersUnderscoresHyphens(): void
    {
        $result = $this->mapper->sanitize('abc-123_def-456');
        self::assertSame('abc-123_def-456', $result);
    }

    public function testReverseKeyIsConsistent(): void
    {
        $key = $this->mapper->reverseKey('filesystem', 'read_file');
        self::assertSame('filesystem:read_file', $key);
    }

    public function testMappingIsIdempotentForCleanNames(): void
    {
        $name1 = $this->mapper->mapHatfieldName('server', 'tool');
        $name2 = $this->mapper->mapHatfieldName('server', 'tool');
        self::assertSame($name1, $name2, 'Same inputs should produce same mapped name');
    }
}
