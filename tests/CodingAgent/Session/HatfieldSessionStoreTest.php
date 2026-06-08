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

        $meta2 = $this->store->loadMetadata($id2);
        self::assertNotNull($meta2);
        self::assertSame('session two', $meta2['prompt']);
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

    // ── Session name metadata ─────────────────────────────────────────────

    public function testLoadMetadataOmitsNameForUnnamedSession(): void
    {
        $sessionId = $this->store->createSession('test');

        $meta = $this->store->loadMetadata($sessionId);
        self::assertNotNull($meta);
        self::assertArrayNotHasKey('name', $meta, 'Unnamed sessions must not include a name key');
    }

    public function testUpdateMetadataSetsAndReturnsName(): void
    {
        $sessionId = $this->store->createSession('test');

        $this->store->updateMetadata($sessionId, ['name' => 'My Session']);
        $meta = $this->store->loadMetadata($sessionId);
        self::assertNotNull($meta);
        self::assertSame('My Session', $meta['name']);
    }

    public function testUpdateMetadataTrimsName(): void
    {
        $sessionId = $this->store->createSession('test');

        $this->store->updateMetadata($sessionId, ['name' => '  Padded Name  ']);
        $meta = $this->store->loadMetadata($sessionId);
        self::assertNotNull($meta);
        self::assertSame('Padded Name', $meta['name']);
    }

    public function testUpdateMetadataClearsNameOnEmptyString(): void
    {
        $sessionId = $this->store->createSession('test');

        // Set a name first
        $this->store->updateMetadata($sessionId, ['name' => 'Will Clear']);
        $meta = $this->store->loadMetadata($sessionId);
        self::assertSame('Will Clear', $meta['name'] ?? null);

        // Clear via empty string
        $this->store->updateMetadata($sessionId, ['name' => '']);
        $meta = $this->store->loadMetadata($sessionId);
        self::assertArrayNotHasKey('name', $meta, 'Empty string must clear the name');
    }

    public function testUpdateMetadataClearsNameOnNull(): void
    {
        $sessionId = $this->store->createSession('test');

        $this->store->updateMetadata($sessionId, ['name' => 'To Be Nulled']);
        $meta = $this->store->loadMetadata($sessionId);
        self::assertSame('To Be Nulled', $meta['name'] ?? null);

        $this->store->updateMetadata($sessionId, ['name' => null]);
        $meta = $this->store->loadMetadata($sessionId);
        self::assertArrayNotHasKey('name', $meta, 'Null must clear the name');
    }

    public function testUpdateMetadataClearsNameOnWhitespaceOnly(): void
    {
        $sessionId = $this->store->createSession('test');

        // Set a name first, then overwrite with whitespace-only string.
        $this->store->updateMetadata($sessionId, ['name' => 'Named']);
        $meta = $this->store->loadMetadata($sessionId);
        self::assertSame('Named', $meta['name'] ?? null);

        $this->store->updateMetadata($sessionId, ['name' => '   ']);
        $meta = $this->store->loadMetadata($sessionId);
        self::assertArrayNotHasKey('name', $meta, 'Whitespace-only name must be cleared');
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

    public function testListSessionsReturnsCreatedSessionsDefaultOrder(): void
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

        // Default sort is updated_at DESC — most recent first.
        self::assertSame($id2, $list[0]['sessionId']);
        self::assertSame($id1, $list[1]['sessionId']);
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

        self::assertNull($row['name']);
        self::assertNull($row['model']);
        self::assertNull($row['model_provider']);
        self::assertNull($row['model_name']);
        self::assertNull($row['reasoning']);
        self::assertSame('Hello World', $row['prompt']);
        self::assertSame('Hello World', $row['promptPreview']);
    }

    public function testListSessionsSupportsCreatedAtAsc(): void
    {
        $id1 = $this->store->createSession('first');
        $id2 = $this->store->createSession('second');

        $list = $this->store->listSessions('created_at', 50, 'ASC');
        self::assertCount(2, $list);
        self::assertSame($id1, $list[0]['sessionId']);
        self::assertSame($id2, $list[1]['sessionId']);
    }

    public function testListSessionsRespectsLimit(): void
    {
        $this->store->createSession('a');
        $this->store->createSession('b');
        $this->store->createSession('c');

        $list = $this->store->listSessions('updated_at', 2);
        self::assertCount(2, $list);
    }

    public function testListSessionsEmptyWhenNoSessions(): void
    {
        $list = $this->store->listSessions();
        self::assertIsArray($list);
        self::assertEmpty($list);
    }

    // ── Display fallback logic ────────────────────────────────────────────

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

    public function testDisplayTitleFallsBackToPromptPreview(): void
    {
        // Prompt must exceed 60 characters to trigger mb_strimwidth truncation.
        $longPrompt = str_repeat('Write a comprehensive README ', 4);
        self::assertGreaterThan(60, mb_strlen($longPrompt));

        $sessionId = $this->store->createSession($longPrompt);

        $list = $this->store->listSessions();
        $row = $this->findRow($list, $sessionId);

        self::assertNull($row['name']);
        self::assertStringStartsWith('Write a comprehensive', $row['displayTitle']);
        // Prompt is >60 chars, so preview should be truncated with ellipsis.
        self::assertStringEndsWith('...', $row['promptPreview'] ?? '');
        self::assertLessThanOrEqual(63, mb_strlen($row['promptPreview'] ?? ''));
    }

    public function testDisplayTitleFallsBackToSessionIdWhenNoPrompt(): void
    {
        $sessionId = $this->store->createSession('');

        $list = $this->store->listSessions();
        $row = $this->findRow($list, $sessionId);

        self::assertNull($row['name']);
        self::assertNull($row['prompt']);
        self::assertNull($row['promptPreview']);
        self::assertSame("Session {$sessionId}", $row['displayTitle']);
    }

    public function testDisplayFallbackDoesNotMutateDbName(): void
    {
        $sessionId = $this->store->createSession('test prompt');

        // Calling listSessions must not write a displayTitle-derived name
        $this->store->listSessions();

        $meta = $this->store->loadMetadata($sessionId);
        self::assertNotNull($meta);
        self::assertArrayNotHasKey('name', $meta);
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
