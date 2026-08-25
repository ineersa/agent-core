<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Messenger;

use Ineersa\CodingAgent\Messenger\MessengerAlarmPrearmListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleSignalEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Test thesis: before later console.signal listeners can fail, an active
 * messenger:consume SIGALRM has already scheduled its next heartbeat.
 */
final class MessengerAlarmPrearmListenerTest extends TestCase
{
    public function testPrearmsExistingMessengerAlarm(): void
    {
        $application = new RecordingApplication(5);

        $this->listener()($this->signalEvent($application, 'messenger:consume', \SIGALRM));

        $this->assertSame([5], $application->prearmedIntervals);
    }

    public function testPrearmsBeforeDefaultPriorityListenerCanFail(): void
    {
        $application = new RecordingApplication(5);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ConsoleEvents::SIGNAL, $this->listener(), MessengerAlarmPrearmListener::PRIORITY);
        $dispatcher->addListener(ConsoleEvents::SIGNAL, function () use ($application): void {
            $this->assertSame([5], $application->prearmedIntervals);

            throw new \RuntimeException('later signal work failed');
        });

        $this->expectExceptionMessage('later signal work failed');
        $dispatcher->dispatch($this->signalEvent($application, 'messenger:consume', \SIGALRM), ConsoleEvents::SIGNAL);
    }

    public function testDoesNotPrearmOtherSignalsOrCommands(): void
    {
        $application = new RecordingApplication(5);

        $listener = $this->listener();
        $listener($this->signalEvent($application, 'messenger:consume', \SIGTERM));
        $listener($this->signalEvent($application, 'cache:clear', \SIGALRM));

        $this->assertSame([], $application->prearmedIntervals);
    }

    public function testDoesNotPrearmWhenNoAlarmIntervalIsConfigured(): void
    {
        $application = new RecordingApplication(null);

        $this->listener()($this->signalEvent($application, 'messenger:consume', \SIGALRM));

        $this->assertSame([], $application->prearmedIntervals);
    }

    private function listener(): MessengerAlarmPrearmListener
    {
        return new MessengerAlarmPrearmListener();
    }

    private function signalEvent(Application $application, string $commandName, int $signal): ConsoleSignalEvent
    {
        $command = new Command($commandName);
        $command->setApplication($application);

        return new ConsoleSignalEvent($command, new ArrayInput([]), new BufferedOutput(), $signal);
    }
}

final class RecordingApplication extends Application
{
    /** @var list<int> */
    public array $prearmedIntervals = [];

    public function __construct(
        private readonly ?int $alarmInterval,
    ) {
        parent::__construct();
    }

    public function getAlarmInterval(): ?int
    {
        return $this->alarmInterval;
    }

    public function setAlarmInterval(?int $seconds): void
    {
        if (null !== $seconds) {
            $this->prearmedIntervals[] = $seconds;
        }
    }
}
