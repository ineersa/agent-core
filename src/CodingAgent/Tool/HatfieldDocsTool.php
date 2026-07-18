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
 * Documents are loaded only by approved logical IDs under the bundled
 * internal-docs root. Arbitrary filesystem paths are never accepted.
 */
final class HatfieldDocsTool implements HatfieldToolProviderInterface, ToolHandlerInterface
{
    private const DEFAULT_LIMIT = 200;

    private const MAX_LIMIT = 500;

    /**
     * Approved logical document IDs (filename stem under internal-docs/).
     *
     * @var list<string>
     */
    private const DOCUMENT_IDS = [
        'agents',
        'background-processes',
        'compaction',
        'hitl-and-approvals',
        'mcp',
        'prompt-templates',
        'session-storage',
        'settings',
    ];

    public function __construct(
        private readonly ToolRuntime $toolRuntime,
        private readonly AppResourceLocator $resources,
        private readonly MarkdownFrontmatterExtractor $extractor,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return string TOON-encoded list or read result
     */
    public function __invoke(array $arguments): string
    {
        return $this->toolRuntime->run(function () use ($arguments): string {
            $operation = $this->requireOperation($arguments);

            $result = match ($operation) {
                'list' => $this->listDocuments(),
                'read' => $this->readDocument($arguments),
                default => throw new ToolCallException('The "operation" argument must be one of: list, read.', retryable: false),
            };

            return Toon::encode($result);
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
                        'enum' => self::DOCUMENT_IDS,
                        'description' => 'Logical document ID (required for read).',
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'description' => '1-indexed body line offset for read (default 1).',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => self::MAX_LIMIT,
                        'description' => 'Maximum body lines for read (default 200, max 500).',
                    ],
                ],
                'required' => ['operation'],
                'additionalProperties' => false,
            ],
            handler: $this,
            executionMode: ToolExecutionMode::Parallel,
            promptLine: 'hatfield_docs list|read [id] [offset] [limit] — list or read bundled Hatfield docs',
        );
    }

    /**
     * @return array{documents: list<array{id: string, title: string, description: string}>}
     */
    private function listDocuments(): array
    {
        $documents = [];
        foreach (self::DOCUMENT_IDS as $id) {
            $meta = $this->loadDocument($id);
            $documents[] = [
                'id' => $id,
                'title' => $meta['title'],
                'description' => $meta['description'],
            ];
        }

        return ['documents' => $documents];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function readDocument(array $arguments): array
    {
        $id = $this->requireId($arguments);
        $offset = $this->optionalPositiveInt($arguments, 'offset', 1);
        $limit = $this->optionalPositiveInt($arguments, 'limit', self::DEFAULT_LIMIT);
        if ($limit > self::MAX_LIMIT) {
            throw new ToolCallException(\sprintf('The "limit" argument must be <= %d.', self::MAX_LIMIT), retryable: false);
        }

        $meta = $this->loadDocument($id);
        $lines = preg_split("/\n/", $meta['body']);
        if (false === $lines) {
            $lines = [];
        }
        $totalLines = \count($lines);

        if ($totalLines > 0 && $offset > $totalLines) {
            throw new ToolCallException(\sprintf('offset %d is past end of document (%d lines).', $offset, $totalLines), retryable: false);
        }

        $slice = \array_slice($lines, $offset - 1, $limit);
        $end = $offset - 1 + \count($slice);
        $hasMore = $end < $totalLines;

        $result = [
            'id' => $id,
            'title' => $meta['title'],
            'description' => $meta['description'],
            'offset' => $offset,
            'limit' => $limit,
            'total_lines' => $totalLines,
            'has_more' => $hasMore,
            'content' => implode("\n", $slice),
        ];
        if ($hasMore) {
            $result['next_offset'] = $end + 1;
        }

        return $result;
    }

    /**
     * @return array{title: string, description: string, body: string}
     */
    private function loadDocument(string $id): array
    {
        $path = $this->documentPath($id);
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
            'title' => trim($matches[1]),
            'description' => trim($parsed['description']),
            'body' => $body,
        ];
    }

    private function documentPath(string $id): string
    {
        // IDs are fixed allow-list members; never accept path separators.
        return $this->resources->getInternalDocsPath().'/'.$id.'.md';
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function requireOperation(array $arguments): string
    {
        $operation = $arguments['operation'] ?? null;
        if (!\is_string($operation) || !\in_array($operation, ['list', 'read'], true)) {
            throw new ToolCallException('The "operation" argument must be one of: list, read.', retryable: false);
        }

        return $operation;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function requireId(array $arguments): string
    {
        $id = $arguments['id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            throw new ToolCallException('The "id" argument is required for read and must be a known document ID.', retryable: false);
        }
        if (!\in_array($id, self::DOCUMENT_IDS, true)) {
            throw new ToolCallException(\sprintf('Unknown document id "%s".', $id), retryable: false, hint: 'Use operation=list to see approved IDs.');
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function optionalPositiveInt(array $arguments, string $key, int $default): int
    {
        if (!\array_key_exists($key, $arguments) || null === $arguments[$key]) {
            return $default;
        }

        $value = $arguments[$key];
        if (!\is_int($value)) {
            throw new ToolCallException(\sprintf('The "%s" argument must be an integer.', $key), retryable: false);
        }
        if ($value < 1) {
            throw new ToolCallException(\sprintf('The "%s" argument must be a positive integer.', $key), retryable: false);
        }

        return $value;
    }
}
