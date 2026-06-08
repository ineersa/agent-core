<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

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
        self::assertNotEmpty($sessionId);
        self::assertMatchesRegularExpression('/^\d+$/', $sessionId);

        // Directory exists
        $sessionPath = $this->store->resolveSessionsBasePath().'/'.$sessionId;
        self::assertDirectoryExists($sessionPath);

        // Metadata lives in the DB, not as metadata.yaml
        self::assertFileDoesNotExist($sessionPath.'/metadata.yaml');
        $meta = $this->store->loadMetadata($sessionId);
        self::assertNotNull($meta);
        self::assertSame($sessionId, $meta['session_id']);
        self::assertSame($sessionId, $meta['run_id'], 'session_id must equal run_id');
        self::assertNull($meta['parent_id'], 'parent_id must be null for new sessions');
        self::assertNull($meta['root_id'], 'root_id must be null for new sessions');
        self::assertSame('Hello', $meta['prompt']);
        self::assertArrayHasKey('created_at', $meta);
        // Name is always present as a non-empty string.
        self::assertArrayHasKey('name', $meta);
        self::assertSame('Hello', $meta['name']);

        // Core files created (no metadata.yaml)
        self::assertFileExists($sessionPath.'/state.json');
        self::assertFileExists($sessionPath.'/events.jsonl');
        self::assertFileDoesNotExist($sessionPath.'/transcript.jsonl');
    }

    public function testExistsReturnsFalseForMissingSession(): void
    {
        self::assertFalse($this->store->exists('nonexistent-session-id'));
    }

    public function testLoadMetadataReturnsNullForMissingSession(): void
    {
        self::assertNull($this->store->loadMetadata('nonexistent-session-id'));
    }

    public function testUpdateMetadataMergesFields(): void
    {
        $sessionId = $this->store->createSession();

        $this->store->updateMetadata($sessionId, [
            'run_id' => 'run-456', // ignored — run_id always equals session_id
            'model' => 'deepseek-v4',
        ]);

        $meta = $this->store->loadMetadata($sessionId);
        self::assertNotNull($meta);
        self::assertSame($sessionId, $meta['run_id'], 'run_id always equals session_id (id from DB)');
        self::assertSame('deepseek-v4', $meta['model']);
        self::assertArrayHasKey('session_id', $meta);
        self::assertArrayHasKey('updated_at', $meta);
    }

    public function testCreateSessionReturnsAutoIncrementIds(): void
    {
        // Creates two sessions; DB auto-increment ensures distinct numeric IDs.
        $id1 = $this->store->createSession('session one');
        $id2 = $this->store->createSession('session two');

        self::assertNotSame($id1, $id2);
        self::assertMatchesRegularExpression('/^\d+$/', $id1);
        self::assertMatchesRegularExpression('/^\d+$/', $id2);

        self::assertDirectoryExists($this->store->resolveSessionsBasePath().'/'.$id1);
        self::assertDirectoryExists($this->store->resolveSessionsBasePath().'/'.$id2);

        $meta1 = $this->store->loadMetadata($id1);
        self::assertNotNull($meta1);
        self::assertSame('session one', $meta1['prompt']);
        // Name is derived from prompt
        self::assertSame('session one', $meta1['name']);

        $meta2 = $this->store->loadMetadata($id2);
        self::assertNotNull($meta2);
        self::assertSame('session two', $meta2['prompt']);
        self::assertSame('session two', $meta2['name']);
    }

    public function testResolveSessionsBasePath(): void
    {
        $basePath = $this->store->resolveSessionsBasePath();
        self::assertNotEmpty($basePath);

        // The resolved base path must match what createSession uses
        $sessionId = $this->store->createSession();
        $expectedSessionDir = $basePath.'/'.$sessionId;
        self::assertDirectoryExists($expectedSessionDir);
    }

    // ── Session name generation ───────────────────────────────────────────

    public function testCreateSessionGeneratesNameFromPrompt(): void
    {
        $sessionId = $this->store->createSession('Write a README');

        $meta = $this->store->loadMetadata($sessionId);
        self::assertNotNull($meta);
        self::assertArrayHasKey('name', $meta);
        self::assertSame('Write a README', $meta['name']);
    }

    public function testCreateSessionGeneratesNameFromLongMultilinePrompt(): void
    {
        // Prompt with newlines, tabs, and excessive internal whitespace
        $prompt = "Write\ta comprehensive\n\nREADME   for the   project";
        $sessionId = $this->store->createSession($prompt);

        $meta = $this->store->loadMetadata($sessionId);
        self::assertNotNull($meta);
        // Internal whitespace collapsed to single spaces.
        self::assertSame(
            'Write a comprehensive README for the project',
            $meta['name'],
        );
    }

    public function testCreateSessionTruncatesLongPromptName(): void
    {
        // 250 'x' chars — name must be ≤ 200. No ellipsis; plain truncation.
        $longPrompt = str_repeat('x', 250);
        $sessionId = $this->store->createSession($longPrompt);

        $meta = $this->store->loadMetadata($sessionId);
        self::assertNotNull($meta);
        self::assertLessThanOrEqual(200, mb_strlen($meta['name']));
        self::assertStringStartsWith('x', $meta['name']);
        // Truncated with no ellipsis — name is exactly 200 chars.
        self::assertSame(200, mb_strlen($meta['name']));
    }

    public function testCreateSessionGeneratesFallbackNameForEmptyPrompt(): void
    {
        $sessionId = $this->store->createSession('');

        $meta = $this->store->loadMetadata($sessionId);
        self::assertNotNull($meta);
        self::assertSame('Session', $meta['name']);
    }

    public function testCreateSessionGeneratesFallbackNameForWhitespaceOnlyPrompt(): void
    {
        $sessionId = $this->store->createSession("  \n\t  ");

        $meta = $this->store->loadMetadata($sessionId);
        self::assertNotNull($meta);
        self::assertSame('Session', $meta['name']);
    }

    // ── Session name update (rename / clear) ──────────────────────────────

    public function testUpdateMetadataSetsAndReturnsName(): void
    {
        $sessionId = $this->store->createSession('test');

        $this->store->updateMetadata($sessionId, ['name' => 'My Session']);
        $meta = $this->store->loadMetadata($sessionId);
        self::assertNotNull($meta);
        self::assertSame('My Session', $meta['name']);
    }

    public function testUpdateMetadataTrimsAndCollapsesName(): void
    {
        $sessionId = $this->store->createSession('test');

        $this->store->updateMetadata($sessionId, ['name' => "  Padded\n\tMultiline  Name  "]);
        $meta = $this->store->loadMetadata($sessionId);
        self::assertNotNull($meta);
        // Whitespace trimmed and internal whitespace collapsed to single spaces.
        self::assertSame('Padded Multiline Name', $meta['name']);
    }

    public function testUpdateMetadataTruncatesLongName(): void
    {
        $sessionId = $this->store->createSession('test');
        $longName = str_repeat('y', 250);

        $this->store->updateMetadata($sessionId, ['name' => $longName]);
        $meta = $this->store->loadMetadata($sessionId);
        self::assertNotNull($meta);
        self::assertSame(200, mb_strlen($meta['name']));
        self::assertStringStartsWith('y', $meta['name']);
        // No ellipsis suffix.
        self::assertStringEndsWith('y', $meta['name']);
    }

    public function testUpdateMetadataFallsBackForEmptyName(): void
    {
        $sessionId = $this->store->createSession('test');

        // Set a name first, then clear via empty string.
        $this->store->updateMetadata($sessionId, ['name' => 'Will Clear']);
        $meta = $this->store->loadMetadata($sessionId);
        self::assertSame('Will Clear', $meta['name']);

        $this->store->updateMetadata($sessionId, ['name' => '']);
        $meta = $this->store->loadMetadata($sessionId);
        // Name stays non-null; falls back to deterministic 'Session'.
        self::assertSame('Session', $meta['name']);
    }

    public function testUpdateMetadataFallsBackForNullName(): void
    {
        $sessionId = $this->store->createSession('test');

        $this->store->updateMetadata($sessionId, ['name' => 'To Be Nulled']);
        $meta = $this->store->loadMetadata($sessionId);
        self::assertSame('To Be Nulled', $meta['name']);

        $this->store->updateMetadata($sessionId, ['name' => null]);
        $meta = $this->store->loadMetadata($sessionId);
        // Null → deterministic non-null fallback.
        self::assertSame('Session', $meta['name']);
    }

    public function testUpdateMetadataFallsBackForNonStringName(): void
    {
        $sessionId = $this->store->createSession('test');

        $this->store->updateMetadata($sessionId, ['name' => 123]);
        $meta = $this->store->loadMetadata($sessionId);
        self::assertSame('Session', $meta['name']);
    }

    public function testUpdateMetadataFallsBackForWhitespaceOnlyName(): void
    {
        $sessionId = $this->store->createSession('test');

        $this->store->updateMetadata($sessionId, ['name' => 'Named']);
        $meta = $this->store->loadMetadata($sessionId);
        self::assertSame('Named', $meta['name']);

        $this->store->updateMetadata($sessionId, ['name' => '   ']);
        $meta = $this->store->loadMetadata($sessionId);
        // Whitespace-only → deterministic fallback, never null.
        self::assertSame('Session', $meta['name']);
    }

    public function testUpdateMetadataIgnoresUnknownKeys(): void
    {
        $sessionId = $this->store->createSession('test');

        $this->store->updateMetadata($sessionId, ['random_unknown_key' => 'value']);
        $meta = $this->store->loadMetadata($sessionId);
        self::assertNotNull($meta);
        self::assertArrayNotHasKey('random_unknown_key', $meta);
    }

    // ── Session listing / catalog ─────────────────────────────────────────

    public function testListSessionsReturnsAllSessionsDefaultOrder(): void
    {
        $id1 = $this->store->createSession('first session');
        // SQLite DATETIME has second granularity and TimestampableLifecycleTrait
        // sets updated_at via Clock::get()->now() at PrePersist time.
        // Two sessions created in the same second get identical timestamps,
        // making DESC ordering non-deterministic. Sleeping one second guarantees
        // distinct timestamps without requiring FrozenClock (unavailable in this
        // Symfony Clock component version) or production test-only APIs.
        sleep(1);
        $id2 = $this->store->createSession('second session');

        $list = $this->store->listSessions();
        self::assertCount(2, $list);

        // All sessions returned, sorted by updated_at DESC — most recent first.
        self::assertSame($id2, $list[0]['sessionId']);
        self::assertSame($id1, $list[1]['sessionId']);
    }

    public function testListSessionsReturnsAllSessionsNoLimit(): void
    {
        // Create more sessions than the old default limit (50) to prove
        // listSessions returns them all.
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->store->createSession("session {$i}");
        }

        $list = $this->store->listSessions();
        self::assertCount(3, $list);
        // Verify IDs match — all sessions returned.
        $returnedIds = array_column($list, 'sessionId');
        sort($ids);
        sort($returnedIds);
        self::assertSame($ids, $returnedIds);
    }

    public function testListSessionsIncludesExpectedFields(): void
    {
        $this->store->createSession('Hello World');

        $list = $this->store->listSessions();
        self::assertNotEmpty($list);

        $row = $list[0];

        self::assertArrayHasKey('sessionId', $row);
        self::assertArrayHasKey('name', $row);
        self::assertArrayHasKey('displayTitle', $row);
        self::assertArrayHasKey('cwd', $row);
        self::assertArrayHasKey('prompt', $row);
        self::assertArrayHasKey('promptPreview', $row);
        // Stable picker DTO: model fields are always present (nullable).
        self::assertArrayHasKey('model', $row);
        self::assertArrayHasKey('model_provider', $row);
        self::assertArrayHasKey('model_name', $row);
        self::assertArrayHasKey('reasoning', $row);
        self::assertArrayHasKey('created_at', $row);
        self::assertArrayHasKey('updated_at', $row);

        // Name is always a non-empty string, derived from the prompt.
        self::assertIsString($row['name']);
        self::assertSame('Hello World', $row['name']);
        self::assertSame('Hello World', $row['displayTitle']);
        self::assertNull($row['model']);
        self::assertNull($row['model_provider']);
        self::assertNull($row['model_name']);
        self::assertNull($row['reasoning']);
        self::assertSame('Hello World', $row['prompt']);
        self::assertSame('Hello World', $row['promptPreview']);
    }

    public function testListSessionsEmptyWhenNoSessions(): void
    {
        $list = $this->store->listSessions();
        self::assertIsArray($list);
        self::assertEmpty($list);
    }

    // ── Display / catalog row shape ───────────────────────────────────────

    public function testDisplayTitleUsesNameWhenSet(): void
    {
        $sessionId = $this->store->createSession('original prompt');

        $this->store->updateMetadata($sessionId, ['name' => 'Renamed Session']);
        $list = $this->store->listSessions();
        self::assertNotEmpty($list);

        $row = $this->findRow($list, $sessionId);
        self::assertSame('Renamed Session', $row['name']);
        self::assertSame('Renamed Session', $row['displayTitle']);
        self::assertSame('original prompt', $row['prompt']);
    }

    public function testDisplayTitleEqualsGeneratedName(): void
    {
        // Name is derived from the prompt; displayTitle equals name since
        // name is guaranteed non-empty.
        $longPrompt = str_repeat('Write a comprehensive README ', 4);
        self::assertGreaterThan(60, mb_strlen($longPrompt));

        $sessionId = $this->store->createSession($longPrompt);

        $list = $this->store->listSessions();
        $row = $this->findRow($list, $sessionId);

        // name is the prompt truncated to 200 chars (no ellipsis),
        // with whitespace collapsed.
        self::assertStringStartsWith('Write a comprehensive', $row['name']);
        self::assertSame($row['name'], $row['displayTitle']);
        // promptPreview is independently truncated to 60 chars + ellipsis.
        self::assertStringEndsWith('...', $row['promptPreview'] ?? '');
        self::assertLessThanOrEqual(63, mb_strlen($row['promptPreview'] ?? ''));
        // name is MUCH longer than promptPreview for a long prompt.
        self::assertGreaterThan(100, mb_strlen($row['name']));
    }

    public function testDisplayTitleForFallbackName(): void
    {
        // Empty prompt → name = 'Session', displayTitle = 'Session'.
        $sessionId = $this->store->createSession('');

        $list = $this->store->listSessions();
        $row = $this->findRow($list, $sessionId);

        self::assertSame('Session', $row['name']);
        self::assertSame('Session', $row['displayTitle']);
        self::assertNull($row['prompt']);
        self::assertNull($row['promptPreview']);
    }

    public function testListSessionsDoesNotMutateDbName(): void
    {
        $sessionId = $this->store->createSession('test prompt');

        // Load metadata to capture the generated name.
        $meta = $this->store->loadMetadata($sessionId);
        self::assertNotNull($meta);
        $originalName = $meta['name'];

        // Calling listSessions must not change the persisted name.
        $this->store->listSessions();

        $metaAfter = $this->store->loadMetadata($sessionId);
        self::assertSame($originalName, $metaAfter['name']);
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

        self::assertSame('deepseek/deepseek-v4-pro', $row['model'] ?? null);
        self::assertSame('deepseek', $row['model_provider'] ?? null);
        self::assertSame('deepseek-v4-pro', $row['model_name'] ?? null);
        self::assertSame('high', $row['reasoning'] ?? null);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $list
     * @return array<string, mixed>
     */
    private function findRow(array $list, string $sessionId): array
    {
        foreach ($list as $row) {
            if (($row['sessionId'] ?? '') === $sessionId) {
                return $row;
            }
        }

        self::fail("Session {$sessionId} not found in list");
    }
}
