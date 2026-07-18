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
 * Thesis: hatfield_docs discovers .md files under internal-docs, caches
 * metadata+body on first use, returns TOON list metadata and raw Markdown
 * read bodies, and rejects IDs absent from the cached catalog.
 */
final class HatfieldDocsToolTest extends TestCase
{
    private string $appRoot;
    private string $docsDir;
    private string $internalDir;
    private HatfieldDocsTool $tool;

    protected function setUp(): void
    {
        $this->appRoot = TestDirectoryIsolation::createProjectTempDir('hatfield-docs-tool');
        $this->docsDir = $this->appRoot.'/docs';
        $this->internalDir = $this->appRoot.'/internal-docs';
        TestDirectoryIsolation::ensureDirectory($this->docsDir);
        TestDirectoryIsolation::ensureDirectory($this->internalDir);

        // Arbitrary fixture catalog — proves discovery, not a fixed production list.
        $this->writeDoc('zeta', 'Zeta Title', 'Zeta description.', "Body of zeta.\nSecond zeta line.\n");
        $this->writeDoc('alpha', 'Alpha Title', 'Alpha description.', "Body of alpha.\n");
        file_put_contents($this->internalDir.'/notes.txt', 'ignored non-md');
        file_put_contents($this->internalDir.'/README', 'ignored extensionless');

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

    public function testDefinitionAndListDiscoverSortedCatalog(): void
    {
        $def = $this->tool->definition();
        $this->assertSame('hatfield_docs', $def->name);
        $this->assertSame(ToolExecutionMode::Parallel, $def->executionMode);
        $this->assertSame(['operation'], $def->parametersJsonSchema['required']);
        $this->assertFalse($def->parametersJsonSchema['additionalProperties']);
        $this->assertSame(['list', 'read'], $def->parametersJsonSchema['properties']['operation']['enum']);
        $this->assertArrayNotHasKey('enum', $def->parametersJsonSchema['properties']['id']);
        $this->assertArrayNotHasKey('offset', $def->parametersJsonSchema['properties']);
        $this->assertArrayNotHasKey('limit', $def->parametersJsonSchema['properties']);

        $list = $this->invokeList();
        $this->assertSame(
            [
                [
                    'id' => 'alpha',
                    'title' => 'Alpha Title',
                    'description' => 'Alpha description.',
                ],
                [
                    'id' => 'zeta',
                    'title' => 'Zeta Title',
                    'description' => 'Zeta description.',
                ],
            ],
            $list['documents'],
        );
    }

    public function testReadReturnsFullMarkdownBodyAndCachesWithoutReread(): void
    {
        $body = ($this->tool)(['operation' => 'read', 'id' => 'zeta']);
        $this->assertIsString($body);
        $this->assertSame("# Zeta Title\n\nBody of zeta.\nSecond zeta line.", $body);
        $this->assertStringNotContainsString('description:', $body);
        $this->assertStringNotContainsString('---', $body);
        $this->assertFalse(str_starts_with(ltrim($body), '{'));
        $this->assertStringNotContainsString('documents:', $body);

        // Mutate and delete backing files after first catalog load.
        file_put_contents(
            $this->docsDir.'/zeta.md',
            "---\ndescription: mutated\n---\n\n# Mutated\n\nshould not appear\n",
        );
        unlink($this->internalDir.'/alpha.md');
        unlink($this->docsDir.'/alpha.md');

        $again = ($this->tool)(['operation' => 'read', 'id' => 'zeta']);
        $this->assertSame($body, $again);

        $list = $this->invokeList();
        $this->assertSame(
            [
                [
                    'id' => 'alpha',
                    'title' => 'Alpha Title',
                    'description' => 'Alpha description.',
                ],
                [
                    'id' => 'zeta',
                    'title' => 'Zeta Title',
                    'description' => 'Zeta description.',
                ],
            ],
            $list['documents'],
        );
        $alphaBody = ($this->tool)(['operation' => 'read', 'id' => 'alpha']);
        $this->assertSame("# Alpha Title\n\nBody of alpha.", $alphaBody);
    }

    #[DataProvider('unknownIdProvider')]
    public function testUnknownIdsRejectedFromCatalog(array $arguments): void
    {
        // Warm the catalog so rejections are pure key lookups.
        $this->invokeList();

        try {
            ($this->tool)($arguments);
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('Unknown document id', $e->getMessage());
            $this->assertFalse($e->retryable());
            $this->assertSame('Use operation=list to see approved IDs.', $e->hint());
        }
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function unknownIdProvider(): iterable
    {
        yield 'missing id' => [['operation' => 'read']];
        yield 'unknown id' => [['operation' => 'read', 'id' => 'datadog']];
        yield 'traversal id' => [['operation' => 'read', 'id' => '../settings']];
        yield 'filename-like id' => [['operation' => 'read', 'id' => 'zeta.md']];
    }

    /**
     * @return array{documents: list<array{id: string, title: string, description: string}>}
     */
    private function invokeList(): array
    {
        $encoded = ($this->tool)(['operation' => 'list']);
        $this->assertIsString($encoded);
        $decoded = Toon::decode($encoded);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('documents', $decoded);

        return $decoded;
    }

    private function writeDoc(string $id, string $title, string $description, string $bodyAfterH1): void
    {
        $raw = "---\ndescription: {$description}\n---\n\n# {$title}\n\n{$bodyAfterH1}";
        file_put_contents($this->docsDir.'/'.$id.'.md', $raw);
        symlink('../docs/'.$id.'.md', $this->internalDir.'/'.$id.'.md');
    }
}
