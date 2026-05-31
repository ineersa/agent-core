<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;

/**
 * Inspect, tail-log, and stop background processes.
 *
 * Implements both HatfieldToolProviderInterface for automatic registration
 * as a permanent tool and ToolHandlerInterface for execution.
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
final class BgStatusTool implements HatfieldToolProviderInterface, ToolHandlerInterface
{
    public function __construct(
        private readonly BackgroundProcessManager $manager,
        private readonly BackgroundProcessConfig $config,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
    ) {
    }

    /**
     * Execute the bg_status tool.
     *
     * @param array<string, mixed> $arguments Must contain 'action' (string)
     *                                        and optionally 'pid' (int)
     *
     * @return string Human-readable result content
     *
     * @throws ToolCallException on validation or execution failures
     */
    public function __invoke(array $arguments): string
    {
        // Validate required argument
        $action = $arguments['action'] ?? null;
        if (!\is_string($action) || '' === $action) {
            throw new ToolCallException('The "action" argument is required and must be a non-empty string.', retryable: false, hint: 'Use one of: list, log, stop.');
        }

        $action = strtolower($action);

        return match ($action) {
            'list' => $this->handleList(),
            'log' => $this->handleLog($arguments),
            'stop' => $this->handleStop($arguments),
            default => throw new ToolCallException(\sprintf('Invalid action "%s".', $action), retryable: false, hint: 'Use one of: list, log, stop.'),
        };
    }

    /**
     * Return the tool definition for automatic provider registration.
     */
    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: 'bg_status',
            description: 'Inspect, view log output, and stop background processes. Actions: list (show all background processes), log (view log tail for a specific PID), stop (terminate a background process).',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => ['list', 'log', 'stop'],
                        'description' => 'Action to perform: list (show all), log (view log tail), stop (terminate)',
                    ],
                    'pid' => [
                        'type' => 'integer',
                        'description' => 'Process PID (required for log and stop actions)',
                    ],
                ],
                'required' => ['action'],
                'additionalProperties' => false,
            ],
            handler: $this,
            executionMode: ToolExecutionMode::Sequential,
            promptLine: 'bg_status action [pid] — inspect, log, or stop background processes; use after launching background jobs',
            promptGuidelines: [
                'Use bg_status list to see all background processes with PID, status, and log path.',
                'Use bg_status log pid=N to see the tail of a background process log output.',
                'Use bg_status stop pid=N to terminate a background process gracefully.',
                'The log action returns truncated output — use the log path to read the full file.',
                'Background processes run independently and survive across tool calls.',
            ],
        );
    }

    // ─── Action handlers ────────────────────────────────────────────

    /**
     * @return string Formatted table of background processes
     */
    private function handleList(): string
    {
        $sessionId = $this->contextAccessor->current()?->runId();
        $entities = $this->manager->list($sessionId);

        if ([] === $entities) {
            return 'No background processes tracked.';
        }

        $lines = [];
        $lines[] = \sprintf('%-6s %-8s %-6s %-22s %-12s %s', 'ID', 'PID', 'PGID', 'Status', 'Started', 'Command');
        $lines[] = str_repeat('-', 80);

        foreach ($entities as $entity) {
            $status = $entity->status->value;
            if (\Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum::Finished === $entity->status
                && null !== $entity->exitCode
                && 0 !== $entity->exitCode
            ) {
                $status = \sprintf('finished (exit code %d)', $entity->exitCode);
            }

            $lines[] = \sprintf(
                '%-6d %-8d %-6s %-22s %-12s %s',
                $entity->id,
                $entity->pid,
                $entity->pgid ?? '-',
                $status,
                substr($entity->startedAt, 11, 8),
                mb_substr($entity->command, 0, 80),
            );
        }

        $lines[] = '';
        $lines[] = \sprintf('Total: %d process(es)', \count($entities));

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return string Log tail content
     *
     * @throws ToolCallException
     */
    private function handleLog(array $arguments): string
    {
        $pid = $arguments['pid'] ?? null;
        if (!\is_int($pid) || $pid <= 0) {
            throw new ToolCallException('The "pid" argument is required and must be a positive integer for the log action.', retryable: false, hint: 'Provide the PID from bg_status list output.');
        }

        try {
            $sessionId = $this->contextAccessor->current()?->runId();
            $result = $this->manager->readLogTail($pid, $this->config->logTailChars, $sessionId);
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

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return string Stop result summary
     *
     * @throws ToolCallException
     */
    private function handleStop(array $arguments): string
    {
        $pid = $arguments['pid'] ?? null;
        if (!\is_int($pid) || $pid <= 0) {
            throw new ToolCallException('The "pid" argument is required and must be a positive integer for the stop action.', retryable: false, hint: 'Provide the PID from bg_status list output.');
        }

        try {
            $sessionId = $this->contextAccessor->current()?->runId();
            $result = $this->manager->stop($pid, $sessionId);
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
