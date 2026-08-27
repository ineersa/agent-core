<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use HelgeSverre\Toon\Toon;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;
use Ineersa\CodingAgent\Tool\Arguments\BgStatusArgumentsDTO;

/**
 * Inspect, tail-log, and stop background processes.
 *
 * Implements HatfieldToolProviderInterface for automatic registration
 * as a permanent tool and the Symfony AI native tool contract (typed DTO arguments).
 *
 * Actions:
 *  - list:  Show all tracked background processes with status, scoped to
 *           the current session via ambient ToolContext.
 *  - log:   Return the tail of a background process log file, scoped to
 *           the current session.
 *  - stop:  Terminate a background process (TERM → grace → KILL), scoped
 *           to the current session.
 *
 * Session ownership: resolves the current run/session ID from the ambient
 * StackToolExecutionContextAccessor (ToolContext::runId()) and passes it
 * to every BackgroundProcessManager call. This ensures the LLM only sees
 * and operates on processes it owns.
 */
final class BgStatusTool implements HatfieldToolProviderInterface
{
    public const string NAME = 'bg_status';

    public const string DESCRIPTION = 'List background processes in the current session, inspect their logs, or stop them.';

    public function __construct(
        private readonly BackgroundProcessManager $manager,
        private readonly BackgroundProcessConfig $config,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
    ) {
    }

    /**
     * Execute the bg_status tool.
     *
     * @param BgStatusArgumentsDTO $arguments
     *                                        and optionally 'pid' (int)
     *
     * @return string Human-readable result content
     *
     * @throws ToolCallException on validation or execution failures
     */
    public function __invoke(BgStatusArgumentsDTO $arguments): string
    {
        // action is Choice-constrained and pid is conditionally required on
        // the DTO; the native ValidateToolCallArgumentsListener guarantees
        // both before the handler runs.
        return match ($arguments->action) {
            'list' => $this->handleList(),
            'log' => $this->handleLog($arguments),
            'stop' => $this->handleStop($arguments),
            default => throw new \LogicException('Unreachable: action is Choice-constrained on BgStatusArgumentsDTO and rejected before invocation.'),
        };
    }

    /**
     * Return the tool definition for automatic provider registration.
     */
    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: self::NAME,
            description: self::DESCRIPTION,
            handler: $this,
            executionMode: ToolExecutionMode::Parallel,
            promptLine: 'bg_status action [pid] — list, view logs for, or stop background processes',
            promptGuidelines: [
                'The log action returns a bounded tail; use the returned log path to inspect the full file.',
                'Background processes run independently and survive across tool calls.',
            ],
        );
    }

    // ─── Action handlers ────────────────────────────────────────────

    /**
     * @return string TOON-encoded list of background processes with metadata
     */
    private function handleList(): string
    {
        $sessionId = $this->contextAccessor->current()?->runId() ?? '';
        $entities = $this->manager->listBackgrounded($sessionId);

        if ([] === $entities) {
            return Toon::encode([
                'processes' => [],
                'hint' => 'No background processes tracked. Use bg_status with action=log or action=stop and pid=<pid> when processes are running.',
            ]);
        }

        $processes = [];
        foreach ($entities as $entity) {
            $status = $entity->status->value;
            if (BackgroundProcessStatusEnum::Finished === $entity->status
                && null !== $entity->exitCode
                && 0 !== $entity->exitCode
            ) {
                $status = \sprintf('finished (exit code %d)', $entity->exitCode);
            }

            $processes[] = [
                'pid' => $entity->pid,
                'pgid' => $entity->pgid,
                'status' => $status,
                'exit_code' => $entity->exitCode,
                'started_at' => $entity->startedAt->format(\DateTimeInterface::ATOM),
                'command' => $entity->command,
                'log_path' => $entity->logPath,
            ];
        }

        return Toon::encode([
            'processes' => $processes,
            'hint' => 'Use bg_status with action=log or action=stop and pid=<pid>.',
        ]);
    }

    /**
     * @return string Log tail content
     *
     * @throws ToolCallException
     */
    private function handleLog(BgStatusArgumentsDTO $arguments): string
    {
        /** @var int $pid DTO When constraints require a positive pid for the log action. */
        $pid = $arguments->pid;

        try {
            $sessionId = $this->contextAccessor->current()?->runId() ?? '';
            $result = $this->manager->readBackgroundedLogTail($sessionId, $pid, $this->config->logTailChars);
        } catch (\RuntimeException $e) {
            throw new ToolCallException($e->getMessage(), retryable: false, hint: 'The process may have already finished or belongs to a different session. Run bg_status list to see available processes for this session.');
        }

        $lines = [];
        $lines[] = \sprintf('Background process PID %d log output:', $pid);
        $lines[] = \sprintf('Log path: %s', $result->logPath);
        $lines[] = \sprintf('Total log size: %d bytes', $result->totalBytes);

        if ($result->truncated) {
            $lines[] = \sprintf('(Showing last %d of %d bytes)', $this->config->logTailChars, $result->totalBytes);
        }

        $lines[] = '';
        $lines[] = '--- BEGIN LOG ---';
        $lines[] = $result->content;
        $lines[] = '--- END LOG ---';

        $text = implode("\n", $lines);

        // Output capping is now handled centrally by OutputCapToolResultProcessor.
        return $text;
    }

    /**
     * @return string Stop result summary
     *
     * @throws ToolCallException
     */
    private function handleStop(BgStatusArgumentsDTO $arguments): string
    {
        /** @var int $pid DTO When constraints require a positive pid for the stop action. */
        $pid = $arguments->pid;

        try {
            $sessionId = $this->contextAccessor->current()?->runId() ?? '';
            $result = $this->manager->stopBackgrounded($sessionId, $pid);
        } catch (\RuntimeException $e) {
            throw new ToolCallException($e->getMessage(), retryable: false, hint: 'The process may have already finished. Run bg_status list to see current state.');
        }

        if ($result->alreadyFinished) {
            return \sprintf('Process PID %d had already finished.', $pid);
        }

        $signalDesc = match ($result->signalSent) {
            'term' => 'SIGTERM (graceful shutdown)',
            'term+kill' => 'SIGTERM then SIGKILL (forced termination)',
            default => $result->signalSent,
        };

        return \sprintf(
            'Process PID %d stopped. Signal: %s. PGID: %s.',
            $pid,
            $signalDesc,
            $result->pgid ?? 'N/A',
        );
    }
}
