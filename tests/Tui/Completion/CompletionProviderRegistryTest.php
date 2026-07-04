<?php

declare(strict_types=1);

namespace Ineersa\Tests\Tui\Completion;

use Ineersa\Tui\Completion\CompletionContext;
use Ineersa\Tui\Completion\CompletionProvider;
use Ineersa\Tui\Completion\CompletionProviderRegistry;
use Ineersa\Tui\Completion\CompletionSuggestion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompletionProviderRegistry::class)]
final class CompletionProviderRegistryTest extends TestCase
{
    #[Test]
    public function returnsSuggestionsFromFirstProviderThatMatches(): void
    {
        $providers = [
            $this->emptyProvider(),
            $this->providerWith([
                new CompletionSuggestion('one', 'one ', '', 0, 0),
            ]),
            $this->providerWith([
                new CompletionSuggestion('two', 'two ', '', 0, 0),
            ]),
        ];

        $registry = new CompletionProviderRegistry($providers);
        $result = $registry->getSuggestions(CompletionContext::forCursorAtEnd('/test'));

        $this->assertCount(1, $result);
        $this->assertSame('one ', $result[0]->insertText);
    }

    #[Test]
    public function skipsEmptyProviders(): void
    {
        $providers = [
            $this->emptyProvider(),
            $this->providerWith([
                new CompletionSuggestion('hit', 'hit ', '', 0, 0),
            ]),
        ];

        $registry = new CompletionProviderRegistry($providers);
        $result = $registry->getSuggestions(CompletionContext::forCursorAtEnd('/test'));

        $this->assertCount(1, $result);
        $this->assertSame('hit ', $result[0]->insertText);
    }

    #[Test]
    public function returnsEmptyWhenNoProviderMatches(): void
    {
        $providers = [
            $this->emptyProvider(),
            $this->emptyProvider(),
        ];

        $registry = new CompletionProviderRegistry($providers);
        $result = $registry->getSuggestions(CompletionContext::forCursorAtEnd('/test'));

        $this->assertSame([], $result);
    }

    #[Test]
    public function returnsEmptyWhenNoProvidersRegistered(): void
    {
        $registry = new CompletionProviderRegistry([]);
        $result = $registry->getSuggestions(CompletionContext::forCursorAtEnd('/test'));

        $this->assertSame([], $result);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    private function emptyProvider(): CompletionProvider
    {
        return new readonly class implements CompletionProvider {
            public function getSuggestions(CompletionContext $context): array
            {
                return [];
            }
        };
    }

    /**
     * @param list<CompletionSuggestion> $suggestions
     */
    private function providerWith(array $suggestions): CompletionProvider
    {
        return new readonly class($suggestions) implements CompletionProvider {
            /** @param list<CompletionSuggestion> $suggestions */
            public function __construct(private array $suggestions)
            {
            }

            public function getSuggestions(CompletionContext $context): array
            {
                return $this->suggestions;
            }
        };
    }
}
