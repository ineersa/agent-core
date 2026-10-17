<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Application\Pipeline\ApplyCommandHandler;
use Ineersa\AgentCore\Application\Pipeline\ReceiveToolCompletionHandler;
use Ineersa\AgentCore\Application\Tool\ToolCompletionDeliveryEligibilityPolicy;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\ReceiveToolCompletion;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryFenceDTO;
use Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder;
use Ineersa\AgentCore\Tests\Support\ToolCompletionReceiptFixture;
use Ineersa\CodingAgent\Session\CanonicalToolCompletionDeliveryFenceProvider;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class CanonicalToolCompletionDeliveryFenceProviderTest extends IsolatedKernelTestCase
{
    public function testCancellationSurvivesExplicitFollowupAndDisposableIndexRebuild(): void
    {
        $c = self::getContainer();
        [$run, $source, $reference] = ToolCompletionReceiptFixture::create($c);
        $provider = $c->get(CanonicalToolCompletionDeliveryFenceProvider::class);
        $captured = $provider->capture($run);
        $this->assertEquals(new ToolCompletionDeliveryFenceDTO(0, 0, 0), $captured);
        $state = new RunState($run, RunStatus::Running, turnNo: 1, lastSeq: $source->seq);
        $cancel = $c->get(ApplyCommandHandler::class)->handle(new ApplyCommand($run, 1, 'cancel', 1, 'cancel-once', 'cancel'), $state);
        foreach ($cancel->events as $event) {
            PreparedEventStoreSeeder::append($c->get(PreparedTransitionEventStoreInterface::class), $event);
        }
        $this->assertSame(RunStatus::Cancelled, $cancel->nextState?->status);
        $followup = $c->get(ApplyCommandHandler::class)->handle(new ApplyCommand($run, 1, 'followup', 1, 'explicit-followup', 'follow_up', ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Continue explicitly.']]]]), $cancel->nextState);
        foreach ($followup->events as $event) {
            $this->assertNotSame('agent_command_rejected', $event->type);
            PreparedEventStoreSeeder::append($c->get(PreparedTransitionEventStoreInterface::class), $event);
        }
        $resumed = $state->with(['status' => RunStatus::Running, 'turnNo' => 2]);
        $this->assertSame('stale_cancellation_generation', $c->get(ToolCompletionDeliveryEligibilityPolicy::class)->ineligibleReason($resumed, $captured));
        $delivery = $c->get(ReceiveToolCompletionHandler::class)->handle(new ReceiveToolCompletion($reference), $resumed);
        $this->assertSame('stale_cancellation_generation', $delivery->events[0]->payload['state_reason']);
        $history = $c->get(SessionRunEventStore::class)->historySource($run);
        $index = \dirname($history->path).'/history-index.sqlite';
        $this->assertFileExists($index);
        (new Filesystem())->remove([$index, $index.'-wal', $index.'-shm']);
        $this->assertFileDoesNotExist($index);
        $this->assertGreaterThan(0, $provider->capture($run)->wakeGeneration);
        $this->assertFileExists($index);
        $this->assertSame('stale_cancellation_generation', $c->get(ToolCompletionDeliveryEligibilityPolicy::class)->ineligibleReason($resumed, $captured));
    }

    public function testOnlySelectedOrDiscardedHistoryChangesInvalidatePermission(): void
    {
        $c = self::getContainer();
        [$run] = ToolCompletionReceiptFixture::create($c);
        $provider = $c->get(CanonicalToolCompletionDeliveryFenceProvider::class);
        $initial = $provider->capture($run);
        $this->append($run, 'turn_advanced', ['turn_no' => 1]);
        $this->append($run, 'history_position_set', ['position_turn_no' => 1, 'reason' => 'turn_advanced']);
        $this->append($run, 'context_compacted', []);
        $this->append($run, 'turn_advanced', ['turn_no' => 2], 2);
        $this->assertEquals($initial, $provider->capture($run));
        $this->append($run, 'history_position_set', ['position_turn_no' => 1, 'reason' => 'history_select']);
        $selected = $provider->capture($run);
        $this->assertGreaterThan($initial->historyGeneration, $selected->historyGeneration);
        $this->append($run, 'history_tail_discarded', ['after_turn_no' => 1]);
        $this->assertGreaterThan($selected->historyGeneration, $provider->capture($run)->historyGeneration);
        $this->assertSame('stale_history_generation', $c->get(ToolCompletionDeliveryEligibilityPolicy::class)->ineligibleReason(new RunState($run, RunStatus::Running), $initial));
        $this->assertSame($initial->wakeGeneration, $provider->capture($run)->wakeGeneration);
    }

    public function testCancellationIsNotForgottenWhenItsTurnLeavesSelectedHistory(): void
    {
        $c = self::getContainer();
        [$run] = ToolCompletionReceiptFixture::create($c);
        $this->append($run, 'turn_advanced', ['turn_no' => 1]);
        $this->append($run, 'turn_advanced', ['turn_no' => 2], 2);
        $this->append($run, 'agent_command_applied', ['kind' => 'cancel'], 2);
        $provider = $c->get(CanonicalToolCompletionDeliveryFenceProvider::class);
        $cancelGeneration = $provider->capture($run)->wakeGeneration;
        $this->assertGreaterThan(0, $cancelGeneration);
        $this->append($run, 'history_position_set', ['position_turn_no' => 1, 'reason' => 'history_select']);
        $this->append($run, 'history_tail_discarded', ['after_turn_no' => 1]);
        $this->assertSame($cancelGeneration, $provider->capture($run)->wakeGeneration);
        $history = $c->get(SessionRunEventStore::class)->historySource($run);
        $index = \dirname($history->path).'/history-index.sqlite';
        (new Filesystem())->remove([$index, $index.'-wal', $index.'-shm']);
        $this->assertSame($cancelGeneration, $provider->capture($run)->wakeGeneration);
    }

    /** @param array<string, mixed> $payload */
    private function append(string $run, string $type, array $payload, int $turn = 1): void
    {
        PreparedEventStoreSeeder::append(self::getContainer()->get(PreparedTransitionEventStoreInterface::class), new RunEvent($run, 0, $turn, $type, $payload));
    }
}
