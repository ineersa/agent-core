<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use HelgeSverre\Toon\Toon;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Scheduling\DTO\SessionScheduleDTO;
use Ineersa\CodingAgent\Scheduling\SessionScheduleRepository;
use Ineersa\CodingAgent\Tool\Arguments\ScheduleArgumentsDTO;
use Symfony\AI\Agent\Toolbox\Attribute\MapToolArguments;
use Symfony\Component\Clock\Clock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Exception\InvalidArgumentException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class ScheduleTool implements HatfieldToolProviderInterface
{
    public function __construct(
        private SessionScheduleRepository $schedules,
        private StackToolExecutionContextAccessor $context,
        private ValidatorInterface $validator,
        #[Autowire('%env(HATFIELD_SESSION_ID)%')]
        private string $controllerRunId,
    ) {
    }

    public function __invoke(#[MapToolArguments] ScheduleArgumentsDTO $arguments): string
    {
        $context = $this->context->requireCurrent();
        $runId = $context->runId();
        if ('cancel' === $arguments->action) {
            $this->schedules->cancel($runId, $arguments->id ?? '');

            return 'Schedule cancelled or already absent. Running invocations are not stopped.';
        }
        if ('create' === $arguments->action) {
            $errors = $this->validator->validate($arguments->arguments['backgrounded'] ?? false, new Assert\IdenticalTo(false, message: 'Scheduled work is already asynchronous; backgrounded=true is not supported.'));
            if (\count($errors) > 0) {
                throw new ValidationFailedException($arguments, $errors);
            }
            $controllerId = $this->schedules->controllerId($this->controllerRunId);
            if (null === $controllerId) {
                throw new ToolCallException('Scheduling requires a live Hatfield controller.', retryable: false);
            }
            $tool = $arguments->tool ?? '';
            $now = Clock::get()->now();
            $definition = new SessionScheduleDTO(
                id: Uuid::v7()->toRfc4122(),
                runId: $runId,
                controllerRunId: $this->controllerRunId,
                controllerId: $controllerId,
                tool: $tool,
                arguments: $arguments->arguments,
                every: $arguments->every ?? '',
                createdAt: $now,
                expiresAt: null === $arguments->for_seconds ? null : $now->modify('+'.$arguments->for_seconds.' seconds'),
                turnNo: $context->turnNo(),
            );
            try {
                $this->schedules->recurring($definition)->getTrigger()->getNextRunDate($now);
            } catch (InvalidArgumentException $exception) {
                throw new ToolCallException($exception->getMessage(), retryable: false, previous: $exception);
            }
            $this->schedules->save($definition);
        }
        $rows = [];
        foreach ($this->schedules->all($runId) as $definition) {
            $rows[] = [
                'id' => $definition->id,
                'tool' => $definition->tool,
                'arguments' => $definition->arguments,
                'every' => $definition->every,
                'next' => $this->schedules->recurring($definition)->getTrigger()->getNextRunDate(Clock::get()->now())?->format(\DateTimeInterface::ATOM),
                'expires_at' => $definition->expiresAt?->format(\DateTimeInterface::ATOM),
            ];
        }

        return Toon::encode(['schedules' => $rows]);
    }

    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: 'schedule',
            description: 'Create, list, or cancel recurring Bash or code-mode work in the main agent\'s live session. Available only to the main agent. Results arrive as automatic notifications. Nothing runs after Hatfield closes.',
            handler: $this,
            promptLine: 'schedule action [tool, arguments, every, for_seconds, id] — create, list, or cancel live-session recurring work',
            promptGuidelines: [
                'Create with tool=bash or code_mode, its existing arguments, and every (for example "5 minutes" or "PT1H"). for_seconds limits the recurring lifetime. Cancel with the returned id.',
                'Scheduled work is already asynchronous. Do not pass backgrounded=true, sleep, or poll for completion. Cancellation prevents future invocations, not work already running. /schedule lists and cancels future work.',
                'Scheduled execution cannot ask for approval or human input. Approval-required actions are denied; use a foreground invocation when a human decision is needed.',
            ],
        );
    }
}
