<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;

/** Scalar accepted identities share the durable command authority, not a cache. */
final readonly class SourceAcceptance
{
    public function __construct(private CommandStoreInterface $commands)
    {
    }

    /** @return array<string, int|string> */
    public static function actionIdentity(string $type, string $runId, string $commandId, string $phase = 'complete'): array
    {
        if ('' === $commandId) {
            throw new \InvalidArgumentException('Source action requires a producer-captured identity.');
        }

        return ['type' => $type, 'run_id' => $runId, 'turn_no' => 0, 'step_id' => $phase, 'attempt' => 1, 'idempotency_key' => $commandId];
    }

    /** @param array<string, int|string> $identity */
    public function identityAlreadyAccepted(array $identity): bool
    {
        return [] !== $identity && $this->commands->has((string) $identity['run_id'], self::key($identity));
    }

    /** @return array<string, int|string> */
    public static function identity(AbstractAgentBusMessage $message): array
    {
        if (!$message instanceof \Ineersa\AgentCore\Domain\Message\StartRun && !$message instanceof ApplyCommand && !$message instanceof \Ineersa\AgentCore\Domain\Message\ApplyShellCommand && !$message instanceof \Ineersa\AgentCore\Domain\Message\AdvanceRun && !$message instanceof \Ineersa\AgentCore\Domain\Message\CompactRun) {
            return [];
        }

        return ['type' => $message::class, 'run_id' => $message->runId(), 'turn_no' => $message->turnNo(), 'step_id' => $message->stepId(), 'attempt' => $message->attempt(), 'idempotency_key' => $message->idempotencyKey()];
    }

    public function alreadyAccepted(AbstractAgentBusMessage $message): bool
    {
        // A queued command is already accepted; replay must not enqueue it or
        // discard history again. Its later FIFO application is not a delivery.
        if ($message instanceof ApplyCommand && $this->commands->has($message->runId(), $message->idempotencyKey())) {
            return true;
        }

        $identity = self::identity($message);

        return [] !== $identity && $this->commands->has($message->runId(), self::key($identity));
    }

    public function publish(\Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO $transition): void
    {
        $work = $transition->work;
        $source = $work['source'] ?? [];
        if (!\is_array($source)) {
            throw new \RuntimeException('Invalid pending source identity.');
        }
        // Internal history/disposition transitions have no source delivery.
        if (!isset($source['type']) || 'tool_result_disposition' === $source['type']) {
            return;
        }
        $key = self::key($source);
        $runId = $source['run_id'];
        if (($work['run_id'] ?? null) !== $runId) {
            throw new \RuntimeException('Pending source differs from transition owner.');
        }
        if (ApplyCommand::class === $source['type'] && $this->commands->has($runId, $source['idempotency_key'])) {
            return;
        }
        $this->commands->markApplied($runId, $key);
    }

    public function validate(\Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO $transition): void
    {
        $source = $transition->work['source'] ?? [];
        if (!\is_array($source)) {
            throw new \RuntimeException('Invalid pending source identity.');
        }
        if (isset($source['type']) && 'tool_result_disposition' !== $source['type']) {
            self::key($source);
            if (($transition->work['run_id'] ?? null) !== $source['run_id']) {
                throw new \RuntimeException('Pending source differs from transition owner.');
            }
        }
    }

    /** @param array<string, mixed> $source */
    private static function key(array $source): string
    {
        foreach (['type', 'run_id', 'step_id', 'idempotency_key'] as $field) {
            if (!isset($source[$field]) || !\is_string($source[$field])) {
                throw new \RuntimeException('Incomplete pending source identity.');
            }
        }
        foreach (['turn_no', 'attempt'] as $field) {
            if (!isset($source[$field]) || !\is_int($source[$field])) {
                throw new \RuntimeException('Incomplete pending source generation.');
            }
        }

        return 'accepted-source:'.hash('sha256', serialize([$source['type'], $source['run_id'], $source['turn_no'], $source['step_id'], $source['attempt'], $source['idempotency_key']]));
    }
}
