<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use HelgeSverre\Toon\Toon;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Read-only parent-agent catalog for curated Hatfield documentation.
 *
 * Documents are discovered once from the bundled internal-docs root and
 * cached for the process lifetime. Lookup is by logical ID only; arbitrary
 * filesystem paths are never accepted.
 */
final class HatfieldDocsTool implements HatfieldToolProviderInterface, ToolHandlerInterface
{
    /**
     * Lazy catalog keyed by logical document ID (filename stem).
     *
     * @var array<string, array{id: string, title: string, description: string, body: string}>|null
     */
    private ?array $catalog = null;

    public function __construct(
        private readonly ToolRuntime $toolRuntime,
        private readonly AppResourceLocator $resources,
        private readonly MarkdownFrontmatterExtractor $extractor,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return string TOON-encoded list metadata, or raw Markdown body for read
     */
    public function __invoke(array $arguments): string
    {
        return $this->toolRuntime->run(function () use ($arguments): string {
            $operation = $arguments['operation'] ?? null;

            return match ($operation) {
                'list' => Toon::encode($this->listDocuments()),
                'read' => $this->readDocument($arguments),
                default => throw new ToolCallException('The "operation" argument must be one of: list, read.', retryable: false),
            };
        });
    }

    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: 'hatfield_docs',
            description: 'List or read bundled Hatfield documentation by logical document ID.',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'operation' => [
                        'type' => 'string',
                        'enum' => ['list', 'read'],
                        'description' => 'list catalog entries, or read one document by id.',
                    ],
                    'id' => [
                        'type' => 'string',
                        'description' => 'Logical document ID (required for read).',
                    ],
                ],
                'required' => ['operation'],
                'additionalProperties' => false,
            ],
            handler: $this,
            executionMode: ToolExecutionMode::Parallel,
            promptLine: 'hatfield_docs list|read [id] — list or read bundled Hatfield docs',
        );
    }

    /**
     * @return array{documents: list<array{id: string, title: string, description: string}>}
     */
    private function listDocuments(): array
    {
        $documents = [];
        foreach ($this->catalog() as $entry) {
            $documents[] = [
                'id' => $entry['id'],
                'title' => $entry['title'],
                'description' => $entry['description'],
            ];
        }

        return ['documents' => $documents];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function readDocument(array $arguments): string
    {
        $id = $arguments['id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            throw new ToolCallException('Unknown document id.', retryable: false, hint: 'Use operation=list to see approved IDs.');
        }

        $catalog = $this->catalog();
        if (!isset($catalog[$id])) {
            throw new ToolCallException('Unknown document id.', retryable: false, hint: 'Use operation=list to see approved IDs.');
        }

        return $catalog[$id]['body'];
    }

    /**
     * @return array<string, array{id: string, title: string, description: string, body: string}>
     */
    private function catalog(): array
    {
        if (null !== $this->catalog) {
            return $this->catalog;
        }

        $root = $this->resources->getInternalDocsPath();

        try {
            $iterator = new \FilesystemIterator(
                $root,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME,
            );
        } catch (\UnexpectedValueException $e) {
            throw new ToolCallException('Unable to open bundled internal-docs directory.', retryable: false, previous: $e);
        }

        $catalog = [];
        foreach ($iterator as $path) {
            if (!\is_string($path) || !is_file($path) || !str_ends_with($path, '.md')) {
                continue;
            }

            $id = pathinfo($path, \PATHINFO_FILENAME);
            if (!\is_string($id) || '' === $id) {
                continue;
            }

            $catalog[$id] = $this->parseDocument($path, $id);
        }

        ksort($catalog);
        $this->catalog = $catalog;

        return $this->catalog;
    }

    /**
     * @return array{id: string, title: string, description: string, body: string}
     */
    private function parseDocument(string $path, string $id): array
    {
        $raw = @file_get_contents($path);
        if (false === $raw) {
            throw new ToolCallException(\sprintf('Unable to read bundled document "%s".', $id), retryable: false);
        }

        $extraction = $this->extractor->extract($raw);
        if (null === $extraction['yamlBlock'] || !$extraction['hasOpeningDelimiter'] || !$extraction['hasClosingDelimiter']) {
            throw new ToolCallException(\sprintf('Document "%s" is missing YAML frontmatter.', $id), retryable: false);
        }

        try {
            $parsed = Yaml::parse($extraction['yamlBlock']);
        } catch (ParseException $e) {
            throw new ToolCallException(\sprintf('Document "%s" has invalid YAML frontmatter.', $id), retryable: false, previous: $e);
        }

        if (!\is_array($parsed) || !isset($parsed['description']) || !\is_string($parsed['description']) || '' === trim($parsed['description'])) {
            throw new ToolCallException(\sprintf('Document "%s" frontmatter must include a non-empty string description.', $id), retryable: false);
        }

        $body = $extraction['body'];
        if (!preg_match('/^#\s+(.+)$/m', $body, $matches)) {
            throw new ToolCallException(\sprintf('Document "%s" is missing an H1 title.', $id), retryable: false);
        }

        return [
            'id' => $id,
            'title' => trim($matches[1]),
            'description' => trim($parsed['description']),
            'body' => $body,
        ];
    }
}
