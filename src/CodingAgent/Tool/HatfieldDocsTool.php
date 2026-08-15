<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use HelgeSverre\Toon\Toon;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Docs\BuiltinDocsCatalog;
use Ineersa\CodingAgent\Docs\BuiltinDocsCatalogException;
use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Ineersa\CodingAgent\Tool\Arguments\HatfieldDocsArgumentsDTO;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Read-only parent-agent catalog for curated Hatfield documentation.
 *
 * Documents are discovered once from the approved built-in roots
 * ({@see docs/*.md} and Extension API docs) and cached for the process
 * lifetime. Lookup is by logical ID only; arbitrary filesystem paths are
 * never accepted.
 */
#[AsTool(self::NAME, self::DESCRIPTION)]
final class HatfieldDocsTool implements HatfieldToolProviderInterface
{
    public const string NAME = 'hatfield_docs';

    /** Provider-visible description; shared with the registry definition. */
    public const string DESCRIPTION = 'List or read bundled Hatfield documentation by logical document ID.';

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
     * @return string TOON-encoded list metadata, or raw Markdown body for read
     */
    public function __invoke(HatfieldDocsArgumentsDTO $arguments): string
    {
        return $this->toolRuntime->run(function () use ($arguments): string {
            // operation is Choice-constrained on the DTO; id is required for
            // read via a When constraint, so no default branch is needed.
            return match ($arguments->operation) {
                'list' => Toon::encode($this->listDocuments()),
                'read' => $this->readDocument($arguments),
                default => throw new \LogicException('Unreachable: operation is Choice-constrained on HatfieldDocsArgumentsDTO and rejected before invocation.'),
            };
        });
    }

    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: self::NAME,
            description: self::DESCRIPTION,
            handler: $this,
            executionMode: ToolExecutionMode::Parallel,
            promptLine: 'hatfield_docs list|read [id] — list or read bundled Hatfield docs',
            promptGuidelines: [
                'Use hatfield_docs for questions about Hatfield behavior, configuration, or usage; call list first when the relevant document ID is unknown.',
            ],
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

    private function readDocument(HatfieldDocsArgumentsDTO $arguments): string
    {
        /** @var string $id DTO When constraint requires a non-blank id for read. */
        $id = (string) $arguments->id;

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

        try {
            $entries = (new BuiltinDocsCatalog($this->extractor))->discover($this->resources->getAppRoot());
        } catch (BuiltinDocsCatalogException $e) {
            throw new ToolCallException($e->getMessage(), retryable: false, previous: $e);
        }

        $catalog = [];
        foreach ($entries as $entry) {
            $catalog[$entry['id']] = [
                'id' => $entry['id'],
                'title' => $entry['title'],
                'description' => $entry['description'],
                'body' => $entry['body'],
            ];
        }

        $this->catalog = $catalog;

        return $this->catalog;
    }
}
