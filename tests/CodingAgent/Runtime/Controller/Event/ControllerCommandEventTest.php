<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\Event;

use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\AnswerHumanHandler;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\BackgroundBashHandler;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\BootstrapAppliedHandler;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\CancelHandler;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\CompactHandler;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\McpRefreshHandler;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\RepairHandler;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\ResumeHandler;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\SelectHistoryTurnHandler;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\ShellCommandHandler;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\StartRunHandler;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\StopBashHandler;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\UserMessageHandler;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Thesis: the controller dispatches each command under its per-type event
 * name, so every supported wire type must reach exactly the handler it
 * reached when handlers filtered one shared event, and nothing may listen to
 * the class-named event the controller no longer dispatches.
 */
final class ControllerCommandEventTest extends IsolatedKernelTestCase
{
    /** @return iterable<string, array{string, class-string}> */
    public static function commandRoutes(): iterable
    {
        yield 'start_run' => ['start_run', StartRunHandler::class];
        yield 'resume' => ['resume', ResumeHandler::class];
        yield 'user_message' => ['user_message', UserMessageHandler::class];
        yield 'follow_up' => ['follow_up', UserMessageHandler::class];
        yield 'append_message' => ['append_message', UserMessageHandler::class];
        yield 'steer' => ['steer', UserMessageHandler::class];
        yield 'cancel' => ['cancel', CancelHandler::class];
        yield 'compact' => ['compact', CompactHandler::class];
        yield 'repair' => ['repair', RepairHandler::class];
        yield 'mcp_refresh' => ['mcp_refresh', McpRefreshHandler::class];
        yield 'answer_human' => ['answer_human', AnswerHumanHandler::class];
        yield 'shell_command' => ['shell_command', ShellCommandHandler::class];
        yield 'select_history_turn' => ['select_history_turn', SelectHistoryTurnHandler::class];
        yield 'background_bash' => ['background_bash', BackgroundBashHandler::class];
        yield 'background_child' => ['background_child', BackgroundBashHandler::class];
        yield 'background_code_mode' => ['background_code_mode', BackgroundBashHandler::class];
        yield 'stop_code_mode' => ['stop_code_mode', BackgroundBashHandler::class];
        yield 'stop_bash' => ['stop_bash', StopBashHandler::class];
        yield 'bootstrap.applied' => ['bootstrap.applied', BootstrapAppliedHandler::class];
        yield 'bootstrap.cancel' => ['bootstrap.cancel', BootstrapAppliedHandler::class];
    }

    /** @param class-string $handlerClass */
    #[DataProvider('commandRoutes')]
    public function testCommandTypeReachesExactlyItsHandler(string $type, string $handlerClass): void
    {
        $listeners = $this->dispatcher()->getListeners(ControllerCommandEvent::NAME_PREFIX.$type);

        $this->assertCount(1, $listeners);
        $this->assertIsArray($listeners[0]);
        $this->assertInstanceOf($handlerClass, $listeners[0][0]);
    }

    public function testUnknownTypeAndClassNamedEventHaveNoListeners(): void
    {
        $dispatcher = $this->dispatcher();

        $this->assertFalse($dispatcher->hasListeners(ControllerCommandEvent::NAME_PREFIX.'start_rnu'));
        $this->assertFalse($dispatcher->hasListeners(ControllerCommandEvent::class));
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $this->assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
