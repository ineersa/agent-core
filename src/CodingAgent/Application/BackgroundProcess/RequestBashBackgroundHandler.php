<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\RequestBashBackgroundDTO;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsMessageHandler(bus: 'agent.command.bus')]
final readonly class RequestBashBackgroundHandler
{
    public function __construct(
        private ProcessStore $store,
        private ProcessLifecycle $lifecycle,
        private RunLockManager $locks,
        private ValidatorInterface $validator,
        private RuntimeEventSinkInterface $sink,
    ) {
    }

    public function __invoke(RequestBashBackgroundDTO $request): void
    {
        $accepted = 0 === \count($this->validator->validate($request))
            && $this->locks->synchronized($request->runId, fn (): bool => $this->store->requestBackground($request, $this->lifecycle));
        if (!$accepted) {
            // Reuse the controller's runtime event pipe rather than competing
            // with the tool worker for its single-requester metadata reply queue.
            $this->locks->afterRelease($request->runId, fn () => $this->sink->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: $request->runId,
                seq: 0,
                payload: ['error' => 'The selected invocation is no longer eligible for backgrounding.'],
            )));
        }
    }
}
