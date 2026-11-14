<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\SymfonyAi\Mistral;

use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\Mistral\MistralSymfonyAiProviderBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\Content\Thinking;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MistralSymfonyAiProviderBuilderTest extends TestCase
{
    public function testSupportsOnlyMistralType(): void
    {
        $builder = new MistralSymfonyAiProviderBuilder(new EventDispatcher());
        $this->assertTrue($builder->supports($this->config()));
        $this->assertFalse($builder->supports(new AiProviderConfig(id: 'other', type: 'generic')));
    }

    public function testNativeContractAuthenticatesAndReplaysThinkingWithoutOpenAiFields(): void
    {
        $previous = getenv('HATFIELD_TEST_MISTRAL_KEY');
        putenv('HATFIELD_TEST_MISTRAL_KEY=test-vibe-key');
        try {
            $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
                $this->assertSame('POST', $method);
                $this->assertSame('https://mistral.example/v1/chat/completions', $url);
                $this->assertContains('Authorization: Bearer test-vibe-key', $options['headers']);
                $body = json_decode($options['body'], true, 512, \JSON_THROW_ON_ERROR);
                $this->assertSame('model', $body['model']);
                $this->assertSame('high', $body['reasoning_effort']);
                $this->assertSame(128, $body['max_tokens']);
                $this->assertSame([
                    ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'Consider this']]],
                    ['type' => 'text', 'text' => 'Previous answer'],
                ], $body['messages'][1]['content']);
                $this->assertArrayNotHasKey('reasoning_content', $body['messages'][1]);
                $this->assertSame(['type' => 'object'], $body['tools'][0]['function']['parameters']);

                return new MockResponse('{"choices":[{"message":{"content":"Answer"},"finish_reason":"stop"}]}');
            });
            $provider = (new MistralSymfonyAiProviderBuilder(new EventDispatcher()))->build($this->config('env:HATFIELD_TEST_MISTRAL_KEY'), $http);
            $this->assertTrue($provider->supports('mistral/model'));
            $this->assertFalse($provider->supports('other/model'));
            $this->assertFalse($provider->supports('mistral/unknown'));
            $result = $provider->invoke('mistral/model', new MessageBag(
                Message::ofUser('First'),
                Message::ofAssistant(new Thinking('Consider this'), 'Previous answer'),
                Message::ofUser('Next'),
            ), [
                'reasoning_effort' => 'high',
                'max_output_tokens' => 128,
                'tools' => [new Tool(new ExecutionReference(self::class), 'inspect', 'Inspect workspace')],
            ])->getResult();
            $this->assertInstanceOf(TextResult::class, $result);
            $this->assertSame('Answer', $result->getContent());
        } finally {
            putenv(false === $previous ? 'HATFIELD_TEST_MISTRAL_KEY' : 'HATFIELD_TEST_MISTRAL_KEY='.$previous);
        }
    }

    public function testNativeStreamConvertsThinkingTextToolsAndUsage(): void
    {
        $chunks = [
            ['choices' => [['delta' => ['content' => [['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'Plan']]]]]]]],
            ['choices' => [['delta' => ['content' => [['type' => 'text', 'text' => 'Checking']]]]]],
            ['choices' => [['delta' => ['tool_calls' => [[
                'index' => 0, 'id' => 'call12345', 'type' => 'function',
                'function' => ['name' => 'read', 'arguments' => '{"path":'],
            ]]]]]],
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '"README.md"}']]]], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]],
        ];
        $body = implode('', array_map(static fn (array $chunk): string => 'data: '.json_encode($chunk, \JSON_THROW_ON_ERROR)."\n\n", $chunks))."data: [DONE]\n\n";
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($body): MockResponse {
            $this->assertContains('Authorization: Bearer literal-key', $options['headers']);

            return new MockResponse($body, ['response_headers' => ['content-type: text/event-stream']]);
        });
        $provider = (new MistralSymfonyAiProviderBuilder(new EventDispatcher()))->build($this->config(), $http);
        $result = $provider->invoke('model', new MessageBag(Message::ofUser('Read README.md')), ['stream' => true]);
        $thinking = '';
        $text = '';
        $calls = [];
        foreach ($result->asStream() as $delta) {
            if ($delta instanceof ThinkingDelta) {
                $thinking .= $delta->getThinking();
            } elseif ($delta instanceof TextDelta) {
                $text .= $delta->getText();
            } elseif ($delta instanceof ToolCallComplete) {
                $calls = $delta->getToolCalls();
            }
        }
        $this->assertSame('Plan', $thinking);
        $this->assertSame('Checking', $text);
        $this->assertCount(1, $calls);
        $this->assertSame('call12345', $calls[0]->getId());
        $this->assertSame('read', $calls[0]->getName());
        $this->assertSame(['path' => 'README.md'], $calls[0]->getArguments());
        $usage = $result->getMetadata()->get('token_usage');
        $this->assertInstanceOf(TokenUsageInterface::class, $usage);
        $this->assertSame(10, $usage->getPromptTokens());
        $this->assertSame(5, $usage->getCompletionTokens());
    }

    private function config(string $key = 'literal-key'): AiProviderConfig
    {
        return new AiProviderConfig(
            id: 'mistral', type: 'mistral', enabled: true, baseUrl: 'https://mistral.example', apiKey: $key,
            models: ['model' => new AiModelDefinition(id: 'model', toolCalling: true, reasoning: true)],
        );
    }
}
