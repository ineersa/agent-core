<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\OutputCapConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tool\OutputCap;
use Ineersa\CodingAgent\Tool\OutputCapLlmTransformHook;
use Ineersa\CodingAgent\Tool\OutputCapToolResultProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

use function Symfony\Component\String\u;

/**
 * Dense behavioral proof for OutputCap: capping, persistence, cleanup, path
 * classification, notice text, and primary/late-stage agreement.
 *
 * Thesis: notices, classification, persistence, sanitization, and notification
 * contracts fail if production OutputCap behavior diverges.
 */
final class OutputCapTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('hatfield-output-cap-test');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            TestDirectoryIsolation::removeDirectory($this->tmpDir);
        }
    }

    public function testSmallTextIsNotCapped(): void
    {
        $cap = $this->outputCap(new OutputCapConfig(storageDir: $this->tmpDir));
        $this->assertNull($cap->capIfNeeded('Hello, world!', 'test-run'));
        $this->assertNull($cap->capIfNeeded('', 'test-run'));
    }

    public function testDefaultRetentionRemainsTwentyFourHours(): void
    {
        $this->assertSame(86400, new OutputCapConfig()->retentionSeconds);
    }

    public function testConfiguredRootWithSymlinkedPathComponentPersistsInsideCanonicalRoot(): void
    {
        $canonicalRoot = $this->tmpDir.'/canonical-output-cap';
        mkdir($canonicalRoot, 0750);
        $linkedRoot = $this->tmpDir.'/linked-output-cap';
        symlink($canonicalRoot, $linkedRoot);
        $path = $this->outputCap(new OutputCapConfig(storageDir: $linkedRoot))->persist('content', 'run');

        $this->assertStringStartsWith($canonicalRoot.'/', $path);
        $this->assertFileExists($path);
        unlink($linkedRoot); // TestDirectoryIsolation intentionally owns directories, not symlink roots.
    }

    public function testSymlinkedConfiguredRootStaleCleanupRespectsCanonicalScopeLock(): void
    {
        $canonicalRoot = $this->tmpDir.'/canonical-output-cap';
        mkdir($canonicalRoot, 0750);
        $linkedRoot = $this->tmpDir.'/linked-output-cap';
        symlink($canonicalRoot, $linkedRoot);
        $runId = 'locked-run';
        $scopeName = 'run-'.hash('sha256', $runId);
        $scope = $canonicalRoot.'/'.$scopeName;
        mkdir($scope, 0750);
        touch($scope, time() - 2);

        $lockFactory = new LockFactory(new FlockStore($this->tmpDir));
        $lock = $lockFactory->createLock('output-cap:'.hash('sha256', $canonicalRoot.'/'.$scopeName));
        $lock->acquire(true);
        try {
            (new OutputCap(
                new OutputCapConfig(storageDir: $linkedRoot, retentionSeconds: 1),
                $lockFactory,
                new NullLogger(),
            ))->cleanup();
        } finally {
            $lock->release();
            unlink($linkedRoot); // TestDirectoryIsolation intentionally owns directories, not symlink roots.
        }

        $this->assertDirectoryExists($scope);
    }

    public function testTextExactlyAtCapBoundaryIsNotCapped(): void
    {
        $cap = $this->outputCap(new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 10));
        $this->assertNull($cap->capIfNeeded('1234567890', 'test-run'));
    }

    public function testOversizedTextProducesNoticePersistenceAndMetrics(): void
    {
        $cap = $this->outputCap(new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 10));
        $text = str_repeat('a', 100);

        $result = $cap->capIfNeeded($text, 'test-run');
        $this->assertNotNull($result);
        $this->assertSame(100, $result->charCount);
        $this->assertSame(25, $result->tokenEstimate);
        $this->assertSame(10, $result->cap);
        $this->assertFileExists($result->savedPath);
        $this->assertStringEqualsFile($result->savedPath, $text);
        $this->assertStringContainsString($this->tmpDir, $result->savedPath);
        $this->assertStringContainsString('Output capped', $result->noticeText);
        $this->assertStringContainsString('100', $result->noticeText);
        $this->assertStringContainsString('25', $result->noticeText);
        $this->assertStringContainsString('Do not rerun the original command', $result->noticeText);
        $this->assertStringContainsString('read(path:', $result->noticeText);
        $this->assertStringContainsString('limit: 200', $result->noticeText);
        $this->assertStringContainsString('without offset+limit', $result->noticeText);
        $this->assertMatchesRegularExpression('/read\\(path: "[^"]+",/', $result->noticeText);
        $this->assertStringNotContainsString($text, $result->noticeText);
    }

    public function testPersistCreatesRunScopedFileWithRestrictiveDirectories(): void
    {
        $nestedDir = $this->tmpDir.'/nested/subdir';
        $cap = $this->outputCap(new OutputCapConfig(storageDir: $nestedDir));
        $path = $cap->persist('prefixed content', 'run-abc123');

        $this->assertFileExists($path);
        $this->assertStringEqualsFile($path, 'prefixed content');
        $this->assertTrue(str_starts_with($path, '/'));
        $this->assertStringContainsString('run-'.hash('sha256', 'run-abc123').'/', $path);
        $this->assertStringEndsWith('.txt', $path);
        $this->assertDirectoryExists($nestedDir);
        $perms = fileperms($nestedDir) & 0777;
        $this->assertLessThanOrEqual(0750, $perms);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.txt$/', basename($path));
    }

    public function testPersistThrowsOnUnwritableDirectory(): void
    {
        $cap = $this->outputCap(new OutputCapConfig(
            storageDir: '/proc/hatfield-output-cap-blocked-'.bin2hex(random_bytes(4)),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to create output cap storage directory');
        $cap->persist('should fail', 'test-run');
    }

    public function testDocLikePathsUseDocCapAndNullUsesDefault(): void
    {
        $cap = $this->outputCap(new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 50, docCap: 100));
        $text = str_repeat('a', 75);

        $this->assertNotNull($cap->capIfNeeded($text, 'test-run', '/path/to/file.php'));
        $this->assertNull($cap->capIfNeeded($text, 'test-run', '/path/to/file.md'));
        $this->assertNull($cap->capIfNeeded($text, 'test-run', '/path/to/file.MD'));
        $this->assertNull($cap->capIfNeeded($text, 'test-run', '/path/to/file.txt'));
        $this->assertNull($cap->capIfNeeded($text, 'test-run', '/path/to/file.toon'));
        $this->assertNotNull($cap->capIfNeeded($text, 'test-run'));
    }

    public function testCleanupDeletesStaleAndPreservesRecentAndMissingDir(): void
    {
        $cap = $this->outputCap(new OutputCapConfig(storageDir: $this->tmpDir, retentionSeconds: 3600));
        $fresh = $cap->persist('fresh', 'fresh-run');
        $stale = $cap->persist('stale', 'stale-run');
        touch($stale, time() - 7200);
        touch(\dirname($stale), time() - 7200);

        $cap->cleanup();
        $this->assertFileExists($fresh);
        $this->assertFileDoesNotExist($stale);

        $missing = $this->outputCap(new OutputCapConfig(storageDir: $this->tmpDir.'/nonexistent'));
        $missing->cleanup();
        $this->assertTrue(true);
    }

    public function testPersistTriggersCleanupOnFirstUse(): void
    {
        @mkdir($this->tmpDir, 0750, true);
        $cap = $this->outputCap(new OutputCapConfig(storageDir: $this->tmpDir, retentionSeconds: 3600));
        $oldPath = $this->tmpDir.'/'.date('Ymd', time() - 7200).'-'.bin2hex(random_bytes(8)).'.txt';
        file_put_contents($oldPath, 'old data');
        touch($oldPath, time() - 7200);

        $newPath = $cap->persist('new data', 'test-run');
        $this->assertFileDoesNotExist($oldPath);
        $this->assertFileExists($newPath);
    }

    public function testReadContextualNoticeUsesOriginalPathAndOffset(): void
    {
        $cap = $this->outputCap(new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 10));
        $result = $cap->capIfNeeded(str_repeat('x', 20), 'test-run', 'src/Foo.php');
        $this->assertNotNull($result);

        $notice = $cap->buildContextualNotice('read', ['path' => 'src/Foo.php', 'offset' => 40], $result);
        $this->assertStringContainsString('read(path: "src/Foo.php", offset: 40, limit: 200)', $notice);
        $this->assertStringContainsString('Do not repeat the original full read', $notice);
        $this->assertStringContainsString(escapeshellarg('src/Foo.php'), $notice);

        $generic = $cap->buildContextualNotice('bash', ['command' => 'ls'], $result);
        $this->assertSame($result->noticeText, $generic);

        // Typed read calls arrive flat (DTO fields at the top level).
        $flatNotice = $cap->buildContextualNotice('read', ['path' => 'src/Foo.php', 'offset' => 40], $result);
        $this->assertStringContainsString('read(path: "src/Foo.php", offset: 40, limit: 200)', $flatNotice);
    }

    #[DataProvider('documentClassificationProvider')]
    public function testResolveCapPathClassifiesDocumentCases(
        ?string $toolName,
        array $arguments,
        bool $isError,
        ?string $expectedPath,
    ): void {
        $cap = $this->outputCap(new OutputCapConfig(storageDir: $this->tmpDir));
        $this->assertSame(
            $expectedPath,
            $cap->resolveCapPath($toolName, $arguments, $isError),
        );
    }

    /**
     * @return iterable<string, array{0: ?string, 1: array<string, mixed>, 2: bool, 3: ?string}>
     */
    public static function documentClassificationProvider(): iterable
    {
        yield 'markdown read path' => ['read', ['path' => 'docs/settings.md'], false, 'docs/settings.md'];
        yield 'raw tool with top-level arguments key stays flat' => [
            'mcp_search',
            ['arguments' => ['path' => 'docs/settings.md'], 'query' => 'x'],
            false,
            null,
        ];
        yield 'raw tool flat path is still extracted' => ['mcp_fetch', ['path' => 'a.txt'], false, 'a.txt'];
        yield 'file_path key' => ['read', ['file_path' => './a.php'], false, './a.php'];
        yield 'file key' => ['write', ['file' => 'x.txt'], false, 'x.txt'];
        yield 'hatfield_docs list is default' => [
            'hatfield_docs',
            ['operation' => 'list'],
            false,
            null,
        ];
        yield 'hatfield_docs read is document' => [
            'hatfield_docs',
            ['operation' => 'read', 'id' => 'settings'],
            false,
            'hatfield-docs-read.md',
        ];
        yield 'hatfield_docs read error is default' => [
            'hatfield_docs',
            ['operation' => 'read', 'id' => 'settings'],
            true,
            null,
        ];
        yield 'agent_resume handoff is document' => [
            'agent_resume',
            ['artifact_id' => 'agent_abc', 'task' => 'continue'],
            false,
            'handoff-report.md',
        ];
        yield 'agent_retrieve handoff is document' => [
            'agent_retrieve',
            ['artifact_id' => 'agent_abc'],
            false,
            'handoff-report.md',
        ];
        yield 'failed fork is default' => ['fork', ['task' => 'x'], true, null];
        yield 'bash remains default' => ['bash', ['command' => 'ls'], false, null];
        yield 'settings dotted key is never a path' => [
            'settings',
            ['operation' => 'read', 'path' => 'docs.example.md'],
            false,
            null,
        ];
    }

    public function testPrimaryAndLateHookAgreeOnDocClassificationAndSettingsDefault(): void
    {
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 20000, docCap: 50000);
        $outputCap = $this->outputCap($cfg);
        $processor = new OutputCapToolResultProcessor($outputCap, \Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory::denormalizer());
        $hook = new OutputCapLlmTransformHook($outputCap, \Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory::denormalizer());

        $body = str_repeat('D', 25000);
        $this->assertGreaterThan(20000, u($body)->length());
        $this->assertLessThan(50000, u($body)->length());

        $docsCall = new ToolCall(
            toolCallId: 'call-docs-1',
            toolName: 'hatfield_docs',
            arguments: ['operation' => 'read', 'id' => 'settings'],
            orderIndex: 0,
            runId: 'test-run',
        );
        $docsResult = new ToolResult(
            toolCallId: 'call-docs-1',
            toolName: 'hatfield_docs',
            content: [['type' => 'text', 'text' => $body]],
            details: ['raw_result' => $body],
            isError: false,
        );
        $processedDocs = $processor->process($docsResult, $docsCall);
        $this->assertSame($body, $processedDocs->content[0]['text'] ?? null);
        $this->assertArrayNotHasKey('output_cap', \is_array($processedDocs->details) ? $processedDocs->details : []);

        $docsMessage = new AgentMessage(
            role: 'tool',
            content: [['type' => 'text', 'text' => $body]],
            toolCallId: 'call-docs-1',
            toolName: 'hatfield_docs',
            details: ['arguments' => ['operation' => 'read', 'id' => 'settings']],
        );
        $this->assertSame($body, $hook->transformContext([$docsMessage], null, 'test-run')[0]->content[0]['text'] ?? null);

        $handoff = str_repeat('S', 20478);
        $subCall = new ToolCall(
            toolCallId: 'call-sub-1',
            toolName: 'subagent',
            arguments: ['task' => 'scout'],
            orderIndex: 0,
            runId: 'test-run',
        );
        $subResult = new ToolResult(
            toolCallId: 'call-sub-1',
            toolName: 'subagent',
            content: [['type' => 'text', 'text' => $handoff]],
            details: ['raw_result' => $handoff],
            isError: false,
        );
        $this->assertSame($handoff, $processor->process($subResult, $subCall)->content[0]['text'] ?? null);
        $subMessage = new AgentMessage(
            role: 'tool',
            content: [['type' => 'text', 'text' => $handoff]],
            toolCallId: 'call-sub-1',
            toolName: 'subagent',
            details: ['arguments' => ['task' => 'scout']],
        );
        $this->assertSame($handoff, $hook->transformContext([$subMessage], null, 'test-run')[0]->content[0]['text'] ?? null);

        $large = str_repeat('K', 25000);
        $settingsCall = new ToolCall(
            toolCallId: 'call-settings-1',
            toolName: 'settings',
            arguments: ['operation' => 'read', 'path' => 'docs.example.md'],
            orderIndex: 0,
            runId: 'test-run',
        );
        $settingsResult = new ToolResult(
            toolCallId: 'call-settings-1',
            toolName: 'settings',
            content: [['type' => 'text', 'text' => $large]],
            details: ['raw_result' => $large],
            isError: false,
        );
        $processedSettings = $processor->process($settingsResult, $settingsCall);
        $details = \is_array($processedSettings->details) ? $processedSettings->details : [];
        $this->assertArrayHasKey('output_cap', $details);
        $this->assertSame(20000, $details['output_cap']['cap']);

        $settingsMessage = new AgentMessage(
            role: 'tool',
            content: [['type' => 'text', 'text' => $large]],
            toolCallId: 'call-settings-1',
            toolName: 'settings',
            details: ['arguments' => ['operation' => 'read', 'path' => 'docs.example.md']],
        );
        $transformedSettings = $hook->transformContext([$settingsMessage], null, 'test-run');
        $this->assertStringContainsString('Output capped', (string) ($transformedSettings[0]->content[0]['text'] ?? ''));
        $this->assertStringContainsString('20000-char cap', (string) ($transformedSettings[0]->content[0]['text'] ?? ''));
    }

    public function testHatfieldDocsReadOverDocCapIsCappedAtFiftyK(): void
    {
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 20000, docCap: 50000);
        $processor = new OutputCapToolResultProcessor($this->outputCap($cfg), \Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory::denormalizer());
        $body = str_repeat('Z', 50001);
        $toolCall = new ToolCall(
            toolCallId: 'call-docs-over',
            toolName: 'hatfield_docs',
            arguments: ['operation' => 'read', 'id' => 'agents'],
            orderIndex: 0,
            runId: 'test-run',
        );
        $result = new ToolResult(
            toolCallId: 'call-docs-over',
            toolName: 'hatfield_docs',
            content: [['type' => 'text', 'text' => $body]],
            details: ['raw_result' => $body],
            isError: false,
        );

        $processed = $processor->process($result, $toolCall);
        $details = \is_array($processed->details) ? $processed->details : [];
        $this->assertArrayHasKey('output_cap', $details);
        $this->assertSame(50000, $details['output_cap']['cap']);
        $this->assertStringContainsString('hatfield_docs completed', (string) ($processed->content[0]['text'] ?? ''));
    }

    public function testCleanupFailureLogsOnlyStructuredCategoryWithoutSensitiveScopeData(): void
    {
        $logger = new TestLogger();
        $cap = new OutputCap(
            new OutputCapConfig(storageDir: $this->tmpDir),
            new LockFactory(new FlockStore($this->tmpDir)),
            $logger,
        );
        $runId = 'sensitive-run-id';
        file_put_contents($this->tmpDir.'/run-'.hash('sha256', $runId), 'not a directory');

        $cap->cleanupRun($runId, 'shutdown');

        $this->assertCount(1, $logger->records);
        $record = $logger->records[0];
        $this->assertSame('output_cap.session_cleanup_failed', $record['message']);
        $this->assertSame('operation_exception', $record['context']['failure_kind']);
        $encoded = json_encode($record['context'], \JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($runId, $encoded);
        $this->assertStringNotContainsString($this->tmpDir, $encoded);
    }

    public function testTraversalLikeRunIdStaysInHashedScopeAndCleanupUnlinksScopeSymlinkWithoutFollowingTarget(): void
    {
        $cap = $this->outputCap(new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 1));
        $path = $cap->persist('owned', '../../not-a-path');
        $this->assertSame(
            $this->tmpDir.'/run-'.hash('sha256', '../../not-a-path'),
            \dirname($path),
        );

        $target = $this->tmpDir.'/outside-target';
        mkdir($target, 0750);
        $targetFile = $target.'/preserved.txt';
        file_put_contents($targetFile, 'outside');
        $symlinkRunId = 'symlink-run';
        $scope = $this->tmpDir.'/run-'.hash('sha256', $symlinkRunId);
        symlink($target, $scope);

        $cap->cleanupRun($symlinkRunId, 'starting');
        $cap->cleanupRun($symlinkRunId, 'starting');

        $this->assertFileExists($targetFile);
        $this->assertFileDoesNotExist($scope);
    }

    private function outputCap(OutputCapConfig $config): OutputCap
    {
        return new OutputCap($config, new LockFactory(new FlockStore($this->tmpDir)), new NullLogger());
    }
}
