<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Claude\ClaudeObserveHook;
use Ineersa\HatfieldExt\ObservationalMemory\Claude\ClaudeOmSettingsProvider;
use Ineersa\HatfieldExt\ObservationalMemory\Claude\ClaudeTranscriptLineCounter;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ClaudeObserveHookTest extends OmTestCase
{
    private const string SESSION = '0f8fad5b-d9cb-469f-a165-70867728950e';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = $this->directory.'/my_project.v2';
        mkdir($this->root.'/.git', 0o750, true);
        mkdir($this->root.'/.hatfield');
        file_put_contents($this->root.'/.hatfield/settings.yaml', "extensions:\n    settings:\n        observational_memory:\n            librarian: {url: 'http://librarian.test:8093/_mcp', timeout_seconds: 5}\n");
    }

    public function testSendsTheCompleteLineCountOfTheProjectSessionTranscript(): void
    {
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options];

            return new MockResponse('{"ok":true,"contract_version":1,"status":"queued"}', ['http_code' => 202]);
        });
        // The unterminated last record is still being written; Librarian ignores it.
        $transcript = $this->transcript("{\"a\":1}\n\n{\"b\":2}\n{\"partial\"");
        $logger = new TestLogger();

        $this->hook($http, $logger)->handle($this->input($transcript), $this->root);

        $this->assertSame([], $logger->records);
        $this->assertCount(1, $requests);
        [$method, $url, $options] = $requests[0];
        $this->assertSame('POST', $method);
        $this->assertSame('http://librarian.test:8093/api/om/v1/observe', $url);
        $this->assertContains('X-Librarian-Project: '.$this->root, $options['headers']);
        $this->assertSame(
            ['contract_version' => 1, 'source_kind' => 'claude_code', 'session_id' => self::SESSION, 'end_seq' => 3],
            json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    public function testStopKeepsTheSessionCursorAndSessionEndSendsTheFinalSignalAndDropsIt(): void
    {
        $endSeqs = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$endSeqs): MockResponse {
            $endSeqs[] = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR)['end_seq'];

            return new MockResponse('{"ok":true,"contract_version":1,"status":"queued"}', ['http_code' => 202]);
        });
        $transcript = $this->transcript("{}\n{}\n");
        $hook = $this->hook($http, new TestLogger());
        $cursor = $this->directory.'/cursors/'.self::SESSION.'.json';

        $hook->handle($this->input($transcript), $this->root);
        $this->assertFileExists($cursor);
        file_put_contents($transcript, "{}\n", \FILE_APPEND);
        $hook->handle($this->input($transcript, 'SessionEnd'), $this->root);

        $this->assertSame([2, 3], $endSeqs);
        $this->assertFileDoesNotExist($cursor);
    }

    #[DataProvider('inertCases')]
    public function testDoesNothingOutsideItsScope(string $case): void
    {
        $transcript = $this->transcript("{}\n");
        $projectDir = $this->root;
        $worktree = false;
        match ($case) {
            'unconfigured' => unlink($this->root.'/.hatfield/settings.yaml'),
            // Claude Code names the project, and files the transcript, after the start directory.
            'started in subdirectory' => [mkdir($projectDir = $this->root.'/src'), $transcript = $this->transcript("{}\n", $projectDir)],
            'missing transcript' => unlink($transcript),
            'empty transcript' => file_put_contents($transcript, '{"partial"'),
            'linked worktree' => $worktree = true,
        };
        $logger = new TestLogger();

        $this->hook($this->noRequests(), $logger, $worktree)->handle($this->input($transcript), $projectDir);

        $this->assertSame([], $logger->records);
    }

    public static function inertCases(): iterable
    {
        foreach (['unconfigured', 'started in subdirectory', 'missing transcript', 'empty transcript', 'linked worktree'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('mismatchedTranscripts')]
    public function testTranscriptOutsideTheEncodedProjectRootIsReported(string $case): void
    {
        $transcript = $this->transcript("{}\n");
        $transcript = match ($case) {
            // A symlinked start directory or a name Claude Code shortened: Librarian cannot find it either.
            'other project directory' => $this->transcript("{}\n", $this->directory.'/symlinked'),
            'other session file' => \dirname($transcript).'/11111111-2222-4333-8444-555555555555.jsonl',
        };
        $logger = new TestLogger();

        $this->hook($this->noRequests(), $logger)->handle($this->input($transcript), $this->root);

        $this->assertCount(1, $logger->records);
        $this->assertSame('om.claude.transcript_path_mismatch', $logger->records[0]['message']);
        $this->assertSame(self::SESSION, $logger->records[0]['context']['session_id']);
    }

    public static function mismatchedTranscripts(): iterable
    {
        yield 'other project directory' => ['other project directory'];
        yield 'other session file' => ['other session file'];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidHookInputIsLoggedWithoutContent(string $input): void
    {
        $logger = new TestLogger();

        $this->hook($this->noRequests(), $logger)->handle($input, $this->root);

        $this->assertCount(1, $logger->records);
        $this->assertSame('om.claude.observe_hook_failed', $logger->records[0]['message']);
        $this->assertStringNotContainsString('secret-prompt', json_encode($logger->records, \JSON_THROW_ON_ERROR));
    }

    public static function invalidInputs(): iterable
    {
        yield 'not json' => ['secret-prompt'];
        yield 'missing session' => ['{"transcript_path":"/secret-prompt/.jsonl"}'];
        yield 'session is not a uuid' => ['{"session_id":"../secret-prompt","transcript_path":"/tmp/x.jsonl"}'];
        yield 'relative transcript' => ['{"session_id":"'.self::SESSION.'","transcript_path":"secret-prompt.jsonl"}'];
        yield 'wrong type' => ['{"session_id":"'.self::SESSION.'","transcript_path":["secret-prompt"]}'];
    }

    public function testLibrarianFailureIsLoggedAndNeverThrown(): void
    {
        $logger = new TestLogger();

        $this->hook(new MockHttpClient(new MockResponse('', ['http_code' => 503])), $logger)->handle($this->input($this->transcript("{}\n")), $this->root);

        $this->assertCount(1, $logger->records);
        $this->assertSame('om.librarian.observe_failed', $logger->records[0]['message']);
        $this->assertSame(self::SESSION, $logger->records[0]['context']['session_id']);
        $this->assertSame(1, $logger->records[0]['context']['end_seq']);
    }

    private function hook(MockHttpClient $http, TestLogger $logger, bool $worktree = false): ClaudeObserveHook
    {
        $exec = $this->createStub(ExecInterface::class);
        if ($worktree) {
            mkdir($this->directory.'/common/worktree', 0o750, true);
            $exec->method('exec')->willReturn(new ExecResultDTO($this->directory.'/common/worktree'."\n".$this->directory.'/common', '', 0));
        } else {
            $exec->method('exec')->willReturn(new ExecResultDTO($this->root.'/.git'."\n".$this->root.'/.git', '', 0));
        }

        // No HOME layer: only the fixture project's settings are read.
        return new ClaudeObserveHook(new ClaudeOmSettingsProvider(null), new ClaudeTranscriptLineCounter($this->directory.'/cursors', $logger), $exec, $logger, $http);
    }

    private function noRequests(): MockHttpClient
    {
        return new MockHttpClient(static function (): never {
            self::fail('No Librarian request expected.');
        });
    }

    private function transcript(string $content, ?string $startDirectory = null): string
    {
        // Claude Code's project directory name: every non-alphanumeric character becomes '-'.
        $directory = $this->directory.'/claude/projects/'.preg_replace('/[^A-Za-z0-9]/', '-', $startDirectory ?? $this->root);
        if (!is_dir($directory)) {
            mkdir($directory, 0o750, true);
        }
        $path = $directory.'/'.self::SESSION.'.jsonl';
        file_put_contents($path, $content);

        return $path;
    }

    private function input(string $transcript, string $event = 'Stop'): string
    {
        return json_encode(['session_id' => self::SESSION, 'transcript_path' => $transcript, 'cwd' => $this->root, 'hook_event_name' => $event], \JSON_THROW_ON_ERROR);
    }
}
