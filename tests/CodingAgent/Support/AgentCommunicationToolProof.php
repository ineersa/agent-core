<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Ineersa\AgentCore\Application\Handler\ExecuteToolCallWorker;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Tests\Support\TestToolBatchRegistration;
use Ineersa\CodingAgent\Application\AgentCommunication\DTO\AcceptSubmissionDTO;
use Ineersa\CodingAgent\Application\AgentCommunication\DTO\CompleteSubmissionDTO;
use Ineersa\CodingAgent\Application\AgentCommunication\DTO\DeliverSubmissionDTO;
use Ineersa\CodingAgent\Application\AgentCommunication\SubmissionReceiptRepository;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\History\HistoryPromptTextExtractor;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class AgentCommunicationToolProof
{
    public static function exercise(ContainerInterface $c, SubmissionFixture $f, bool $notice): void
    {
        $transport = $c->get('messenger.transport.run_control');
        $transport->reset();
        $state = $notice ? $f->childState : $f->parentState;
        $target = $notice ? $f->parentState : $f->childState;
        $call = new ExecuteToolCall($state->runId, 1, 'step', 1, 'source-execution', $notice ? 'notice' : 'steer', $notice ? 'notify_parent' : 'agent_steer', $notice ? ['text' => 'Important guidance'] : ['text' => 'Important guidance', 'artifact_id' => $f->artifact], 0, toolsRef: 'current');
        TestToolBatchRegistration::register($c->get(ToolBatchCollector::class), $c->get(ToolBatchStoreInterface::class), $state->runId, 1, 'step', [$call]);
        // Configured worker -> executor -> native Symfony toolbox -> mapped DTO
        // validation -> real handler -> native deferred registration and subscriber.
        Assert::assertNull(($c->get(ExecuteToolCallWorker::class))($call));
        $sent = $transport->getSent();
        Assert::assertCount(1, $sent);
        $accept = $sent[0]->getMessage();
        Assert::assertInstanceOf(AcceptSubmissionDTO::class, $accept);
        $active = $c->get(ActiveRunContextInterface::class);
        $active->loadRecovered($state);
        $bus = $c->get('agent.command.bus');
        $bus->dispatch($accept, [new ReceivedStamp('run_control')]);
        Assert::assertNotNull($c->get(SubmissionReceiptRepository::class)->receipt($state->runId, $accept->identity));
        $sent = $transport->getSent();
        $messages = array_map(static fn ($envelope) => $envelope->getMessage(), $sent);
        $completion = array_find($messages, static fn (object $message): bool => $message instanceof CompleteSubmissionDTO);
        $delivery = array_find($messages, static fn (object $message): bool => $message instanceof DeliverSubmissionDTO);
        Assert::assertInstanceOf(CompleteSubmissionDTO::class, $completion);
        Assert::assertInstanceOf(DeliverSubmissionDTO::class, $delivery);
        Assert::assertSame('source_accepted', $completion->completion->details['status']);
        Assert::assertStringContainsString('does not confirm receiver delivery or attention', $completion->completion->content[0]['text']);
        $bus->dispatch($completion, [new ReceivedStamp('run_control')]);
        Assert::assertSame('completed', $c->get(DeferredToolCompletionRepositoryInterface::class)->status($accept->identity));
        $active->loadRecovered($target);
        $bus->dispatch($delivery, [new ReceivedStamp('run_control')]);
        $admitted = $active->requireLoaded($target->runId);
        Assert::assertSame($target->pendingToolCalls, $admitted->pendingToolCalls);
        Assert::assertSame($target->messages, $admitted->messages);
        $bus->dispatch(new AdvanceRun($target->runId, 1, 'blocked', 1, 'blocked'), [new ReceivedStamp('run_control')]);
        Assert::assertSame($admitted->messages, $active->requireLoaded($target->runId)->messages);
        // Represent completion of the recipient's unrelated tools. The next real
        // owner boundary, not recipient admission, appends the authored message.
        $active->loadRecovered($admitted->with(['pendingToolCalls' => [], 'activeStepId' => null]));
        $bus->dispatch(new AdvanceRun($target->runId, 1, 'safe', 1, 'safe'), [new ReceivedStamp('run_control')]);
        $applied = $active->requireLoaded($target->runId);
        $message = $applied->messages[array_key_last($applied->messages)];
        Assert::assertSame('agent_communication', $message->role);
        Assert::assertStringContainsString('Important guidance', $message->content[0]['text']);
        Assert::assertSame($accept->identity, $message->metadata['agent_communication']['identity']);
        Assert::assertStringContainsString($notice ? 'child (informational notice)' : 'parent task owner (guidance)', $message->content[0]['text']);
        $notifications = [];
        $mapper = $c->get(RuntimeEventMapper::class);
        foreach ($c->get(EventStoreInterface::class)->allFor($target->runId) as $event) {
            if ($accept->identity !== ($event->payload['idempotency_key'] ?? null)) {
                continue;
            }
            Assert::assertSame('', HistoryPromptTextExtractor::extract($event->type, $event->payload));
            $runtime = $mapper->toRuntimeEvent($event);
            if (null === $runtime) {
                continue;
            }
            Assert::assertNotContains($runtime->type, [RuntimeEventTypeEnum::UserMessageSubmitted->value, RuntimeEventTypeEnum::UserMessageQueued->value]);
            if (RuntimeEventTypeEnum::ModelNotification->value === $runtime->type) {
                $notifications[] = $runtime;
            }
        }
        Assert::assertCount(1, $notifications);
        Assert::assertSame($message->content[0]['text'], $notifications[0]->payload['text']);
        Assert::assertSame($accept->identity, $notifications[0]->payload['id']);
        Assert::assertSame($notice ? 'notify_parent' : 'agent_steer', $notifications[0]->payload['kind']);
    }
}
