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

    // ── Open / Close ─────────────────────────────────────────────

    #[Test]
    public function startsClosed(): void
    {
        $this->assertFalse($this->state->isOpen());
        $this->assertNull($this->state->selected());
        $this->assertSame([], $this->state->getSuggestions());
        $this->assertSame(0, $this->state->getSelectedIndex());
    }

    #[Test]
    public function opensWithSuggestionsAndSelectsFirst(): void
    {
        $suggestions = $this->createSuggestions(['/help', '/exit', '/clear']);

        $this->state->open($suggestions);

        $this->assertTrue($this->state->isOpen());
        $this->assertSame($suggestions, $this->state->getSuggestions());
        $this->assertSame(0, $this->state->getSelectedIndex());
        $this->assertNotNull($this->state->selected());
        $this->assertSame('/help', $this->state->selected()->display);
    }

    #[Test]
    public function openWithEmptySuggestionsDoesNotOpen(): void
    {
        $this->state->open([]);

        $this->assertFalse($this->state->isOpen());
        $this->assertNull($this->state->selected());
    }

    #[Test]
    public function closeResetsState(): void
    {
        $this->state->open($this->createSuggestions(['/help']));
        $this->state->close();

        $this->assertFalse($this->state->isOpen());
        $this->assertNull($this->state->selected());
        $this->assertSame([], $this->state->getSuggestions());
    }

    // ── Navigation ────────────────────────────────────────────────

    #[Test]
    public function moveNextWrapsAround(): void
    {
        $this->state->open($this->createSuggestions(['/help', '/exit', '/clear']));

        // First item selected (index 0)
        $this->assertSame('/help', $this->state->selected()->display);

        $this->state->moveNext();
        $this->assertSame('/exit', $this->state->selected()->display);

        $this->state->moveNext();
        $this->assertSame('/clear', $this->state->selected()->display);

        // Wrap around to first
        $this->state->moveNext();
        $this->assertSame('/help', $this->state->selected()->display);
    }

    #[Test]
    public function movePreviousWrapsAround(): void
    {
        $this->state->open($this->createSuggestions(['/help', '/exit', '/clear']));

        // First item selected, previous wraps to last
        $this->state->movePrevious();
        $this->assertSame('/clear', $this->state->selected()->display);

        $this->state->movePrevious();
        $this->assertSame('/exit', $this->state->selected()->display);

        $this->state->movePrevious();
        $this->assertSame('/help', $this->state->selected()->display);
    }

    #[Test]
    public function moveNextDoesNothingWhenClosed(): void
    {
        $this->state->moveNext();

        $this->assertFalse($this->state->isOpen());
        $this->assertSame(0, $this->state->getSelectedIndex());
    }

    #[Test]
    public function movePreviousDoesNothingWhenClosed(): void
    {
        $this->state->movePrevious();

        $this->assertFalse($this->state->isOpen());
        $this->assertSame(0, $this->state->getSelectedIndex());
    }

    #[Test]
    public function navigationWithSingleItemStaysOnSameItem(): void
    {
        $this->state->open($this->createSuggestions(['/only']));

        $this->state->moveNext();
        $this->assertSame('/only', $this->state->selected()->display);

        $this->state->movePrevious();
        $this->assertSame('/only', $this->state->selected()->display);
    }

    // ── Accept ────────────────────────────────────────────────────

    #[Test]
    public function acceptSelectedReturnsHighlightedSuggestion(): void
    {
        $suggestions = $this->createSuggestions(['/help', '/exit']);
        $this->state->open($suggestions);

        $accepted = $this->state->acceptSelected();

        $this->assertNotNull($accepted);
        $this->assertSame('/help', $accepted->display);
    }

    #[Test]
    public function acceptSelectedReturnsNullWhenClosed(): void
    {
        $this->assertNull($this->state->acceptSelected());
    }

    #[Test]
    public function acceptSelectedAfterNavigation(): void
    {
        $this->state->open($this->createSuggestions(['/help', '/exit', '/clear']));

        $this->state->moveNext();
        $this->state->moveNext(); // index 2: /clear

        $accepted = $this->state->acceptSelected();
        $this->assertSame('/clear', $accepted->display);
    }

    // ── Re-open ───────────────────────────────────────────────────

    #[Test]
    public function reopenResetsSelection(): void
    {
        $this->state->open($this->createSuggestions(['/help', '/exit']));
        $this->state->moveNext(); // Select /exit
        $this->assertSame('/exit', $this->state->selected()->display);

        $this->state->close();
        $this->assertFalse($this->state->isOpen());

        $this->state->open($this->createSuggestions(['/clear', '/model']));
        $this->assertTrue($this->state->isOpen());
        $this->assertSame(0, $this->state->getSelectedIndex());
        $this->assertSame('/clear', $this->state->selected()->display);
    }

    // ── Helpers ────────────────────────────────────────────────────

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
