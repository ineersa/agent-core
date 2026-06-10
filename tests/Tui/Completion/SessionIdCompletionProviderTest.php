<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Completion;

use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Tui\Completion\CompletionContext;
use Ineersa\Tui\Completion\CompletionSuggestion;
use Ineersa\Tui\Completion\SessionIdCompletionProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for SessionIdCompletionProvider.
 *
 * Uses IsolatedKernelTestCase because HatfieldSessionStore and
 * HatfieldSessionRepository are final (cannot be mocked/stubbed).
 * Tests that only verify context matching (no listSessions() call)
 * work with a fresh store; tests that need session data create
 * sessions via the real store.
 *
 * @see IsolatedKernelTestCase for isolation and setup details
 */
#[CoversClass(SessionIdCompletionProvider::class)]
final class SessionIdCompletionProviderTest extends IsolatedKernelTestCase
{
    private HatfieldSessionStore $sessionStore;
    private SessionIdCompletionProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var HatfieldSessionStore $store */
        $store = self::getContainer()->get(HatfieldSessionStore::class);
        $this->sessionStore = $store;
        $this->provider = new SessionIdCompletionProvider($this->sessionStore);
    }

    // ── Context detection (no listSessions needed) ─────────────────────

    #[Test]
    public function returnsEmptyForResumeWithoutSpace(): void
    {
        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('/resume'),
        );

        self::assertSame([], $suggestions);
    }

    #[Test]
    public function returnsEmptyForRenameWithoutSpace(): void
    {
        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('/rename'),
        );

        self::assertSame([], $suggestions);
    }

    #[Test]
    public function returnsEmptyForNonSessionCommand(): void
    {
        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('/help '),
        );

        self::assertSame([], $suggestions);
    }

    #[Test]
    public function returnsEmptyForTextWithoutSlash(): void
    {
        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('hello world'),
        );

        self::assertSame([], $suggestions);
    }

    #[Test]
    public function returnsEmptyForEmptyText(): void
    {
        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd(''),
        );

        self::assertSame([], $suggestions);
    }

    #[Test]
    public function returnsEmptyForResumeWithTrailingSpaceAfterId(): void
    {
        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('/resume 1 '),
        );

        self::assertSame([], $suggestions);
    }

    #[Test]
    public function returnsEmptyForRenameWithTrailingSpaceAfterId(): void
    {
        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('/rename 1 '),
        );

        self::assertSame([], $suggestions);
    }

    #[Test]
    public function returnsEmptyForNonNumericPrefix(): void
    {
        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('/resume abc'),
        );

        self::assertSame([], $suggestions);
    }

    #[Test]
    public function returnsEmptyForMixedCharsPrefix(): void
    {
        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('/resume 1a'),
        );

        self::assertSame([], $suggestions);
    }

    // ── With session data ──────────────────────────────────────────────

    #[Test]
    public function returnsEmptyWhenNoSessions(): void
    {
        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('/resume '),
        );

        self::assertSame([], $suggestions);
    }

    #[Test]
    public function returnsSuggestionsForResumeCommandWithSpace(): void
    {
        $this->sessionStore->createSession('Session Alpha');

        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('/resume '),
        );

        self::assertNotEmpty($suggestions);
        $displays = array_map(static fn (CompletionSuggestion $s) => $s->display, $suggestions);
        self::assertStringContainsString('Session Alpha', $displays[0]);
    }

    #[Test]
    public function returnsSuggestionsForRAliasWithSpace(): void
    {
        $this->sessionStore->createSession('Session Alpha');

        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('/r '),
        );

        self::assertNotEmpty($suggestions);
    }

    #[Test]
    public function returnsSuggestionsForRenameCommandWithSpace(): void
    {
        $this->sessionStore->createSession('Session Alpha');

        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('/rename '),
        );

        self::assertNotEmpty($suggestions);
        $displays = array_map(static fn (CompletionSuggestion $s) => $s->display, $suggestions);
        self::assertStringContainsString('Session Alpha', $displays[0]);
    }

    #[Test]
    public function filtersBySessionIdPrefix(): void
    {
        $id1 = $this->sessionStore->createSession('Session One');
        $this->sessionStore->createSession('Session Two');
        $this->sessionStore->createSession('Session Three');

        // The DB auto-increment assigns IDs 1, 2, 3.
        // Filter by "1" should match session 1 only.
        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('/resume 1'),
        );

        self::assertCount(1, $suggestions);
        self::assertSame($id1.' ', $suggestions[0]->insertText);
    }

    #[Test]
    public function suggestionReplacementRangeForResume(): void
    {
        $id = $this->sessionStore->createSession('Important Session');

        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('/resume '.$id),
        );

        self::assertCount(1, $suggestions);
        $suggestion = $suggestions[0];

        // replacementStart = strlen('/resume ') = 8
        // replacementLength = strlen($id)
        self::assertSame(8, $suggestion->replacementStart);
        self::assertSame(\strlen($id), $suggestion->replacementLength);
        self::assertSame($id.' ', $suggestion->insertText);
        self::assertSame('Session '.$id, $suggestion->description);
    }

    #[Test]
    public function suggestionReplacementRangeForRename(): void
    {
        $id = $this->sessionStore->createSession('Session Ninety-Nine');

        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('/rename '.$id),
        );

        self::assertCount(1, $suggestions);
        $suggestion = $suggestions[0];

        // replacementStart = strlen('/rename ') = 8
        self::assertSame(8, $suggestion->replacementStart);
        self::assertSame(\strlen($id), $suggestion->replacementLength);
        self::assertSame($id.' ', $suggestion->insertText);
    }

    #[Test]
    public function suggestionReplacementRangeForShortAlias(): void
    {
        $id = $this->sessionStore->createSession('Session Three');

        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('/r '),
        );

        self::assertCount(1, $suggestions);
        $suggestion = $suggestions[0];

        // replacementStart = strlen('/r ') = 3
        self::assertSame(3, $suggestion->replacementStart);
        self::assertSame(0, $suggestion->replacementLength);
        self::assertSame($id.' ', $suggestion->insertText);
    }
}
