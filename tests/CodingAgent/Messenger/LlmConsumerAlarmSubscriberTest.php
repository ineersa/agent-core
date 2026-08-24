<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Messenger;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Messenger\LlmConsumerAlarmSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleAlarmEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Test thesis: only SIGALRM events for an llm messenger:consume invocation
 * produce the temporary keepalive diagnostic.
 */
final class LlmConsumerAlarmSubscriberTest extends TestCase
{
    private TestLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new TestLogger();
    }

    public function testLogsLlmMessengerConsumeAlarm(): void
    {
        $this->subscriber()($this->alarmEvent('messenger:consume', ['llm', 'tool']));

        $this->assertSame([
            [
                'level' => 'info',
                'message' => 'LLM consumer SIGALRM tick',
                'context' => [
                    'component' => 'messenger',
                    'event_type' => 'messenger.keepalive.alarm_tick',
                    'receivers' => ['llm', 'tool'],
                ],
            ],
        ], $this->logger->records);
    }

    public function testDoesNotLogForNonLlmMessengerConsumeAlarm(): void
    {
        $this->subscriber()($this->alarmEvent('messenger:consume', ['tool']));

        $this->assertSame([], $this->logger->records);
    }

    public function testDoesNotLogForOtherCommandAlarm(): void
    {
        $this->subscriber()($this->alarmEvent('cache:clear', ['llm']));

        $this->assertSame([], $this->logger->records);
    }

    /** @param list<string> $receivers */
    private function alarmEvent(string $commandName, array $receivers): ConsoleAlarmEvent
    {
        $input = new ArrayInput(['receivers' => $receivers]);
        $input->bind(new InputDefinition([
            new InputArgument('receivers', InputArgument::IS_ARRAY),
        ]));

        return new ConsoleAlarmEvent(
            new Command($commandName),
            $input,
            new BufferedOutput(),
        );
    }

    private function subscriber(): LlmConsumerAlarmSubscriber
    {
        return new LlmConsumerAlarmSubscriber($this->logger);
    }
}
