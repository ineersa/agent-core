<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Application\Pipeline\SourceAcceptance;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SourceAcceptanceTest extends IsolatedKernelTestCase
{
    public static function publicationBoundaries(): iterable
    {
        yield 'after append before publication' => [false];
        yield 'after publication before journal removal' => [true];
    }

    #[DataProvider('publicationBoundaries')]
    public function testEventFreeAcceptanceRecoversAndOutlivesCurrentTokenAndCache(bool $published): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('source recovery');
        $source = new AdvanceRun($run, 1, 'old-step', 1, 'old-key');
        $acceptance = $container->get(SourceAcceptance::class);
        $events = $container->get(PreparedTransitionEventStoreInterface::class);
        $this->assertFalse($acceptance->alreadyAccepted($source));
        $events->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'source' => SourceAcceptance::identity($source)]);
        $pending = $events->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $this->assertFalse($acceptance->alreadyAccepted($source));
        if ($published) {
            $acceptance->publish($pending);
            $this->assertTrue($acceptance->alreadyAccepted($source));
        }
        $container->get(PendingTransitionRecovery::class)->recover($run);
        $this->assertNull($events->verifiedPendingTransition($run));
        $this->assertTrue($acceptance->alreadyAccepted($source));
        $active = $container->get(ActiveRunContextInterface::class);
        $active->loadRecovered(RunState::queued($run)->with(['lastAppliedAdvanceKey' => 'new-key']));
        $container->get('cache.app')->clear();
        $restarted = new SourceAcceptance($container->get(CommandStoreInterface::class));
        $this->assertTrue($restarted->alreadyAccepted($source));
        $this->assertFalse($restarted->alreadyAccepted(new AdvanceRun($run, 1, 'new-step', 1, 'new-key')));
        $handler = $this->createMock(\Ineersa\AgentCore\Application\Pipeline\RunMessageHandler::class);
        $handler->method('supports')->willReturn(true);
        $handler->expects($this->never())->method('handle');
        $discard = $this->createMock(\Ineersa\AgentCore\Contract\History\HistoryTailDiscardInterface::class);
        $discard->expects($this->never())->method('isContextMutatingMessage');
        $processor = new \Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor($active, $container->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class), $container->get(\Ineersa\AgentCore\Application\Pipeline\RunCommit::class), [$handler], $discard);
        $processor->process('source-recovery', $source);
        $this->assertNull($events->latestSequenceFor($run));
        $this->assertSame(0, $container->get(CommandStoreInterface::class)->countPending($run));
    }
}
