<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Model;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Extension\Model\ExtensionModelCaller;
use Ineersa\Hatfield\ExtensionApi\Model\AiModelReference;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\ResultConverterInterface;

/**
 * Thesis: callModel bridge uses the injected container platform + standard
 * Symfony Agent; no-toolbox returns native result; toolbox uses AgentProcessor
 * and executes the tool loop.
 */
final class ExtensionModelCallerTest extends TestCase
{
    public function testNoToolboxPassesMessageBagThroughStandardAgentAndReturnsNativeResult(): void
    {
        $bag = new MessageBag(Message::ofUser('hello from extension'));
        $platform = $this->createMock(PlatformInterface::class);
        $platform->expects($this->once())
            ->method('invoke')
            ->with(
                'llama_cpp_test/test',
                $this->identicalTo($bag),
                $this->callback(static fn (array $options): bool => false === ($options['stream'] ?? null)),
            )
            ->willReturn($this->deferredText('native-ok'));

        $caller = new ExtensionModelCaller($platform, new TestLogger());
        $result = $caller->call(
            new AiModelReference('llama_cpp_test', 'test'),
            $bag,
        );

        $this->assertInstanceOf(TextResult::class, $result);
        $this->assertSame('native-ok', $result->getContent());
    }

    public function testToolboxUsesAgentProcessorAndExecutesToolLoop(): void
    {
        $tool = new #[AsTool(name: 'ping', description: 'Ping tool for extension model bridge tests')] class {
            public bool $executed = false;

            public function __invoke(): string
            {
                $this->executed = true;

                return 'pong';
            }
        };
        $toolbox = new Toolbox([$tool]);

        $platform = $this->createMock(PlatformInterface::class);
        $platform->expects($this->exactly(2))
            ->method('invoke')
            ->willReturnOnConsecutiveCalls(
                $this->deferredToolCall('call_1', 'ping'),
                $this->deferredText('tool-loop-done'),
            );

        $caller = new ExtensionModelCaller($platform, new TestLogger());
        $result = $caller->call(
            new AiModelReference('llama_cpp_test', 'test'),
            new MessageBag(Message::ofUser('call ping once')),
            $toolbox,
        );

        $this->assertTrue($tool->executed);
        $this->assertInstanceOf(TextResult::class, $result);
        $this->assertSame('tool-loop-done', $result->getContent());
    }

    public function testProviderFailurePropagatesNativeExceptionAfterPrivacySafeLog(): void
    {
        $platform = $this->createMock(PlatformInterface::class);
        $platform->expects($this->once())
            ->method('invoke')
            ->willThrowException(new \RuntimeException('provider boom'));

        $logger = new TestLogger();
        $caller = new ExtensionModelCaller($platform, $logger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('provider boom');

        try {
            $caller->call(
                new AiModelReference('llama_cpp_test', 'test'),
                new MessageBag(Message::ofUser('x')),
            );
        } finally {
            $this->assertNotSame([], $logger->records);
            $this->assertSame('extension.model_call_failed', $logger->records[0]['message'] ?? null);
            $this->assertSame('extension_model_caller', $logger->records[0]['context']['component'] ?? null);
            $this->assertArrayNotHasKey('exception_message', $logger->records[0]['context'] ?? []);
            $this->assertArrayNotHasKey('prompt', $logger->records[0]['context'] ?? []);
        }
    }

    private function deferredText(string $text): DeferredResult
    {
        $converter = new class($text) implements ResultConverterInterface {
            public function __construct(private string $text)
            {
            }

            public function supports(\Symfony\AI\Platform\Model $model): bool
            {
                return true;
            }

            public function convert(RawResultInterface $result, array $options = []): TextResult
            {
                return new TextResult($this->text);
            }

            public function getTokenUsageExtractor(): ?\Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface
            {
                return null;
            }
        };

        $raw = new class implements RawResultInterface {
            public function getData(): array
            {
                return [];
            }

            public function getDataStream(): \Generator
            {
                yield from [];
            }

            public function getObject(): object
            {
                return new \stdClass();
            }
        };

        return new DeferredResult($converter, $raw);
    }

    private function deferredToolCall(string $id, string $name): DeferredResult
    {
        $converter = new class($id, $name) implements ResultConverterInterface {
            public function __construct(private string $id, private string $name)
            {
            }

            public function supports(\Symfony\AI\Platform\Model $model): bool
            {
                return true;
            }

            public function convert(RawResultInterface $result, array $options = []): ToolCallResult
            {
                return new ToolCallResult([new ToolCall($this->id, $this->name, [])]);
            }

            public function getTokenUsageExtractor(): ?\Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface
            {
                return null;
            }
        };

        $raw = new class implements RawResultInterface {
            public function getData(): array
            {
                return [];
            }

            public function getDataStream(): \Generator
            {
                yield from [];
            }

            public function getObject(): object
            {
                return new \stdClass();
            }
        };

        return new DeferredResult($converter, $raw);
    }
}
