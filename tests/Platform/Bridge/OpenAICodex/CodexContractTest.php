<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\CodexContract;
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\CodexToolNormalizer;
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\CodexToolCallNormalizer;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

final class CodexContractTest extends TestCase
{
    /**
     * Verify that CodexContract::createRequestPayload produces the Responses API
     * format: {input: [...], instructions: "..."} instead of Chat Completions
     * format {messages: [...]}.
     */
    #[DataProvider('requestPayloadProvider')]
    public function testCreateRequestPayload(MessageBag $messageBag, array $expected): void
    {
        $contract = CodexContract::create();
        $model = new CodexModel('gpt-5.5');

        $payload = $contract->createRequestPayload($model, $messageBag, []);

        self::assertEquals($expected, $payload);
    }

    public static function requestPayloadProvider(): \Generator
    {
        yield 'user message only' => [
            new MessageBag(Message::ofUser('Hello')),
            [
                'input' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'input_text', 'text' => 'Hello'],
                        ],
                    ],
                ],
            ],
        ];

        yield 'system + user message' => [
            new MessageBag(
                Message::forSystem('You are a helpful assistant.'),
                Message::ofUser('What is the weather?'),
            ),
            [
                'input' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'input_text', 'text' => 'What is the weather?'],
                        ],
                    ],
                ],
                'instructions' => 'You are a helpful assistant.',
            ],
        ];

        yield 'multi-turn conversation' => [
            new MessageBag(
                Message::ofUser('Hello'),
                Message::ofAssistant('Hi! How can I help?'),
                Message::ofUser('Tell me a joke.'),
            ),
            [
                'input' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'input_text', 'text' => 'Hello'],
                        ],
                    ],
                    [
                        'role' => 'assistant',
                        'type' => 'message',
                        'content' => [
                            ['type' => 'output_text', 'text' => 'Hi! How can I help?'],
                        ],
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'input_text', 'text' => 'Tell me a joke.'],
                        ],
                    ],
                ],
            ],
        ];

        yield 'system + conversation' => [
            new MessageBag(
                Message::forSystem('You are a code assistant.'),
                Message::ofUser('Write a function'),
                Message::ofAssistant('Here is the code.'),
            ),
            [
                'input' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'input_text', 'text' => 'Write a function'],
                        ],
                    ],
                    [
                        'role' => 'assistant',
                        'type' => 'message',
                        'content' => [
                            ['type' => 'output_text', 'text' => 'Here is the code.'],
                        ],
                    ],
                ],
                'instructions' => 'You are a code assistant.',
            ],
        ];

        yield 'with tool call in assistant message' => [
            new MessageBag(
                Message::ofUser('Roll a die'),
                Message::ofAssistant(
                    new ToolCall('call-123', 'roll-die', ['sides' => 6]),
                ),
            ),
            [
                'input' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'input_text', 'text' => 'Roll a die'],
                        ],
                    ],
                    [
                        'id' => 'call-123',
                        'call_id' => 'call-123',
                        'name' => 'roll-die',
                        'arguments' => json_encode(['sides' => 6]),
                        'type' => 'function_call',
                    ],
                ],
            ],
        ];
    }

    public function testItDoesNotContainMessagesKey(): void
    {
        $contract = CodexContract::create();
        $model = new CodexModel('gpt-5.5');
        $messageBag = new MessageBag(Message::ofUser('Hi'));

        $payload = $contract->createRequestPayload($model, $messageBag, []);

        self::assertArrayNotHasKey('messages', $payload);
        self::assertArrayHasKey('input', $payload);
    }

    public function testUserContentIsTypedInputText(): void
    {
        $contract = CodexContract::create();
        $model = new CodexModel('gpt-5.5');
        $messageBag = new MessageBag(Message::ofUser('Hello world'));

        $payload = $contract->createRequestPayload($model, $messageBag, []);

        $userInput = $payload['input'][0];
        self::assertSame('user', $userInput['role']);
        self::assertIsArray($userInput['content']);
        self::assertSame('input_text', $userInput['content'][0]['type']);
        self::assertSame('Hello world', $userInput['content'][0]['text']);
    }

    public function testToolNormalizerIncludesStrictNull(): void
    {
        $normalizer = new CodexToolNormalizer();
        $tool = new Tool(
            reference: new ExecutionReference(self::class),
            name: 'get_weather',
            description: 'Get current weather',
            parameters: ['type' => 'object', 'properties' => ['location' => ['type' => 'string']]],
        );

        $result = $normalizer->normalize($tool, context: ['model' => new CodexModel('gpt-5.5')]);

        self::assertSame('function', $result['type']);
        self::assertSame('get_weather', $result['name']);
        self::assertSame('Get current weather', $result['description']);
        self::assertArrayHasKey('strict', $result);
        self::assertNull($result['strict']);
        self::assertArrayHasKey('parameters', $result);
    }

    public function testToolNormalizerOmitsStrictWhenNoParameters(): void
    {
        $normalizer = new CodexToolNormalizer();
        $tool = new Tool(
            reference: new ExecutionReference(self::class),
            name: 'no_params',
            description: 'Tool with no parameters',
        );

        $result = $normalizer->normalize($tool, context: ['model' => new CodexModel('gpt-5.5')]);

        self::assertArrayNotHasKey('parameters', $result);
        self::assertArrayNotHasKey('strict', $result);
    }

    public function testToolCallNormalizerIncludesIdAndCallId(): void
    {
        $normalizer = new CodexToolCallNormalizer();
        $toolCall = new ToolCall('call-xyz', 'search', ['q' => 'test']);

        $result = $normalizer->normalize($toolCall, context: ['model' => new CodexModel('gpt-5.5')]);

        self::assertSame('call-xyz', $result['id']);
        self::assertSame('call-xyz', $result['call_id']);
        self::assertSame('search', $result['name']);
        self::assertSame('function_call', $result['type']);
        self::assertStringContainsString('"q"', $result['arguments']);
    }
}
