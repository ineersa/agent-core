<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppConfigResolver;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\TranscriptEntry;
use PHPUnit\Framework\TestCase;

final class HatfieldSessionStoreTest extends TestCase
{
    private string $tempDir = '';
    private HatfieldSessionStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/hatfield-session-test-'.getmypid();
        if (is_dir($this->tempDir)) {
            $this->rmDir($this->tempDir);
        }
        mkdir($this->tempDir, 0777, true);

        // Create a minimal project dir with .hatfield and config
        mkdir($this->tempDir.'/.hatfield', 0777, true);
        mkdir($this->tempDir.'/config', 0777, true);

        // Write a defaults YAML with sessions path pointing to our temp dir
        file_put_contents($this->tempDir.'/config/hatfield.defaults.yaml', <<<'YAML'
tui:
    theme: cyberpunk
    theme_paths:
        - '%kernel.project_dir%/config/themes'
sessions:
    path: .hatfield/sessions
YAML);

        // Write empty project settings
        file_put_contents($this->tempDir.'/.hatfield/settings.yaml', '');

        $pathResolver = new SettingsPathResolver($this->tempDir);
        $loader = new AppConfigLoader($pathResolver);
        $configResolver = new AppConfigResolver($loader, $this->tempDir);

        $this->store = new HatfieldSessionStore($configResolver, $this->tempDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (is_dir($this->tempDir)) {
            $this->rmDir($this->tempDir);
        }
    }

    public function testCreateSessionCreatesDirectoryAndMetadata(): void
    {
        $sessionId = $this->store->createSession($this->tempDir, 'Hello');

        // Directory exists
        $sessionPath = $this->tempDir.'/.hatfield/sessions/'.$sessionId;
        self::assertDirectoryExists($sessionPath);

        // Metadata YAML created
        self::assertFileExists($sessionPath.'/metadata.yaml');
        $meta = $this->store->loadMetadata($this->tempDir, $sessionId);
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
        self::assertFileExists($sessionPath.'/runtime-events.jsonl');

        // Empty transcript
        $entries = $this->store->getTranscript($this->tempDir, $sessionId);
        self::assertCount(0, $entries);
    }

    public function testAppendAndLoadTranscript(): void
    {
        $sessionId = $this->store->createSession($this->tempDir);

        $this->store->appendTranscriptEntry($this->tempDir, $sessionId, new TranscriptEntry(
            role: 'user',
            text: 'Hello world',
            meta: ['session_id' => $sessionId],
        ));

        $this->store->appendTranscriptEntry($this->tempDir, $sessionId, new TranscriptEntry(
            role: 'assistant',
            text: 'Hi there!',
            meta: ['run_id' => 'abc123', 'seq' => 1],
        ));

        $entries = $this->store->getTranscript($this->tempDir, $sessionId);
        self::assertCount(2, $entries);

        self::assertSame('user', $entries[0]->role);
        self::assertSame('Hello world', $entries[0]->text);
        self::assertSame('assistant', $entries[1]->role);
        self::assertSame('Hi there!', $entries[1]->text);
        self::assertSame('abc123', $entries[1]->meta['run_id']);
    }

    public function testAppendTranscriptPreservesOrder(): void
    {
        $sessionId = $this->store->createSession($this->tempDir);

        for ($i = 1; $i <= 5; ++$i) {
            $this->store->appendTranscriptEntry($this->tempDir, $sessionId, new TranscriptEntry(
                role: 'user',
                text: "Message {$i}",
            ));
        }

        $entries = $this->store->getTranscript($this->tempDir, $sessionId);
        self::assertCount(5, $entries);
        self::assertSame('Message 1', $entries[0]->text);
        self::assertSame('Message 5', $entries[4]->text);
    }

    public function testResumeMissingSessionReturnsNull(): void
    {
        $meta = $this->store->loadMetadata($this->tempDir, 'nonexistent');
        self::assertNull($meta);
    }

    public function testExistsReturnsFalseForMissingSession(): void
    {
        self::assertFalse($this->store->exists($this->tempDir, 'nonexistent'));
    }

    public function testUpdateMetadataMergesFields(): void
    {
        $sessionId = $this->store->createSession($this->tempDir);

        $this->store->updateMetadata($this->tempDir, $sessionId, [
            'run_id' => 'run-456',
            'model' => 'deepseek-v4',
        ]);

        $meta = $this->store->loadMetadata($this->tempDir, $sessionId);
        self::assertNotNull($meta);
        self::assertSame('run-456', $meta['run_id']);
        self::assertSame('deepseek-v4', $meta['model']);
        self::assertArrayHasKey('session_id', $meta); // Original field preserved
        self::assertArrayHasKey('updated_at', $meta);
    }

    public function testAppendRuntimeEvent(): void
    {
        $sessionId = $this->store->createSession($this->tempDir);

        $this->store->appendRuntimeEvent($this->tempDir, $sessionId, [
            'v' => 1,
            'type' => 'run_started',
            'runId' => 'abc',
            'seq' => 1,
            'payload' => ['prompt' => 'test'],
        ]);

        $sessionPath = $this->tempDir.'/.hatfield/sessions/'.$sessionId;
        $content = file_get_contents($sessionPath.'/runtime-events.jsonl');
        self::assertNotFalse($content);
        self::assertStringContainsString('run_started', $content);
        self::assertStringContainsString('abc', $content);
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

    public function testCreateSessionWithDifferentProjects(): void
    {
        // Create a second project dir
        $tempDir2 = sys_get_temp_dir().'/hatfield-session-test-2-'.getmypid();
        if (is_dir($tempDir2)) {
            $this->rmDir($tempDir2);
        }
        mkdir($tempDir2, 0777, true);
        mkdir($tempDir2.'/.hatfield', 0777, true);
        mkdir($tempDir2.'/config', 0777, true);
        file_put_contents($tempDir2.'/config/hatfield.defaults.yaml', "tui:\n    theme: cyberpunk\nsessions:\n    path: .hatfield/sessions\n");
        file_put_contents($tempDir2.'/.hatfield/settings.yaml', '');

        try {
            $pathResolver2 = new SettingsPathResolver($tempDir2);
            $loader2 = new AppConfigLoader($pathResolver2);
            $resolver2 = new AppConfigResolver($loader2, $tempDir2);
            $store2 = new HatfieldSessionStore($resolver2, $tempDir2);

            $id1 = $this->store->createSession($this->tempDir, 'project one');
            $id2 = $store2->createSession($tempDir2, 'project two');

            // Different sessions
            self::assertNotSame($id1, $id2);

            // Different directories
            self::assertDirectoryExists($this->tempDir.'/.hatfield/sessions/'.$id1);
            self::assertDirectoryExists($tempDir2.'/.hatfield/sessions/'.$id2);

            // Cross-project lookup returns null
            self::assertNull($this->store->loadMetadata($tempDir2, $id1));
            self::assertNull($store2->loadMetadata($this->tempDir, $id2));
        } finally {
            if (is_dir($tempDir2)) {
                $this->rmDir($tempDir2);
            }
        }
    }

    public function testGenerateIdReturns12CharHex(): void
    {
        $id1 = $this->store->generateId();
        $id2 = $this->store->generateId();

        self::assertSame(12, \strlen($id1));
        self::assertSame(12, \strlen($id2));
        self::assertNotSame($id1, $id2, 'Generated IDs must be unique');
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $id1);
    }

    public function testCreateSessionWithExplicitId(): void
    {
        $explicitId = 'aaabbbcccddd';
        $returnedId = $this->store->createSession($this->tempDir, 'test', $explicitId);

        self::assertSame($explicitId, $returnedId);
        self::assertDirectoryExists($this->tempDir.'/.hatfield/sessions/'.$explicitId);

        $meta = $this->store->loadMetadata($this->tempDir, $explicitId);
        self::assertNotNull($meta);
        self::assertSame($explicitId, $meta['session_id']);
        self::assertSame($explicitId, $meta['run_id'], 'run_id must equal session_id');
    }

    public function testResolveSessionsBasePath(): void
    {
        $basePath = $this->store->resolveSessionsBasePath($this->tempDir);

        self::assertSame($this->tempDir.'/.hatfield/sessions', $basePath);

        // The resolved base path must match what createSession uses
        $sessionId = $this->store->createSession($this->tempDir);
        $expectedSessionDir = $basePath.'/'.$sessionId;
        self::assertDirectoryExists($expectedSessionDir);
    }

    public function testResolveSessionsBasePathForDifferentProjects(): void
    {
        $tempDir2 = sys_get_temp_dir().'/hatfield-session-test-resolve-'.getmypid();
        if (is_dir($tempDir2)) {
            $this->rmDir($tempDir2);
        }
        mkdir($tempDir2, 0777, true);
        mkdir($tempDir2.'/.hatfield', 0777, true);
        mkdir($tempDir2.'/config', 0777, true);
        file_put_contents($tempDir2.'/config/hatfield.defaults.yaml', "tui:\n    theme: cyberpunk\nsessions:\n    path: .hatfield/sessions\n");
        file_put_contents($tempDir2.'/.hatfield/settings.yaml', '');

        try {
            $pathResolver2 = new SettingsPathResolver($tempDir2);
            $loader2 = new AppConfigLoader($pathResolver2);
            $resolver2 = new AppConfigResolver($loader2, $tempDir2);
            $store2 = new HatfieldSessionStore($resolver2, $tempDir2);

            $basePath1 = $this->store->resolveSessionsBasePath($this->tempDir);
            $basePath2 = $store2->resolveSessionsBasePath($tempDir2);

            self::assertNotSame($basePath1, $basePath2, 'Different projects must resolve different sessions base paths');
            self::assertSame($this->tempDir.'/.hatfield/sessions', $basePath1);
            self::assertSame($tempDir2.'/.hatfield/sessions', $basePath2);
        } finally {
            if (is_dir($tempDir2)) {
                $this->rmDir($tempDir2);
            }
        }
    }

    private function rmDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            $entry->isDir() ? rmdir((string) $entry) : unlink((string) $entry);
        }
        rmdir($dir);
    }
}
