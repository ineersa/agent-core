<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class HatfieldSessionStoreTest extends IsolatedKernelTestCase
{
    private HatfieldSessionStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var HatfieldSessionStore $store */
        $store = self::getContainer()->get(HatfieldSessionStore::class);
        $this->store = $store;
    }

    public function testCreateSessionCreatesDirectoryAndMetadata(): void
    {
        $sessionId = $this->store->createSession('Hello');

        // ID is a numeric string from DB auto-increment.
        $this->assertNotEmpty($sessionId);
        $this->assertMatchesRegularExpression('/^\d+$/', $sessionId);

        // Directory exists
        $sessionPath = $this->store->resolveSessionsBasePath().'/'.$sessionId;
        $this->assertDirectoryExists($sessionPath);

        // Metadata lives in the DB, not as metadata.yaml
        $this->assertFileDoesNotExist($sessionPath.'/metadata.yaml');
        $session = $this->store->findSession($sessionId);
        $this->assertNotNull($session);
        $this->assertSame($sessionId, (string) $session->id);
        $this->assertSame($sessionId, (string) $session->id, 'session_id must equal run_id');
        $this->assertNull($session->parentId, 'parent_id must be null for new sessions');
        $this->assertNull($session->rootId, 'root_id must be null for new sessions');
        $this->assertSame('Hello', $session->prompt);
        $this->assertInstanceOf(\DateTimeImmutable::class, $session->createdAt);
        // Name is always present as a non-empty string.

        $this->assertSame('Hello', $session->name);

        $this->assertTrue(Uuid::isValid($session->providerCacheKey));
        $this->assertInstanceOf(UuidV7::class, Uuid::fromString($session->providerCacheKey));

        $sessionAgain = $this->store->findSession($sessionId);
        $this->assertSame($session->providerCacheKey, $sessionAgain->providerCacheKey);

        // Core files created (no metadata.yaml)
        $this->assertFileExists($sessionPath.'/state.json');
        $this->assertFileExists($sessionPath.'/events.jsonl');
        $this->assertFileDoesNotExist($sessionPath.'/transcript.jsonl');
    }

    public function testExistsReturnsFalseForMissingSession(): void
    {
        $this->assertFalse($this->store->exists('nonexistent-session-id'));
    }

    public function testFindSessionReturnsNullForMissingSession(): void
    {
        $this->assertNull($this->store->findSession('nonexistent-session-id'));
    }

    public function testUpdateMetadataMergesFields(): void
    {
        $sessionId = $this->store->createSession();

        $this->store->updateMetadata($sessionId, [
            'run_id' => 'run-456', // ignored — run_id always equals session_id
            'model' => 'deepseek-v4',
        ]);

        $session = $this->store->findSession($sessionId);
        $this->assertNotNull($session);
        $this->assertSame($sessionId, (string) $session->id, 'run_id always equals session_id (id from DB)');
        $this->assertSame('deepseek-v4', $session->model);

        $this->assertInstanceOf(\DateTimeImmutable::class, $session->updatedAt);
    }

    public function testCreateSessionReturnsAutoIncrementIds(): void
    {
        // Creates two sessions; DB auto-increment ensures distinct numeric IDs.
        $id1 = $this->store->createSession('session one');
        $id2 = $this->store->createSession('session two');

        $this->assertNotSame($id1, $id2);
        $this->assertMatchesRegularExpression('/^\d+$/', $id1);
        $this->assertMatchesRegularExpression('/^\d+$/', $id2);

        $this->assertDirectoryExists($this->store->resolveSessionsBasePath().'/'.$id1);
        $this->assertDirectoryExists($this->store->resolveSessionsBasePath().'/'.$id2);

        $session1 = $this->store->findSession($id1);
        $this->assertNotNull($session1);
        $this->assertSame('session one', $session1->prompt);
        // Name is derived from prompt
        $this->assertSame('session one', $session1->name);

        $session2 = $this->store->findSession($id2);
        $this->assertNotNull($session2);
        $this->assertSame('session two', $session2->prompt);
        $this->assertSame('session two', $session2->name);
    }

    public function testResolveSessionsBasePath(): void
    {
        $basePath = $this->store->resolveSessionsBasePath();
        $this->assertNotEmpty($basePath);

        // The resolved base path must match what createSession uses
        $sessionId = $this->store->createSession();
        $expectedSessionDir = $basePath.'/'.$sessionId;
        $this->assertDirectoryExists($expectedSessionDir);
    }

    // ── Session name generation ───────────────────────────────────────────

    public function testCreateSessionGeneratesNameFromPrompt(): void
    {
        $sessionId = $this->store->createSession('Write a README');

        $session = $this->store->findSession($sessionId);
        $this->assertNotNull($session);

        $this->assertSame('Write a README', $session->name);
    }

    public function testCreateSessionGeneratesNameFromLongMultilinePrompt(): void
    {
        // Prompt with newlines, tabs, and excessive internal whitespace
        $prompt = "Write\ta comprehensive\n\nREADME   for the   project";
        $sessionId = $this->store->createSession($prompt);

        $session = $this->store->findSession($sessionId);
        $this->assertNotNull($session);
        // Internal whitespace collapsed to single spaces.
        $this->assertSame(
            'Write a comprehensive README for the project',
            $session->name,
        );
    }

    public function testCreateSessionTruncatesLongPromptName(): void
    {
        // 250 'x' chars — name must be ≤ 200. No ellipsis; plain truncation.
        $longPrompt = str_repeat('x', 250);
        $sessionId = $this->store->createSession($longPrompt);

        $session = $this->store->findSession($sessionId);
        $this->assertNotNull($session);
        $this->assertLessThanOrEqual(200, mb_strlen($session->name));
        $this->assertStringStartsWith('x', $session->name);
        // Truncated with no ellipsis — name is exactly 200 chars.
        $this->assertSame(200, mb_strlen($session->name));
    }

    public function testCreateSessionGeneratesFallbackNameForEmptyPrompt(): void
    {
        $sessionId = $this->store->createSession('');

        $session = $this->store->findSession($sessionId);
        $this->assertNotNull($session);
        $this->assertSame('Session', $session->name);
    }

    public function testCreateSessionGeneratesFallbackNameForWhitespaceOnlyPrompt(): void
    {
        $sessionId = $this->store->createSession("  \n\t  ");

        $session = $this->store->findSession($sessionId);
        $this->assertNotNull($session);
        $this->assertSame('Session', $session->name);
    }

    // ── Session name update (rename / clear) ──────────────────────────────

    public function testUpdateMetadataSetsAndReturnsName(): void
    {
        $sessionId = $this->store->createSession('test');

        $this->store->updateMetadata($sessionId, ['name' => 'My Session']);
        $session = $this->store->findSession($sessionId);
        $this->assertNotNull($session);
        $this->assertSame('My Session', $session->name);
    }

    public function testUpdateMetadataTrimsAndCollapsesName(): void
    {
        $sessionId = $this->store->createSession('test');

        $this->store->updateMetadata($sessionId, ['name' => "  Padded\n\tMultiline  Name  "]);
        $session = $this->store->findSession($sessionId);
        $this->assertNotNull($session);
        // Whitespace trimmed and internal whitespace collapsed to single spaces.
        $this->assertSame('Padded Multiline Name', $session->name);
    }

    public function testUpdateMetadataTruncatesLongName(): void
    {
        $sessionId = $this->store->createSession('test');
        $longName = str_repeat('y', 250);

        $this->store->updateMetadata($sessionId, ['name' => $longName]);
        $session = $this->store->findSession($sessionId);
        $this->assertNotNull($session);
        $this->assertSame(200, mb_strlen($session->name));
        $this->assertStringStartsWith('y', $session->name);
        // No ellipsis suffix.
        $this->assertStringEndsWith('y', $session->name);
    }

    public function testUpdateMetadataFallsBackForEmptyName(): void
    {
        $sessionId = $this->store->createSession('test');

        // Set a name first, then clear via empty string.
        $this->store->updateMetadata($sessionId, ['name' => 'Will Clear']);
        $session = $this->store->findSession($sessionId);
        $this->assertSame('Will Clear', $session->name);

        $this->store->updateMetadata($sessionId, ['name' => '']);
        $session = $this->store->findSession($sessionId);
        // Name stays non-null; falls back to deterministic 'Session'.
        $this->assertSame('Session', $session->name);
    }

    public function testUpdateMetadataFallsBackForNullName(): void
    {
        $sessionId = $this->store->createSession('test');

        $this->store->updateMetadata($sessionId, ['name' => 'To Be Nulled']);
        $session = $this->store->findSession($sessionId);
        $this->assertSame('To Be Nulled', $session->name);

        $this->store->updateMetadata($sessionId, ['name' => null]);
        $session = $this->store->findSession($sessionId);
        // Null → deterministic non-null fallback.
        $this->assertSame('Session', $session->name);
    }

    public function testUpdateMetadataFallsBackForNonStringName(): void
    {
        $sessionId = $this->store->createSession('test');

        $this->store->updateMetadata($sessionId, ['name' => 123]);
        $session = $this->store->findSession($sessionId);
        $this->assertSame('Session', $session->name);
    }

    public function testUpdateMetadataFallsBackForWhitespaceOnlyName(): void
    {
        $sessionId = $this->store->createSession('test');

        $this->store->updateMetadata($sessionId, ['name' => 'Named']);
        $session = $this->store->findSession($sessionId);
        $this->assertSame('Named', $session->name);

        $this->store->updateMetadata($sessionId, ['name' => '   ']);
        $session = $this->store->findSession($sessionId);
        // Whitespace-only → deterministic fallback, never null.
        $this->assertSame('Session', $session->name);
    }

    public function testUpdateMetadataIgnoresUnknownKeys(): void
    {
        $sessionId = $this->store->createSession('test');

        $this->store->updateMetadata($sessionId, ['random_unknown_key' => 'value']);
        $session = $this->store->findSession($sessionId);
        $this->assertNotNull($session);
        $this->assertFalse(property_exists($session, 'random_unknown_key'));
    }

    // ── Session listing / catalog ─────────────────────────────────────────

    public function testListSessionsReturnsAllSessionsDefaultOrder(): void
    {
        $originalClock = Clock::get();
        try {
            Clock::set(new MockClock(new \DateTimeImmutable('2026-06-10 12:00:00')));
            $id1 = $this->store->createSession('first session');

            Clock::set(new MockClock(new \DateTimeImmutable('2026-06-10 12:00:05')));
            $id2 = $this->store->createSession('second session');

            $list = $this->store->listSessions();
            $this->assertCount(2, $list);

            // All sessions returned, sorted by updated_at DESC — most recent first.
            $this->assertSame($id2, $list[0]['sessionId']);
            $this->assertSame($id1, $list[1]['sessionId']);
        } finally {
            Clock::set($originalClock);
        }
    }

    public function testListSessionsReturnsAllSessionsNoLimit(): void
    {
        // Create multiple sessions and verify all are returned;
        // the catalog query no longer applies a max-result cap.
        $ids = [];
        for ($i = 0; $i < 3; ++$i) {
            $ids[] = $this->store->createSession("session {$i}");
        }

        $list = $this->store->listSessions();
        $this->assertCount(3, $list);
        // Verify IDs match — all sessions returned.
        $returnedIds = array_column($list, 'sessionId');
        sort($ids);
        sort($returnedIds);
        $this->assertSame($ids, $returnedIds);
    }

    public function testListSessionsIncludesExpectedFields(): void
    {
        $this->store->createSession('Hello World');

        $list = $this->store->listSessions();
        $this->assertNotEmpty($list);

        $row = $list[0];

        $this->assertArrayHasKey('sessionId', $row);
        $this->assertArrayHasKey('name', $row);
        $this->assertArrayHasKey('displayTitle', $row);
        $this->assertArrayHasKey('cwd', $row);
        $this->assertArrayHasKey('prompt', $row);
        $this->assertArrayHasKey('promptPreview', $row);
        // Stable picker DTO: model fields are always present (nullable).
        $this->assertArrayHasKey('model', $row);
        $this->assertArrayHasKey('model_provider', $row);
        $this->assertArrayHasKey('model_name', $row);
        $this->assertArrayHasKey('reasoning', $row);
        $this->assertArrayHasKey('created_at', $row);
        $this->assertArrayHasKey('updated_at', $row);

        // Name is always a non-empty string, derived from the prompt.
        $this->assertIsString($row['name']);
        $this->assertSame('Hello World', $row['name']);
        $this->assertSame('Hello World', $row['displayTitle']);
        $this->assertNull($row['model']);
        $this->assertNull($row['model_provider']);
        $this->assertNull($row['model_name']);
        $this->assertNull($row['reasoning']);
        $this->assertSame('Hello World', $row['prompt']);
        $this->assertSame('Hello World', $row['promptPreview']);
    }

    public function testListSessionsEmptyWhenNoSessions(): void
    {
        $list = $this->store->listSessions();
        $this->assertIsArray($list);
        $this->assertEmpty($list);
    }

    // ── Display / catalog row shape ───────────────────────────────────────

    public function testDisplayTitleUsesNameWhenSet(): void
    {
        $sessionId = $this->store->createSession('original prompt');

        $this->store->updateMetadata($sessionId, ['name' => 'Renamed Session']);
        $list = $this->store->listSessions();
        $this->assertNotEmpty($list);

        $row = $this->findRow($list, $sessionId);
        $this->assertSame('Renamed Session', $row['name']);
        $this->assertSame('Renamed Session', $row['displayTitle']);
        $this->assertSame('original prompt', $row['prompt']);
    }

    public function testDisplayTitleEqualsGeneratedName(): void
    {
        // Name is derived from the prompt; displayTitle equals name since
        // name is guaranteed non-empty.
        $longPrompt = str_repeat('Write a comprehensive README ', 4);
        $this->assertGreaterThan(60, mb_strlen($longPrompt));

        $sessionId = $this->store->createSession($longPrompt);

        $list = $this->store->listSessions();
        $row = $this->findRow($list, $sessionId);

        // name is the prompt truncated to 200 chars (no ellipsis),
        // with whitespace collapsed.
        $this->assertStringStartsWith('Write a comprehensive', $row['name']);
        $this->assertSame($row['name'], $row['displayTitle']);
        // promptPreview is independently truncated to 60 chars + ellipsis.
        $this->assertStringEndsWith('...', $row['promptPreview'] ?? '');
        $this->assertLessThanOrEqual(63, mb_strlen($row['promptPreview'] ?? ''));
        // name is MUCH longer than promptPreview for a long prompt.
        $this->assertGreaterThan(100, mb_strlen($row['name']));
    }

    public function testDisplayTitleForFallbackName(): void
    {
        // Empty prompt → name = 'Session', displayTitle = 'Session'.
        $sessionId = $this->store->createSession('');

        $list = $this->store->listSessions();
        $row = $this->findRow($list, $sessionId);

        $this->assertSame('Session', $row['name']);
        $this->assertSame('Session', $row['displayTitle']);
        $this->assertNull($row['prompt']);
        $this->assertNull($row['promptPreview']);
    }

    public function testListSessionsDoesNotMutateDbName(): void
    {
        $sessionId = $this->store->createSession('test prompt');

        // Load metadata to capture the generated name.
        $session = $this->store->findSession($sessionId);
        $this->assertNotNull($session);
        $originalName = $session->name;

        // Calling listSessions must not change the persisted name.
        $this->store->listSessions();

        $sessionAfter = $this->store->findSession($sessionId);
        $this->assertSame($originalName, $sessionAfter->name);
    }

    // ── Name with model metadata ──────────────────────────────────────────

    public function testListSessionsIncludesModelFieldsWhenSet(): void
    {
        $sessionId = $this->store->createSession('test');

        $this->store->updateMetadata($sessionId, [
            'model' => 'deepseek/deepseek-v4-pro',
            'model_provider' => 'deepseek',
            'model_name' => 'deepseek-v4-pro',
            'reasoning' => 'high',
        ]);

        $list = $this->store->listSessions();
        $row = $this->findRow($list, $sessionId);

        $this->assertSame('deepseek/deepseek-v4-pro', $row['model'] ?? null);
        $this->assertSame('deepseek', $row['model_provider'] ?? null);
        $this->assertSame('deepseek-v4-pro', $row['model_name'] ?? null);
        $this->assertSame('high', $row['reasoning'] ?? null);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $list
     *
     * @return array<string, mixed>
     */
    private function findRow(array $list, string $sessionId): array
    {
        foreach ($list as $row) {
            if (($row['sessionId'] ?? '') === $sessionId) {
                return $row;
            }
        }

        $this->fail("Session {$sessionId} not found in list");
    }
}
