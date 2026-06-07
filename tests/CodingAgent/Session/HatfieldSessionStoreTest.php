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

    public function testAppendAndLoadTranscript(): void
    {
        // This test is removed because transcript.jsonl is no longer written.
        // Transcript blocks are rebuilt from events.jsonl on resume.
        $this->expectNotToPerformAssertions();
    }

    public function testAppendTranscriptPreservesOrder(): void
    {
        // This test is removed because transcript.jsonl is no longer written.
        $this->expectNotToPerformAssertions();
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
}
