<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\Replay;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
}
