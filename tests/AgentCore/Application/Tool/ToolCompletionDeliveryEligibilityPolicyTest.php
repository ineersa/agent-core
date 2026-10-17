<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Tool;

use Ineersa\AgentCore\Application\Tool\ToolCompletionDeliveryEligibilityPolicy;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryFenceDTO;
use Ineersa\AgentCore\Tests\Support\StaticToolCompletionDeliveryFenceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ToolCompletionDeliveryEligibilityPolicyTest extends TestCase
{
    #[DataProvider('fences')]
    public function testPermissionIsNotRenewedByCurrentRunStatus(?ToolCompletionDeliveryFenceDTO $captured, ?string $reason): void
    {
        $policy = new ToolCompletionDeliveryEligibilityPolicy(new StaticToolCompletionDeliveryFenceProvider(new ToolCompletionDeliveryFenceDTO(10, 20, 30)));
        $state = new RunState('root', RunStatus::Running);
        $this->assertSame($reason, $policy->ineligibleReason($state, $captured));
        $this->assertSame($reason, $policy->ineligibleReason($state->with(['status' => RunStatus::Completed]), $captured));
    }

    public static function fences(): iterable
    {
        yield 'current' => [new ToolCompletionDeliveryFenceDTO(10, 20, 30), null];
        yield 'missing permission' => [null, 'missing_delivery_fence'];
        yield 'controller restarted' => [new ToolCompletionDeliveryFenceDTO(10, 20, 29), 'stale_controller_generation'];
        yield 'cancel then reopen' => [new ToolCompletionDeliveryFenceDTO(9, 20, 30), 'stale_cancellation_generation'];
        yield 'selected or discarded branch' => [new ToolCompletionDeliveryFenceDTO(10, 19, 30), 'stale_history_generation'];
    }

    public function testCancellationAndTerminalChildrenNeverBecomeEligible(): void
    {
        $fence = new ToolCompletionDeliveryFenceDTO(0, 0, 0);
        $policy = new ToolCompletionDeliveryEligibilityPolicy(new StaticToolCompletionDeliveryFenceProvider($fence));
        foreach ([RunStatus::Cancelling, RunStatus::Cancelled, RunStatus::Failed] as $status) {
            $this->assertSame('cancelled_or_failed', $policy->ineligibleReason(new RunState('root', $status), $fence));
        }
        $this->assertSame('terminal_child', $policy->ineligibleReason(new RunState('child', RunStatus::Completed, parentRunId: 'root'), $fence));
    }
}
