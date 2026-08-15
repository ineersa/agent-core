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
 * Thesis: hatfield_docs discovers only Markdown files with strict
 * builtin: true under the two approved roots, caches metadata+body on
 * first use, returns TOON list metadata and raw Markdown read bodies,
 * excludes unmarked docs, rejects unknown IDs, and fails closed on
 * duplicate IDs.
 */
final class HatfieldDocsToolTest extends TestCase
{
    private string $appRoot;
    private string $docsDir;
    private string $apiDocsDir;
    private HatfieldDocsTool $tool;

    protected function setUp(): void
    {
        $this->appRoot = TestDirectoryIsolation::createProjectTempDir('hatfield-docs-tool');
        $this->docsDir = $this->appRoot.'/docs';
        $this->apiDocsDir = $this->appRoot.'/.hatfield/extensions/extension-api/docs';
        TestDirectoryIsolation::ensureDirectory($this->docsDir);
        TestDirectoryIsolation::ensureDirectory($this->apiDocsDir);

        $this->writeCoreDoc('zeta', 'Zeta Title', 'Zeta description.', "Body of zeta.\nSecond zeta line.\n");
        $this->writeCoreDoc('alpha', 'Alpha Title', 'Alpha description.', "Body of alpha.\n");
        $this->writeApiDoc('extension-api', 'Extension API', 'API overview.', "API body.\n");
        // Unmarked repository-only decoy must stay invisible.
        file_put_contents($this->docsDir.'/datadog.md', "# Datadog\n\nrepo only\n");
        file_put_contents(
            $this->docsDir.'/notes.md',
            "---\ndescription: unmarked\nbuiltin: false\n---\n\n# Notes\n\nignored\n",
        );
        file_put_contents($this->docsDir.'/README.txt', 'ignored non-md');

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

    public function testDefinitionAndListDiscoverSortedCatalogFromApprovedRoots(): void
    {
        $def = $this->tool->definition();
        $this->assertSame('hatfield_docs', $def->name);
        $this->assertSame(ToolExecutionMode::Parallel, $def->executionMode);
        $this->assertSame(['operation'], $def->parametersJsonSchema['required']);
        $this->assertFalse($def->parametersJsonSchema['additionalProperties']);
        $this->assertSame(['list', 'read'], $def->parametersJsonSchema['properties']['operation']['enum']);
        $this->assertArrayNotHasKey('enum', $def->parametersJsonSchema['properties']['id']);
        $this->assertSame(1, $def->parametersJsonSchema['properties']['id']['minLength']);
        $this->assertSame(
            ['Use hatfield_docs for questions about Hatfield behavior, configuration, or usage; call list first when the relevant document ID is unknown.'],
            $def->promptGuidelines,
        );

        $list = $this->invokeList();
        $this->assertSame(
            [
                [
                    'id' => 'alpha',
                    'title' => 'Alpha Title',
                    'description' => 'Alpha description.',
                ],
                [
                    'id' => 'extension-api',
                    'title' => 'Extension API',
                    'description' => 'API overview.',
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
        $this->assertStringNotContainsString('builtin:', $body);
        $this->assertStringNotContainsString('---', $body);

        file_put_contents(
            $this->docsDir.'/zeta.md',
            "---\nbuiltin: true\ndescription: mutated\n---\n\n# Mutated\n\nshould not appear\n",
        );
        unlink($this->docsDir.'/alpha.md');

        $again = ($this->tool)(['operation' => 'read', 'id' => 'zeta']);
        $this->assertSame($body, $again);

        $list = $this->invokeList();
        $this->assertCount(3, $list['documents']);
        $alphaBody = ($this->tool)(['operation' => 'read', 'id' => 'alpha']);
        $this->assertSame("# Alpha Title\n\nBody of alpha.", $alphaBody);
    }

    public function testDuplicateIdsAcrossRootsFailClosed(): void
    {
        $this->writeApiDoc('alpha', 'Dup', 'Dup description.', "dup\n");
        $tool = new HatfieldDocsTool(
            new ToolRuntime(new StackToolExecutionContextAccessor()),
            new AppResourceLocator($this->appRoot),
            new MarkdownFrontmatterExtractor(),
        );

        try {
            $tool(['operation' => 'list']);
            $this->fail('Expected ToolCallException for duplicate IDs');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('Duplicate built-in documentation id "alpha"', $e->getMessage());
            $this->assertFalse($e->retryable());
        }
    }

    public function testMalformedBuiltinDocumentFailsClosed(): void
    {
        file_put_contents(
            $this->docsDir.'/broken.md',
            "---\nbuiltin: true\ndescription: missing h1\n---\n\nNo heading here.\n",
        );
        $tool = new HatfieldDocsTool(
            new ToolRuntime(new StackToolExecutionContextAccessor()),
            new AppResourceLocator($this->appRoot),
            new MarkdownFrontmatterExtractor(),
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('exactly one useful H1');
        $tool(['operation' => 'list']);
    }

    #[DataProvider('unknownIdProvider')]
    public function testUnknownIdsRejectedFromCatalog(array $arguments): void
    {
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
        yield 'unmarked id' => [['operation' => 'read', 'id' => 'datadog']];
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

    private function writeCoreDoc(string $id, string $title, string $description, string $bodyAfterH1): void
    {
        $raw = "---\nbuiltin: true\ndescription: {$description}\n---\n\n# {$title}\n\n{$bodyAfterH1}";
        file_put_contents($this->docsDir.'/'.$id.'.md', $raw);
    }

    private function writeApiDoc(string $id, string $title, string $description, string $bodyAfterH1): void
    {
        $raw = "---\nbuiltin: true\ndescription: {$description}\n---\n\n# {$title}\n\n{$bodyAfterH1}";
        file_put_contents($this->apiDocsDir.'/'.$id.'.md', $raw);
    }
}
