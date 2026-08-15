<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Completion;

use Ineersa\Tui\Completion\CompletionState;
use Ineersa\Tui\Completion\CompletionSuggestion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompletionState::class)]
final class CompletionStateTest extends TestCase
{
    private CompletionState $state;

    protected function setUp(): void
    {
        $this->state = new CompletionState();
    }

    #[Test]
    public function startsClosed(): void
    {
        $this->assertFalse($this->state->isOpen());
        $this->assertSame([], $this->state->getSuggestions());
        $this->assertNull($this->state->acceptSelected('0'));
    }

    #[Test]
    public function opensWithSuggestions(): void
    {
        $suggestions = $this->createSuggestions(['/help', '/exit', '/clear']);

        $this->state->open($suggestions);

        $this->assertTrue($this->state->isOpen());
        $this->assertSame($suggestions, $this->state->getSuggestions());
        $this->assertSame('/help', $this->state->acceptSelected('0')?->display);
        $this->assertSame('/exit', $this->state->acceptSelected('1')?->display);
    }

    #[Test]
    public function openWithEmptySuggestionsDoesNotOpen(): void
    {
        $this->state->open([]);

        $this->assertFalse($this->state->isOpen());
        $this->assertNull($this->state->acceptSelected('0'));
    }

    #[Test]
    public function closeResetsState(): void
    {
        $this->state->open($this->createSuggestions(['/help']));
        $this->state->close();

        $this->assertFalse($this->state->isOpen());
        $this->assertSame([], $this->state->getSuggestions());
        $this->assertNull($this->state->acceptSelected('0'));
    }

    #[Test]
    public function acceptSelectedReturnsNullForUnknownValue(): void
    {
        $this->state->open($this->createSuggestions(['/help']));

        $this->assertNull($this->state->acceptSelected(null));
        $this->assertNull($this->state->acceptSelected('9'));
        $this->assertNull($this->state->acceptSelected('nope'));
    }

    #[Test]
    public function suggestionByValueMapsSelectListValue(): void
    {
        $this->state->open($this->createSuggestions(['/help', '/exit']));

        $this->assertSame('/exit', $this->state->suggestionByValue('1')?->display);
        $this->assertNull($this->state->suggestionByValue('x'));
    }

    /** @param list<string> $displays */
    private function createSuggestions(array $displays): array
    {
        $suggestions = [];
        foreach ($displays as $display) {
            $suggestions[] = new CompletionSuggestion(
                display: $display,
                insertText: $display.' ',
                description: 'desc-'.$display,
                replacementStart: 0,
                replacementLength: 0,
            );
        }

        return $suggestions;
    }
}
