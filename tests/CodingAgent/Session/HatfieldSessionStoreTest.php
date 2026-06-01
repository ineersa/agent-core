<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\TranscriptEntry;
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

        // Metadata YAML created
        self::assertFileExists($sessionPath.'/metadata.yaml');
        $meta = $this->store->loadMetadata($sessionId);
        self::assertNotNull($meta);
        self::assertSame($sessionId, $meta['session_id']);
        self::assertSame($sessionId, $meta['run_id'], 'session_id must equal run_id');
        self::assertNull($meta['parent_id'], 'parent_id must be null for new sessions');
        self::assertNull($meta['root_id'], 'root_id must be null for new sessions');
        self::assertSame('Hello', $meta['prompt']);
        self::assertArrayHasKey('created_at', $meta);

        // All files created (including agent-core store files)
        self::assertFileExists($sessionPath.'/state.json');
        self::assertFileExists($sessionPath.'/events.jsonl');
        self::assertFileExists($sessionPath.'/transcript.jsonl');

        // Empty transcript
        $entries = $this->store->getTranscript($sessionId);
        self::assertCount(0, $entries);
    }

    public function testAppendAndLoadTranscript(): void
    {
        $sessionId = $this->store->createSession();

        $this->store->appendTranscriptEntry($sessionId, new TranscriptEntry(
            role: 'user',
            text: 'Hello world',
            meta: ['session_id' => $sessionId],
        ));

        $this->store->appendTranscriptEntry($sessionId, new TranscriptEntry(
            role: 'assistant',
            text: 'Hi there!',
            meta: ['run_id' => '123', 'seq' => 1],
        ));

        $entries = $this->store->getTranscript($sessionId);
        self::assertCount(2, $entries);

        self::assertSame('user', $entries[0]->role);
        self::assertSame('Hello world', $entries[0]->text);
        self::assertSame('assistant', $entries[1]->role);
        self::assertSame('Hi there!', $entries[1]->text);
        self::assertSame('123', $entries[1]->meta['run_id']);
    }

    public function testAppendTranscriptPreservesOrder(): void
    {
        $sessionId = $this->store->createSession();

        for ($i = 1; $i <= 5; ++$i) {
            $this->store->appendTranscriptEntry($sessionId, new TranscriptEntry(
                role: 'user',
                text: "Message {$i}",
            ));
        }

        $entries = $this->store->getTranscript($sessionId);
        self::assertCount(5, $entries);
        self::assertSame('Message 1', $entries[0]->text);
        self::assertSame('Message 5', $entries[4]->text);
    }

    public function testResumeMissingSessionReturnsNull(): void
    {
        $meta = $this->store->loadMetadata('nonexistent');
        self::assertNull($meta);
    }

    public function testExistsReturnsFalseForMissingSession(): void
    {
        self::assertFalse($this->store->exists('nonexistent'));
    }

    public function testUpdateMetadataMergesFields(): void
    {
        $sessionId = $this->store->createSession();

        $this->store->updateMetadata($sessionId, [
            'run_id' => 'run-456',
            'model' => 'deepseek-v4',
        ]);

        $meta = $this->store->loadMetadata($sessionId);
        self::assertNotNull($meta);
        self::assertSame('run-456', $meta['run_id']);
        self::assertSame('deepseek-v4', $meta['model']);
        self::assertArrayHasKey('session_id', $meta); // Original field preserved
        self::assertArrayHasKey('updated_at', $meta);
    }

    public function testTranscriptEntryFromArray(): void
    {
        $entry = TranscriptEntry::fromArray([
            'role' => 'user',
            'text' => 'hello',
            'meta' => ['key' => 'val'],
            'created_at' => '2026-05-13T12:00:00+00:00',
        ]);

        self::assertSame('user', $entry->role);
        self::assertSame('hello', $entry->text);
        self::assertSame('val', $entry->meta['key']);
        self::assertSame('2026-05-13T12:00:00+00:00', $entry->createdAt->format('c'));
    }

    public function testTranscriptEntryToArray(): void
    {
        $entry = new TranscriptEntry(
            role: 'assistant',
            text: 'response',
            meta: ['run_id' => 'x'],
        );
        $data = $entry->toArray();

        self::assertSame('assistant', $data['role']);
        self::assertSame('response', $data['text']);
        self::assertSame('x', $data['meta']['run_id']);
        self::assertArrayHasKey('created_at', $data);
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
