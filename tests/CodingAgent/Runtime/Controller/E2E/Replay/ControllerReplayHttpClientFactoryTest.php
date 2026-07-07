<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\Replay;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @covers \Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\Replay\ControllerReplayHttpClientFactory
 *
 * Tests that the replay fixture cursor persists across multiple factory
 * invocations (simulating LLM consumer restart/recycle between turns).
 * Without the file-backed cursor, a recycled consumer would reset the
 * fixture index to 0 and serve the wrong fixture.
 */
final class ControllerReplayHttpClientFactoryTest extends TestCase
{
    private string $tempDir = '';
    private string $origReplayPath = '';
    private string $origServerReplayPath = '';
    private string $origCursorDir = '';
    private string $origServerCursorDir = '';

    protected function setUp(): void
    {
        $this->tempDir = TestDirectoryIsolation::createProjectTempDir('test-replay-cursor');

        // Save original env values for restore.
        $this->origReplayPath = $_ENV['HATFIELD_LLM_REPLAY_FIXTURE_PATH']
            ?? ($_SERVER['HATFIELD_LLM_REPLAY_FIXTURE_PATH'] ?? '');
        $this->origServerReplayPath = $_SERVER['HATFIELD_LLM_REPLAY_FIXTURE_PATH'] ?? '';

        // Set env for the factory.
        $_ENV['HATFIELD_LLM_REPLAY_FIXTURE_PATH'] = '';
        $_SERVER['HATFIELD_LLM_REPLAY_FIXTURE_PATH'] = '';

        $this->origCursorDir = $_ENV['HATFIELD_LLM_REPLAY_CURSOR_DIR'] ?? ($_SERVER['HATFIELD_LLM_REPLAY_CURSOR_DIR'] ?? '');
        $this->origServerCursorDir = $_SERVER['HATFIELD_LLM_REPLAY_CURSOR_DIR'] ?? '';
        unset($_ENV['HATFIELD_LLM_REPLAY_CURSOR_DIR'], $_SERVER['HATFIELD_LLM_REPLAY_CURSOR_DIR']);
    }

    protected function tearDown(): void
    {
        // Restore original env.
        if ('' !== $this->origReplayPath) {
            $_ENV['HATFIELD_LLM_REPLAY_FIXTURE_PATH'] = $this->origReplayPath;
            $_SERVER['HATFIELD_LLM_REPLAY_FIXTURE_PATH'] = $this->origServerReplayPath;
        } else {
            unset($_ENV['HATFIELD_LLM_REPLAY_FIXTURE_PATH'], $_SERVER['HATFIELD_LLM_REPLAY_FIXTURE_PATH']);
        }

        if ('' !== $this->origCursorDir) {
            $_ENV['HATFIELD_LLM_REPLAY_CURSOR_DIR'] = $this->origCursorDir;
            $_SERVER['HATFIELD_LLM_REPLAY_CURSOR_DIR'] = $this->origServerCursorDir;
        } else {
            unset($_ENV['HATFIELD_LLM_REPLAY_CURSOR_DIR'], $_SERVER['HATFIELD_LLM_REPLAY_CURSOR_DIR']);
        }

        if ('' !== $this->tempDir) {
            TestDirectoryIsolation::removeDirectory($this->tempDir);
        }
    }

    public function testCursorPersistsAcrossFactoryInvocations(): void
    {
        // ── Write two fixture files to the temp dir ──
        $fixture1 = [
            'model' => 'llama_cpp/test',
            'reasoning' => 'off',
            'deltas' => [
                ['type' => 'text', 'content' => 'First fixture response'],
            ],
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 50,
                'total_tokens' => 150,
            ],
            'stop_reason' => 'stop',
        ];

        $fixture2 = [
            'model' => 'llama_cpp/test',
            'reasoning' => 'off',
            'deltas' => [
                ['type' => 'text', 'content' => 'Second fixture response'],
            ],
            'usage' => [
                'input_tokens' => 5000,
                'output_tokens' => 80,
                'total_tokens' => 5080,
            ],
            'stop_reason' => 'stop',
        ];

        $fixturePath1 = $this->tempDir.'/.replay-fixture-1.json';
        $fixturePath2 = $this->tempDir.'/.replay-fixture-2.json';

        file_put_contents(
            $fixturePath1,
            json_encode($fixture1, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $fixturePath2,
            json_encode($fixture2, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
        );

        // Set the env var to the fixture paths (semicolon-separated).
        $fixturePathEnv = $fixturePath1.';'.$fixturePath2;
        $_ENV['HATFIELD_LLM_REPLAY_FIXTURE_PATH'] = $fixturePathEnv;
        $_SERVER['HATFIELD_LLM_REPLAY_FIXTURE_PATH'] = $fixturePathEnv;
        $_ENV['HATFIELD_LLM_REPLAY_CURSOR_DIR'] = $this->tempDir;
        $_SERVER['HATFIELD_LLM_REPLAY_CURSOR_DIR'] = $this->tempDir;

        // ── First factory invocation (simulates first consumer process) ──
        $client1 = ControllerReplayHttpClientFactory::create();
        $this->assertInstanceOf(HttpClientInterface::class, $client1);

        $response1 = $client1->request('POST', 'http://replay.internal/v1/chat/completions', [
            'json' => ['model' => 'llama_cpp/test', 'messages' => [['role' => 'user', 'content' => 'turn 1']]],
        ]);
        $this->assertSame(200, $response1->getStatusCode());

        $body1 = $response1->getContent(false);
        $usage1 = self::extractUsageFromSSE($body1);
        $this->assertNotNull($usage1, 'First response must contain usage');
        $this->assertSame(100, $usage1['input_tokens'] ?? 0, 'First fixture should have input_tokens=100');

        // ── Second factory invocation (simulates consumer restart/recycle) ──
        $client2 = ControllerReplayHttpClientFactory::create();
        $this->assertInstanceOf(HttpClientInterface::class, $client2);

        $response2 = $client2->request('POST', 'http://replay.internal/v1/chat/completions', [
            'json' => ['model' => 'llama_cpp/test', 'messages' => [['role' => 'user', 'content' => 'turn 2']]],
        ]);
        $this->assertSame(200, $response2->getStatusCode());

        $body2 = $response2->getContent(false);
        $usage2 = self::extractUsageFromSSE($body2);
        $this->assertNotNull($usage2, 'Second response must contain usage');
        $this->assertSame(5000, $usage2['input_tokens'] ?? 0, 'Second fixture should have input_tokens=5000 (not fixture 0 reused)');

        // ── Third factory invocation (exhaustion) ──
        $client3 = ControllerReplayHttpClientFactory::create();
        $this->assertInstanceOf(HttpClientInterface::class, $client3);

        $response3 = $client3->request('POST', 'http://replay.internal/v1/chat/completions', [
            'json' => ['model' => 'llama_cpp/test', 'messages' => [['role' => 'user', 'content' => 'turn 3']]],
        ]);
        $this->assertSame(200, $response3->getStatusCode());

        // After fixture exhaustion, the fallback response has no usage.
        $headers3 = $response3->getHeaders();
        $this->assertSame('1', $headers3['x-replay-fallback'][0] ?? '', 'Exhausted queue must return fallback response');

        // ── Verify cursor file was cleaned up with temp dir ──
        // (the cursor file is inside tempDir, so tearDown removes it)
    }

    public function testProcessLocalCursorAdvancesWithoutWritingBesideFixtures(): void
    {
        $fixture1 = [
            'model' => 'llama_cpp/test',
            'deltas' => [['type' => 'text', 'content' => 'one']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2],
            'stop_reason' => 'stop',
        ];
        $fixture2 = [
            'model' => 'llama_cpp/test',
            'deltas' => [['type' => 'text', 'content' => 'two']],
            'usage' => ['input_tokens' => 2, 'output_tokens' => 2, 'total_tokens' => 4],
            'stop_reason' => 'stop',
        ];

        $fixtureDir = $this->tempDir.'/fixtures-like-repo';
        mkdir($fixtureDir, 0777, true);
        $fixturePath1 = $fixtureDir.'/fixture-a.json';
        $fixturePath2 = $fixtureDir.'/fixture-b.json';
        file_put_contents($fixturePath1, json_encode($fixture1, \JSON_THROW_ON_ERROR));
        file_put_contents($fixturePath2, json_encode($fixture2, \JSON_THROW_ON_ERROR));

        $fixturePathEnv = $fixturePath1.';'.$fixturePath2;
        $_ENV['HATFIELD_LLM_REPLAY_FIXTURE_PATH'] = $fixturePathEnv;
        $_SERVER['HATFIELD_LLM_REPLAY_FIXTURE_PATH'] = $fixturePathEnv;
        unset($_ENV['HATFIELD_LLM_REPLAY_CURSOR_DIR'], $_SERVER['HATFIELD_LLM_REPLAY_CURSOR_DIR']);

        $client = ControllerReplayHttpClientFactory::create();
        $req = static fn (HttpClientInterface $c) => $c->request('POST', 'http://replay.internal/v1/chat/completions', [
            'json' => ['model' => 'llama_cpp/test', 'messages' => [['role' => 'user', 'content' => 'x']]],
        ]);

        $usage1 = self::extractUsageFromSSE($req($client)->getContent(false));
        $usage2 = self::extractUsageFromSSE($req($client)->getContent(false));
        $this->assertSame(1, $usage1['input_tokens'] ?? 0);
        $this->assertSame(2, $usage2['input_tokens'] ?? 0);

        $glob = glob($fixtureDir.'/.replay-fixture-cursor*') ?: [];
        $this->assertSame([], $glob, 'Must not create cursor files next to fixture paths when cursor dir unset');
    }

    /**
     * Extract the usage payload from an SSE response body.
     *
     * Searches for the SSE data frame that contains a "usage" key and
     * returns its decoded value.  Returns null if no usage frame found.
     *
     * @param string $sseBody the full SSE response body
     *
     * @return array<string, mixed>|null
     */
    private static function extractUsageFromSSE(string $sseBody): ?array
    {
        foreach (explode("\n", $sseBody) as $line) {
            if (!str_starts_with($line, 'data: ')) {
                continue;
            }

            $json = json_decode(substr($line, 6), true);
            if (\is_array($json) && isset($json['usage']) && \is_array($json['usage'])) {
                return $json['usage'];
            }
        }

        return null;
    }
}
