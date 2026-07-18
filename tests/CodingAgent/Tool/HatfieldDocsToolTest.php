<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use HelgeSverre\Toon\Toon;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tool\HatfieldDocsTool;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: hatfield_docs exposes only the eight curated IDs as TOON list/read
 * results with frontmatter metadata, bounded body chunks, and hard path safety.
 */
final class HatfieldDocsToolTest extends TestCase
{
    /** @var list<string> */
    private const EXPECTED_IDS = [
        'agents',
        'background-processes',
        'compaction',
        'hitl-and-approvals',
        'mcp',
        'prompt-templates',
        'session-storage',
        'settings',
    ];

    private string $appRoot;
    private HatfieldDocsTool $tool;

    protected function setUp(): void
    {
        $this->appRoot = TestDirectoryIsolation::createProjectTempDir('hatfield-docs-tool');
        $docsDir = $this->appRoot.'/docs';
        $internal = $this->appRoot.'/internal-docs';
        TestDirectoryIsolation::ensureDirectory($docsDir);
        TestDirectoryIsolation::ensureDirectory($internal);

        foreach (self::EXPECTED_IDS as $id) {
            $body = "# Title for {$id}\n\nLine one of {$id}.\nLine two of {$id}.\nLine three of {$id}.\n";
            $raw = "---\ndescription: Description for {$id}.\n---\n\n".$body;
            file_put_contents($docsDir.'/'.$id.'.md', $raw);
            symlink('../docs/'.$id.'.md', $internal.'/'.$id.'.md');
        }

        $this->tool = new HatfieldDocsTool(
            new ToolRuntime(new StackToolExecutionContextAccessor()),
            new AppResourceLocator($this->appRoot),
            new MarkdownFrontmatterExtractor(),
        );
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->appRoot);
    }

    public function testDefinitionAndListExposeExactCatalog(): void
    {
        $def = $this->tool->definition();
        $this->assertSame('hatfield_docs', $def->name);
        $this->assertSame(ToolExecutionMode::Parallel, $def->executionMode);
        $this->assertSame(['operation'], $def->parametersJsonSchema['required']);
        $this->assertFalse($def->parametersJsonSchema['additionalProperties']);
        $this->assertSame(['list', 'read'], $def->parametersJsonSchema['properties']['operation']['enum']);
        $this->assertSame(self::EXPECTED_IDS, $def->parametersJsonSchema['properties']['id']['enum']);

        $list = $this->invoke(['operation' => 'list']);
        $this->assertArrayHasKey('documents', $list);
        $this->assertCount(8, $list['documents']);
        $ids = array_column($list['documents'], 'id');
        $this->assertSame(self::EXPECTED_IDS, $ids);
        $this->assertSame('Title for agents', $list['documents'][0]['title']);
        $this->assertSame('Description for agents.', $list['documents'][0]['description']);
    }

    public function testReadReturnsBoundedBodyWithoutFrontmatter(): void
    {
        $first = $this->invoke([
            'operation' => 'read',
            'id' => 'settings',
            'offset' => 1,
            'limit' => 2,
        ]);
        $this->assertSame('settings', $first['id']);
        $this->assertSame('Title for settings', $first['title']);
        $this->assertSame('Description for settings.', $first['description']);
        $this->assertSame(1, $first['offset']);
        $this->assertSame(2, $first['limit']);
        $this->assertSame(5, $first['total_lines']);
        $this->assertTrue($first['has_more']);
        $this->assertSame(3, $first['next_offset']);
        // limit counts physical lines, including blank lines after H1.
        $this->assertSame("# Title for settings\n", $first['content']);
        $this->assertStringNotContainsString('description:', $first['content']);
        $this->assertStringNotContainsString('---', $first['content']);

        $rest = $this->invoke([
            'operation' => 'read',
            'id' => 'settings',
            'offset' => 3,
            'limit' => 10,
        ]);
        $this->assertFalse($rest['has_more']);
        $this->assertArrayNotHasKey('next_offset', $rest);
        $this->assertSame("Line one of settings.\nLine two of settings.\nLine three of settings.", $rest['content']);
    }

    #[DataProvider('invalidArgumentProvider')]
    public function testValidationRejectsUnsafeAndMalformedInput(array $arguments, string $messageFragment): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage($messageFragment);
        ($this->tool)($arguments);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidArgumentProvider(): iterable
    {
        yield 'missing id on read' => [
            ['operation' => 'read'],
            'id',
        ];
        yield 'unknown id' => [
            ['operation' => 'read', 'id' => 'datadog'],
            'Unknown document id',
        ];
        yield 'traversal id' => [
            ['operation' => 'read', 'id' => '../settings'],
            'Unknown document id',
        ];
        yield 'path id' => [
            ['operation' => 'read', 'id' => 'settings.md'],
            'Unknown document id',
        ];
        yield 'offset past eof' => [
            ['operation' => 'read', 'id' => 'settings', 'offset' => 99],
            'past end of document',
        ];
        yield 'limit too large' => [
            ['operation' => 'read', 'id' => 'settings', 'limit' => 501],
            'limit',
        ];
        yield 'non-int offset' => [
            ['operation' => 'read', 'id' => 'settings', 'offset' => '1'],
            'offset',
        ];
    }

    public function testMissingResourceFails(): void
    {
        unlink($this->appRoot.'/internal-docs/settings.md');
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Unable to read bundled document "settings"');
        ($this->tool)(['operation' => 'read', 'id' => 'settings']);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function invoke(array $arguments): array
    {
        $encoded = ($this->tool)($arguments);
        $this->assertIsString($encoded);
        $decoded = Toon::decode($encoded);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
