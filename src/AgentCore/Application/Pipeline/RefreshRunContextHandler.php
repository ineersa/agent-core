<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\RefreshRunContext;
use Ineersa\AgentCore\Domain\Run\RunState;

final readonly class RefreshRunContextHandler implements RunMessageHandler
{
    public function supports(object $message): bool
    {
        return $message instanceof RefreshRunContext;
    }

    public function handle(object $message, RunState $state): HandlerResult
    {
        \assert($message instanceof RefreshRunContext);
        $messages = \Ineersa\AgentCore\Domain\Message\GeneratedContext::replace($state->messages, $message->messages);

        return new HandlerResult(
            nextState: $state->with(['messages' => $messages]),
            events: [RunEvent::forAppend($state->runId, $state->turnNo, RunEventTypeEnum::ContextRefreshed->value, [
                'messages' => array_map(static fn (AgentMessage $item): array => $item->toArray(), $message->messages),
            ])],
        );
    }
}
