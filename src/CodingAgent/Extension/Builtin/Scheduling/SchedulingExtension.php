<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\Scheduling;

use Ineersa\CodingAgent\Extension\Builtin\Scheduling\DTO\SessionScheduleDTO;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterSessionStartHookContextDTO;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterSessionStartHookInterface;
use Ineersa\Hatfield\ExtensionApi\Scheduler\RecurringJobDTO;
use Ineersa\Hatfield\ExtensionApi\Scheduler\RecurringJobSourceInterface;
use Ineersa\Hatfield\ExtensionApi\Session\OwnerNotificationDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ContextualExtensionToolHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/** Optional, live-controller recurring work. No host scheduling policy lives here. */
final class SchedulingExtension implements HatfieldExtensionInterface, TuiExtensionInterface, ContextualExtensionToolHandlerInterface, AfterSessionStartHookInterface, RecurringJobSourceInterface, ExtensionAgentJobHandlerInterface
{
    private const string HANDLER = 'scheduling.execute';
    private SessionScheduleRepository $schedules;
    private SchedulePicker $picker;

    public function __invoke(array $arguments, ToolInvocationContextDTO $context): mixed
    {
        $action = $arguments['action'] ?? null;
        if (!\in_array($action, ['create', 'list', 'cancel'], true)) {
            throw new \InvalidArgumentException('Choose create, list or cancel.');
        }
        $controllerId = $this->schedules->controllerId($context->runId);
        if (null === $controllerId) {
            throw new \RuntimeException('Scheduling requires a live main controller.');
        }
        if ('cancel' === $action) {
            if (!\is_string($arguments['id'] ?? null) || '' === trim($arguments['id'])) {
                throw new \InvalidArgumentException('Cancel requires a schedule id.');
            }
            $this->schedules->cancel($context->runId, $arguments['id']);
        } elseif ('create' === $action) {
            $tool = $arguments['tool'] ?? null;
            $input = $arguments['arguments'] ?? [];
            $every = $arguments['every'] ?? null;
            $lifetime = $arguments['for_seconds'] ?? null;
            if (!\in_array($tool, ['bash', 'code_mode'], true) || !\is_array($input) || ([] !== $input && array_is_list($input)) || !\is_string($every) || '' === trim($every) || (null !== $lifetime && (!\is_int($lifetime) || $lifetime <= 0)) || true === ($input['backgrounded'] ?? false)) {
                throw new \InvalidArgumentException('Create requires Bash/code_mode, an arguments object, an interval, optional positive for_seconds, and no backgrounded=true.');
            }
            $now = new \DateTimeImmutable();
            $definition = new SessionScheduleDTO(bin2hex(random_bytes(16)), $context->runId, $context->runId, $controllerId, $tool, $input, $every, $now, null === $lifetime ? null : $now->modify('+'.$lifetime.' seconds'), 0);
            $this->schedules->recurring($definition)->getTrigger()->getNextRunDate($now);
            $this->schedules->save($definition);
        }

        return ['schedules' => array_values(array_map(fn (SessionScheduleDTO $row): array => [
            'id' => $row->id, 'tool' => $row->tool, 'arguments' => $row->arguments, 'every' => $row->every,
            'next' => $this->schedules->recurring($row)->getTrigger()->getNextRunDate(new \DateTimeImmutable())?->format(\DateTimeInterface::ATOM),
            'expires_at' => $row->expiresAt?->format(\DateTimeInterface::ATOM),
        ], $this->schedules->all($context->runId)))];
    }

    public function register(ExtensionApiInterface $api): void
    {
        $path = $api->getCwd().'/.hatfield/cache';
        $this->schedules = new SessionScheduleRepository(new FilesystemAdapter('session-schedules', 0, $path), new LockFactory(new FlockStore()));
        $this->picker = new SchedulePicker($this->schedules);
        $api->registerTool(new ToolRegistrationDTO(
            'schedule', 'Create, list, or cancel recurring Bash or code-mode work in this live main session. Nothing runs after Hatfield closes.',
            ['type' => 'object', 'properties' => [
                'action' => ['type' => 'string', 'enum' => ['create', 'list', 'cancel']],
                'tool' => ['type' => 'string', 'enum' => ['bash', 'code_mode']],
                'arguments' => ['type' => 'object', 'additionalProperties' => true],
                'every' => ['type' => 'string', 'description' => 'Symfony interval, e.g. 5 minutes or PT1H.'],
                'for_seconds' => ['type' => 'integer', 'minimum' => 1],
                'id' => ['type' => 'string'],
            ], 'required' => ['action'], 'additionalProperties' => false], $this,
            promptGuidelines: [
                'Create with tool=bash or code_mode, arguments and every. Optional for_seconds limits its lifetime. Cancel with the returned id; /schedule lists and cancels future work.',
                'Scheduled work is already asynchronous. Do not pass backgrounded=true, sleep, or poll. Cancellation prevents future invocations, not work already running.',
                'Scheduled work cannot ask for approval or human input. Approval-required actions are denied. Results arrive automatically; running code-mode invocations have no /bg control.',
            ], mainOnly: true,
        ));
        $api->registerCommand(new CommandDefinitionDTO('schedule', [], 'List and cancel recurring work', '/schedule', false), $this->picker);
        $api->registerSessionStartHook($this);
        $api->registerExtensionAgentJobHandler(self::HANDLER, $this);
        $api->registerRecurringJobSource('scheduling', $this);
    }

    public function registerTui(TuiExtensionContextInterface $context): void
    {
        $this->picker->wire($context);
    }

    public function onAfterSessionStart(AfterSessionStartHookContextDTO $context): void
    {
        $this->schedules->activate($context->runId);
        $this->schedules->clear($context->runId);
    }

    public function jobs(string $runId): iterable
    {
        foreach ($this->schedules->all($runId) as $definition) {
            yield new RecurringJobDTO($definition->id, self::HANDLER, ['run_id' => $runId, 'id' => $definition->id, 'controller_id' => $definition->controllerId], $definition->every, $definition->createdAt, $definition->expiresAt);
        }
    }

    public function handle(ExtensionApiInterface $api, array $payload, ?string $jobId, ?string $correlationId): void
    {
        $runId = $payload['run_id'] ?? '';
        $definition = $this->schedules->all($runId)[$payload['id'] ?? ''] ?? null;
        if (null === $definition || $definition->controllerId !== ($payload['controller_id'] ?? null) || (null !== $definition->expiresAt && $definition->expiresAt <= new \DateTimeImmutable())) {
            return;
        }
        $result = $api->tools()->invoke(new ToolInvocationRequestDTO($runId, $jobId ?? bin2hex(random_bytes(16)), $definition->tool, $definition->arguments, true));
        $api->notifyOwner(new OwnerNotificationDTO($runId, 'schedule:'.$result->invocationId, \sprintf("Scheduled %s result [%s]:\n%s", $definition->tool, $definition->id, $result->text), $result->cancelled));
    }
}
