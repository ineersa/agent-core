<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/** Select the recipient at owner consumption, not at a producer's stale status snapshot. */
#[AsMessageHandler(bus: 'agent.command.bus')]
final readonly class BackgroundCompletionHandler
{
    public function __construct(
        private RunLockManager $locks,
        private OwnerRunInitializationMiddleware $initialization,
        private ActiveRunContextInterface $contexts,
        private AgentChildRunDirectory $children,
        private RunMessageProcessor $processor,
        private NormalizerInterface $normalizer,
        private MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(DeliverBackgroundCompletionDTO $message): void
    {
        $runId = $message->runId();
        $this->locks->synchronized($runId, function () use ($message, $runId): void {
            $this->initialization->initializeForOwner($runId, $message);
            $state = $this->contexts->requireLoaded($runId);
            if (null !== $state->parentRunId && ($state->status->isTerminal() || RunStatus::Cancelling === $state->status)) {
                $owner = $this->children->locate($runId)
                    ?? throw new \RuntimeException('Background completion child owner identity is missing.');
                $rootRunId = $owner->parentRunId;
                $visited = [$runId => true];
                while (null !== ($ancestor = $this->children->locate($rootRunId))) {
                    if (isset($visited[$rootRunId])) {
                        throw new \RuntimeException('Cyclic background completion ownership.');
                    }
                    $visited[$rootRunId] = true;
                    $rootRunId = $ancestor->parentRunId;
                }
                $forward = new DeliverBackgroundCompletionDTO(
                    $rootRunId,
                    $message->identity,
                    \sprintf("Background completion from child owner %s (agent_id: %s, run_id: %s). The child was not resumed.\n\n%s", $owner->agentName, $owner->artifactId, $runId, $message->text),
                );
                // No owner mutation or retained terminal-child context. The root
                // receives the same existing append-message flow after release.
                if ($state->status->isTerminal()) {
                    $this->contexts->release($runId);
                }
                $this->locks->afterRelease($runId, function () use ($forward): void {
                    $this->commandBus->dispatch($forward);
                });

                return;
            }

            $payload = $this->normalizer->normalize(new AgentMessage('user', [['type' => 'text', 'text' => $message->text]]), context: [AbstractObjectNormalizer::SKIP_NULL_VALUES => true]);
            // Apply under this same owner lock, so a finishing child cannot become
            // terminal between destination selection and mailbox admission.
            $this->processor->process('background_completion', new ApplyCommand(
                $runId, $state->turnNo, $message->identity, 1, $message->identity,
                CoreCommandKind::AppendMessage, ['message' => $payload],
            ));
        });
    }
}
