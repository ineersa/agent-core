<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\CommandHandler;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Entity\BackgroundProcess;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\StopBashHandler;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Tests\Support\BackgroundProcessFixture;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

final class StopBashHandlerTest extends IsolatedKernelTestCase
{
    public function testFinishedAcceptedRecordReturnsFalseAndRefusesCrossRunOrForeground(): void
    {
        $directory = TestDirectoryIsolation::createProjectTempDir('stop-bash-handler');
        try {
            $manager = self::getContainer()->get(EntityManagerInterface::class);
            $process = new BackgroundProcess();
            $process->sessionId = 'stop-run';
            $process->pid = 2147483647;
            $process->logPath = $directory.'/log';
            $process->statusPath = $directory.'/status';
            BackgroundProcessFixture::finish($process, 0, new \DateTimeImmutable());
            $process->backgroundedAt = new \DateTimeImmutable();
            $manager->persist($process);
            $manager->flush();
            $handler = self::getContainer()->get(StopBashHandler::class);
            $events = [];
            $emit = static function (RuntimeEvent $event) use (&$events): void { $events[] = $event; };
            $handler(new ControllerCommandEvent(new RuntimeCommand('one', 'stop_bash', 'stop-run', ['record_id' => $process->id]), $emit));
            $this->assertSame('bg_process.stop.completed', $events[0]->type);
            $this->assertSame(['commandId' => 'one', 'available' => true, 'stopped' => false], $events[0]->payload);
            $handler(new ControllerCommandEvent(new RuntimeCommand('two', 'stop_bash', 'other-run', ['record_id' => $process->id]), $emit));
            $this->assertSame(['commandId' => 'two', 'available' => false, 'stopped' => false], $events[1]->payload);
            $process->backgroundedAt = null;
            $manager->flush();
            $handler(new ControllerCommandEvent(new RuntimeCommand('three', 'stop_bash', 'stop-run', ['record_id' => $process->id]), $emit));
            $this->assertFalse($events[2]->payload['available']);
        } finally {
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testInvalidIdentityNeverReadsProcesses(): void
    {
        $events = [];
        $handler = self::getContainer()->get(StopBashHandler::class);
        $handler(new ControllerCommandEvent(new RuntimeCommand('invalid', 'stop_bash', '', ['record_id' => 0]), static function (RuntimeEvent $event) use (&$events): void { $events[] = $event; }));
        $this->assertSame(['commandId' => 'invalid', 'available' => false, 'stopped' => false], $events[0]->payload);
    }
}
