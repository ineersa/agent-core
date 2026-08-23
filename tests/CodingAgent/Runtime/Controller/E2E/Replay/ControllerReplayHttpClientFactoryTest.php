<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\Replay;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\Stream\SseStream;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * @covers \Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\Replay\ControllerReplayHttpClientFactory
 */
final class ControllerReplayHttpClientFactoryTest extends TestCase
{
    #[Test]
    public function requestMatcherSelectsFixtureByLastUserMessageWithoutFifoOrder(): void
    {
        $dir = TestDirectoryIsolation::createOsTempDir('replay-factory');

        $fixtureA = $dir.'/a.json';
        $fixtureB = $dir.'/b.json';
        file_put_contents($fixtureA, json_encode([
            'model' => 'llama_cpp/test',
            'deltas' => [['type' => 'text', 'content' => 'turn-one']],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 1, 'total_tokens' => 101],
            'stop_reason' => 'stop',
            'replay_match' => ['last_user_contains' => 'FIRST_PROMPT_MARKER'],
        ], \JSON_THROW_ON_ERROR));
        file_put_contents($fixtureB, json_encode([
            'model' => 'llama_cpp/test',
            'deltas' => [['type' => 'text', 'content' => 'turn-two']],
            'usage' => ['input_tokens' => 5000, 'output_tokens' => 1, 'total_tokens' => 5001],
            'stop_reason' => 'stop',
            'replay_match' => ['last_user_contains' => 'SECOND_PROMPT_MARKER'],
        ], \JSON_THROW_ON_ERROR));

        $_ENV['HATFIELD_LLM_REPLAY_FIXTURE_PATH'] = $fixtureA.';'.$fixtureB;
        $_SERVER['HATFIELD_LLM_REPLAY_FIXTURE_PATH'] = $fixtureA.';'.$fixtureB;

        try {
            $client = ControllerReplayHttpClientFactory::create();
            $this->assertInstanceOf(MockHttpClient::class, $client);

            $secondBody = json_encode([
                'messages' => [
                    ['role' => 'user', 'content' => 'SECOND_PROMPT_MARKER follow-up text'],
                ],
            ], \JSON_THROW_ON_ERROR);
            $second = $client->request('POST', 'http://replay.internal/v1/chat/completions', ['body' => $secondBody]);
            $secondContent = $second->getContent();
            $this->assertStringContainsString('turn-two', $secondContent);
            $this->assertStringNotContainsString('turn-one', $secondContent);

            $firstBody = json_encode([
                'messages' => [
                    ['role' => 'user', 'content' => 'FIRST_PROMPT_MARKER start text'],
                ],
            ], \JSON_THROW_ON_ERROR);
            $first = $client->request('POST', 'http://replay.internal/v1/chat/completions', ['body' => $firstBody]);
            $firstContent = $first->getContent();
            $this->assertStringContainsString('turn-one', $firstContent);
        } finally {
            unset($_ENV['HATFIELD_LLM_REPLAY_FIXTURE_PATH'], $_SERVER['HATFIELD_LLM_REPLAY_FIXTURE_PATH']);
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    #[Test]
    public function compactionPromptMatcherSelectsCompactionFixture(): void
    {
        $dir = TestDirectoryIsolation::createOsTempDir('replay-factory');

        $assistant = $dir.'/assistant.json';
        $summary = $dir.'/summary.json';
        file_put_contents($assistant, json_encode([
            'model' => 'llama_cpp/test',
            'deltas' => [['type' => 'text', 'content' => 'assistant']],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 1, 'total_tokens' => 101],
            'stop_reason' => 'stop',
            'replay_match' => ['last_user_contains' => 'USER_TURN'],
        ], \JSON_THROW_ON_ERROR));
        file_put_contents($summary, json_encode([
            'model' => 'llama_cpp/test',
            'deltas' => [['type' => 'text', 'content' => 'summary-text']],
            'usage' => ['input_tokens' => 600, 'output_tokens' => 1, 'total_tokens' => 601],
            'stop_reason' => 'stop',
            'replay_match' => ['compaction_prompt' => true],
        ], \JSON_THROW_ON_ERROR));

        $_ENV['HATFIELD_LLM_REPLAY_FIXTURE_PATH'] = $assistant.';'.$summary;
        $_SERVER['HATFIELD_LLM_REPLAY_FIXTURE_PATH'] = $assistant.';'.$summary;

        try {
            $client = ControllerReplayHttpClientFactory::create();
            $this->assertInstanceOf(MockHttpClient::class, $client);

            $body = json_encode([
                'messages' => [
                    ['role' => 'user', 'content' => 'You are performing a CONTEXT CHECKPOINT COMPACTION. Summarize.'],
                ],
            ], \JSON_THROW_ON_ERROR);
            $response = $client->request('POST', 'http://replay.internal/v1/chat/completions', ['body' => $body]);
            $content = $response->getContent();
            $this->assertStringContainsString('summary-text', $content);
        } finally {
            unset($_ENV['HATFIELD_LLM_REPLAY_FIXTURE_PATH'], $_SERVER['HATFIELD_LLM_REPLAY_FIXTURE_PATH']);
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    #[Test]
    public function sseChunkDelayMsSpacesSseStreamObservationsAcrossWallClock(): void
    {
        $dir = TestDirectoryIsolation::createOsTempDir('replay-factory-pace');
        $fixture = $dir.'/paced.json';
        file_put_contents($fixture, json_encode([
            'model' => 'llama_cpp/test',
            'sse_chunk_delay_ms' => 120,
            'deltas' => [
                ['type' => 'text', 'content' => 'A'],
                ['type' => 'text', 'content' => 'B'],
                ['type' => 'text', 'content' => 'C'],
            ],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 3, 'total_tokens' => 4],
            'stop_reason' => 'stop',
            'replay_match' => ['last_user_contains' => 'PACE_PROMPT'],
        ], \JSON_THROW_ON_ERROR));

        $_ENV['HATFIELD_LLM_REPLAY_FIXTURE_PATH'] = $fixture;
        $_SERVER['HATFIELD_LLM_REPLAY_FIXTURE_PATH'] = $fixture;

        try {
            $client = ControllerReplayHttpClientFactory::create();
            $this->assertInstanceOf(StreamPacingHttpClient::class, $client);

            $esc = new EventSourceHttpClient($client);
            $response = $esc->request('POST', 'http://replay.internal/v1/chat/completions', [
                'body' => json_encode([
                    'messages' => [['role' => 'user', 'content' => 'PACE_PROMPT']],
                ], \JSON_THROW_ON_ERROR),
            ]);

            $t0 = microtime(true);
            $texts = [];
            $times = [];
            foreach ((new SseStream())->stream($response) as $data) {
                $text = $data['choices'][0]['delta']['content'] ?? null;
                if (!\is_string($text) || '' === $text) {
                    continue;
                }
                $texts[] = $text;
                $times[] = microtime(true) - $t0;
            }

            $this->assertSame(['A', 'B', 'C'], $texts);
            $this->assertGreaterThanOrEqual(0.10, $times[1] - $times[0]);
            $this->assertGreaterThanOrEqual(0.10, $times[2] - $times[1]);
        } finally {
            unset($_ENV['HATFIELD_LLM_REPLAY_FIXTURE_PATH'], $_SERVER['HATFIELD_LLM_REPLAY_FIXTURE_PATH']);
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    #[Test]
    public function exhaustedFifoQueueFailsLoudlyInsteadOfSyntheticDone(): void
    {
        $dir = TestDirectoryIsolation::createOsTempDir('replay-factory-exhausted');
        $fixture = $dir.'/one.json';
        file_put_contents($fixture, json_encode([
            'model' => 'llama_cpp/test',
            'deltas' => [['type' => 'text', 'content' => 'only-one']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2],
            'stop_reason' => 'stop',
        ], \JSON_THROW_ON_ERROR));

        $_ENV['HATFIELD_LLM_REPLAY_FIXTURE_PATH'] = $fixture;
        $_SERVER['HATFIELD_LLM_REPLAY_FIXTURE_PATH'] = $fixture;

        try {
            $client = ControllerReplayHttpClientFactory::create();
            $this->assertInstanceOf(MockHttpClient::class, $client);

            $first = $client->request('POST', 'http://replay.internal/v1/chat/completions', [
                'body' => json_encode([
                    'messages' => [['role' => 'user', 'content' => 'first']],
                ], \JSON_THROW_ON_ERROR),
            ]);
            $this->assertSame(200, $first->getStatusCode());
            $this->assertStringContainsString('only-one', $first->getContent());

            $second = $client->request('POST', 'http://replay.internal/v1/chat/completions', [
                'body' => json_encode([
                    'messages' => [['role' => 'user', 'content' => 'second']],
                ], \JSON_THROW_ON_ERROR),
            ]);
            $this->assertSame(500, $second->getStatusCode());
            $this->assertSame(['1'], $second->getHeaders(false)['x-replay-exhausted'] ?? null);
            $this->assertStringContainsString('Replay fixture queue exhausted', $second->getContent(false));
            $this->assertStringNotContainsString('"content":"done"', $second->getContent(false));
        } finally {
            unset($_ENV['HATFIELD_LLM_REPLAY_FIXTURE_PATH'], $_SERVER['HATFIELD_LLM_REPLAY_FIXTURE_PATH']);
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }
}
