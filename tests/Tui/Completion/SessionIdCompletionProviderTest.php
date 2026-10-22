<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Completion;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\SqlLoggingKernel;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Tui\Completion\CompletionContext;
use Ineersa\Tui\Completion\CompletionProviderRegistry;
use Ineersa\Tui\Completion\CompletionSuggestion;
use Ineersa\Tui\Completion\SessionCompletionSourceInterface;
use Ineersa\Tui\Completion\SessionIdCompletionProvider;
use Ineersa\Tui\Listener\CompletionListener;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;

/**
 * Tests for SessionIdCompletionProvider.
 *
 * Uses IsolatedKernelTestCase because HatfieldSessionStore and
 * HatfieldSessionRepository are final (cannot be mocked/stubbed).
 * Tests that only verify context matching (no listSessions() call)
 * work with a fresh source; tests that need session data create
 * sessions via the real store.
 *
 * @see IsolatedKernelTestCase for isolation and setup details
 */
#[CoversClass(SessionIdCompletionProvider::class)]
final class SessionIdCompletionProviderTest extends IsolatedKernelTestCase
{
    use TuiRuntimeContextBuilderTrait;
    private HatfieldSessionStore $sessionStore;
    private SessionCompletionSourceInterface $sessionSource;
    private SessionIdCompletionProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var HatfieldSessionStore $store */
        $store = self::getContainer()->get(HatfieldSessionStore::class);
        $this->sessionStore = $store;
        /** @var SessionCompletionSourceInterface $source */
        $source = self::getContainer()->get(SessionCompletionSourceInterface::class);
        $this->sessionSource = $source;
        $this->provider = new SessionIdCompletionProvider($this->sessionSource);
    }

    // ── Context detection (no listSessions needed) ─────────────────────

    #[Test]
    public function virtualCompletionUsesBoundedCatalogQueryWithExactTitlesAndPrefix(): void
    {
        $seed = $this->sessionStore->createSession('seed');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $em->getConnection();
        $template = $connection->fetchAssociative('SELECT * FROM hatfield_session WHERE id = ?', [(int) $seed]);
        $this->assertIsArray($template);
        for ($i = 0; $i < 34; ++$i) {
            $row = $template;
            $row['id'] = 1000 + $i;
            $row['provider_cache_key'] = (string) new UuidV7();
            $row['name'] = 'Exact title '.$i;
            $row['updated_at'] = (new \DateTimeImmutable('2026-01-01'))->modify('+'.$i.' seconds')->format('Y-m-d H:i:s');
            $connection->insert('hatfield_session', $row);
        }
        $connection->executeStatement("UPDATE hatfield_session SET name = '', prompt = ? WHERE id = 1033", ['Fallback prompt']);
        $connection->executeStatement("UPDATE hatfield_session SET name = '', prompt = NULL WHERE id = 1032");
        $template['id'] = 2000;
        $template['provider_cache_key'] = (string) new UuidV7();
        $template['name'] = 'NONMATCHING TITLE';
        $template['updated_at'] = '2027-01-01 00:00:00';
        $connection->insert('hatfield_session', $template);
        $em->clear();
        $sql = self::getContainer()->get('test.sql_logger');
        $sql->records = [];

        foreach (['resume', 'r', 'rename'] as $command) {
            $suggestions = $this->provider->getSuggestions(CompletionContext::forCursorAtEnd('/'.$command.' 10'));
            $this->assertCount(30, $suggestions);
            $this->assertSame('#1033 — Fallback prompt', $suggestions[0]->display);
            $this->assertSame('#1032 — Session 1032', $suggestions[1]->display);
            $this->assertSame('#1031 — Exact title 31', $suggestions[2]->display);
            $this->assertSame('1004 ', $suggestions[29]->insertText);
        }
        $queries = array_values(array_filter($sql->records, static fn (array $row): bool => str_contains($row['context']['sql'] ?? '', 'CAST(id AS TEXT) AS session_id')));
        $this->assertCount(3, $queries);
        $this->assertStringContainsString('LIMIT 30', $queries[0]['context']['sql']);
        $this->assertSame([], $em->getUnitOfWork()->getIdentityMap()[HatfieldSession::class] ?? []);

        $harness = new VirtualTuiHarness(columns: 120, rows: 50);
        $context = $this->buildTuiContext()->withTui($harness->tui())
            ->withState(new TuiSessionState('virtual-session-completion'))
            ->withScreen($harness->screen())->build();
        (new CompletionListener(new CompletionProviderRegistry([$this->provider])))->register($context);
        try {
            $harness->startInputLoop();
            $harness->screen()->promptEditor()->typeText('/resume 10');
            $harness->render();
            $harness->sendInput("\t");
            $text = $harness->plainScreenText();
            $this->assertStringContainsString('Completions', $text);
            $this->assertStringContainsString('Fallback prompt', $text);
            $this->assertStringContainsString('Session 1032', $text);
            $this->assertStringNotContainsString('NONMATCHING TITLE', $text);
            $harness->sendInput("\t");
            $this->assertSame('/resume 1033 ', $harness->screen()->promptEditor()->getText());
        } finally {
            $harness->stopInputLoop();
        }
    }

    #[Test]
    public function returnsEmptyForResumeWithoutSpace(): void
    {
        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('/resume'),
        );

        $this->assertSame([], $suggestions);
    }

    #[Test]
    public function returnsEmptyForRenameWithoutSpace(): void
    {
        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('/rename'),
        );

        $this->assertSame([], $suggestions);
    }

    #[Test]
    public function returnsEmptyForNonSessionCommand(): void
    {
        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('/help '),
        );

        $this->assertSame([], $suggestions);
    }

    #[Test]
    public function returnsEmptyForTextWithoutSlash(): void
    {
        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('hello world'),
        );

        $this->assertSame([], $suggestions);
    }

    #[Test]
    public function returnsEmptyForEmptyText(): void
    {
        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd(''),
        );

        $this->assertSame([], $suggestions);
    }

    #[Test]
    public function returnsEmptyForResumeWithTrailingSpaceAfterId(): void
    {
        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('/resume 1 '),
        );

        $this->assertSame([], $suggestions);
    }

    #[Test]
    public function returnsEmptyForRenameWithTrailingSpaceAfterId(): void
    {
        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('/rename 1 '),
        );

        $this->assertSame([], $suggestions);
    }

    #[Test]
    public function returnsEmptyForNonNumericPrefix(): void
    {
        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('/resume abc'),
        );

        $this->assertSame([], $suggestions);
    }

    #[Test]
    public function returnsEmptyForMixedCharsPrefix(): void
    {
        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('/resume 1a'),
        );

        $this->assertSame([], $suggestions);
    }

    // ── With session data ──────────────────────────────────────────────

    #[Test]
    public function returnsEmptyWhenNoSessions(): void
    {
        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('/resume '),
        );

        $this->assertSame([], $suggestions);
    }

    #[Test]
    public function returnsSuggestionsForResumeCommandWithSpace(): void
    {
        $this->sessionStore->createSession('Session Alpha');

        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('/resume '),
        );

        $this->assertNotEmpty($suggestions);
        $displays = array_map(static fn (CompletionSuggestion $s) => $s->display, $suggestions);
        $this->assertStringContainsString('Session Alpha', $displays[0]);
    }

    #[Test]
    public function returnsSuggestionsForRAliasWithSpace(): void
    {
        $this->sessionStore->createSession('Session Alpha');

        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('/r '),
        );

        $this->assertNotEmpty($suggestions);
    }

    #[Test]
    public function returnsSuggestionsForRenameCommandWithSpace(): void
    {
        $this->sessionStore->createSession('Session Alpha');

        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('/rename '),
        );

        $this->assertNotEmpty($suggestions);
        $displays = array_map(static fn (CompletionSuggestion $s) => $s->display, $suggestions);
        $this->assertStringContainsString('Session Alpha', $displays[0]);
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

        $this->assertCount(1, $suggestions);
        $this->assertSame($id1.' ', $suggestions[0]->insertText);
    }

    #[Test]
    public function suggestionReplacementRangeForResume(): void
    {
        $id = $this->sessionStore->createSession('Important Session');

        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('/resume '.$id),
        );

        $this->assertCount(1, $suggestions);
        $suggestion = $suggestions[0];

        // replacementStart = strlen('/resume ') = 8
        // replacementLength = strlen($id)
        $this->assertSame(8, $suggestion->replacementStart);
        $this->assertSame(\strlen($id), $suggestion->replacementLength);
        $this->assertSame($id.' ', $suggestion->insertText);
        $this->assertSame('Session '.$id, $suggestion->description);
    }

    #[Test]
    public function suggestionReplacementRangeForRename(): void
    {
        $id = $this->sessionStore->createSession('Session Ninety-Nine');

        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('/rename '.$id),
        );

        $this->assertCount(1, $suggestions);
        $suggestion = $suggestions[0];

        // replacementStart = strlen('/rename ') = 8
        $this->assertSame(8, $suggestion->replacementStart);
        $this->assertSame(\strlen($id), $suggestion->replacementLength);
        $this->assertSame($id.' ', $suggestion->insertText);
    }

    #[Test]
    public function suggestionReplacementRangeForShortAlias(): void
    {
        $id = $this->sessionStore->createSession('Session Three');

        $suggestions = $this->provider->getSuggestions(
            CompletionContext::forCursorAtEnd('/r '),
        );

        $this->assertCount(1, $suggestions);
        $suggestion = $suggestions[0];

        // replacementStart = strlen('/r ') = 3
        $this->assertSame(3, $suggestion->replacementStart);
        $this->assertSame(0, $suggestion->replacementLength);
        $this->assertSame($id.' ', $suggestion->insertText);
    }

    protected static function createKernel(array $options = []): Kernel
    {
        return new SqlLoggingKernel('test', false);
    }
}
