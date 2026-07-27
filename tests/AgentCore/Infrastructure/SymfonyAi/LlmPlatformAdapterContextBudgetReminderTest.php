<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\DynamicToolDescriptionProcessor;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface as SymfonyPlatformInterface;

/**
 * @covers \Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter
 *
 * Thesis: context-budget reminder is injected as provider system guidance
 * while canonical RunState.messages remain unchanged.
 */
#[AllowMockObjectsWithoutExpectations]
final class LlmPlatformAdapterContextBudgetReminderTest extends TestCase
{
    public function testApplyContextBudgetReminderMergesSystemText(): void
    {
        $adapter = $this->makeAdapter();
        $method = new \ReflectionMethod(LlmPlatformAdapter::class, 'applyContextBudgetReminder');

        $bag = new MessageBag(
            Message::forSystem('base system'),
            Message::ofUser('hello'),
        );

        $merged = $method->invoke($adapter, $bag, 'Context is nearly exhausted. Finish now.');
        $this->assertStringContainsString('base system', (string) $merged->getSystemMessage()?->getContent());
        $this->assertStringContainsString('Context is nearly exhausted. Finish now.', (string) $merged->getSystemMessage()?->getContent());

        // Original bag is not mutated by withSystemMessage clone path.
        $this->assertSame('base system', (string) $bag->getSystemMessage()?->getContent());

        $unchanged = $method->invoke($adapter, $bag, null);
        $this->assertSame('base system', (string) $unchanged->getSystemMessage()?->getContent());

        $emptyBase = $method->invoke($adapter, new MessageBag(Message::ofUser('only user')), 'Finish now.');
        $this->assertSame('Finish now.', (string) $emptyBase->getSystemMessage()?->getContent());
    }

    public function testResolveContextMessagesDoesNotEmbedReminder(): void
    {
        $adapter = $this->makeAdapter();
        $method = new \ReflectionMethod(LlmPlatformAdapter::class, 'resolveContextMessages');

        $messages = [
            new AgentMessage('system', [['type' => 'text', 'text' => 'base system']]),
            new AgentMessage('user', [['type' => 'text', 'text' => 'hello']]),
        ];

        $resolved = $method->invoke($adapter, new \Ineersa\AgentCore\Domain\Model\ModelInvocationInput(
            messages: $messages,
            contextBudgetReminderText: 'Context is nearly exhausted. Finish now.',
        ));

        $this->assertCount(2, $resolved);
        $this->assertSame('base system', $resolved[0]->content[0]['text'] ?? null);
        $this->assertStringNotContainsString('nearly exhausted', (string) ($resolved[0]->content[0]['text'] ?? ''));
    }

    private function makeAdapter(): LlmPlatformAdapter
    {
        return new LlmPlatformAdapter(
            runStore: new InMemoryRunStore(),
            messageConverter: new AgentMessageConverter(),
            toolDescriptionProcessor: new DynamicToolDescriptionProcessor(),
            platform: $this->createMock(SymfonyPlatformInterface::class),
            transformContextHooks: [],
            convertToLlmHooks: [],
            streamObserver: null,
            costCalculator: null,
            logger: new NullLogger(),
        );
    }
}
