<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Logging;

use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor that injects the current {@see RunLogContext} correlation
 * fields and process memory samples into every log record's `extra` key.
 *
 * Registered with the `monolog.processor` tag in services.yaml so it runs
 * for every handler. Supports nesting: context fields set at outer scopes
 * (e.g. run_id in RunOrchestrator) are preserved when inner scopes add
 * more fields (e.g. handler in RunMessageProcessor).
 *
 * Ambient context fields are only injected when they are not already
 * present in either `extra` or `context`, allowing call sites to
 * explicitly override any ambient value for a specific log record.
 *
 * Heap attribution uses `sampler_pid` (always the process that produced the
 * sample). `pid` is injected as the current process before ambient merge unless
 * the call site already set `pid` in `extra` or `context`; ambient `pid` alone
 * cannot override that process sample.
 */
final class LogContextProcessor implements ProcessorInterface
{
    public function __construct(private readonly TelemetrySessionKeyProvider $sessionKeys)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;

        // Record both live PHP memory and allocator-reserved memory; Messenger
        // uses the latter for its worker memory limit.
        // sampler_pid is always this process. pid is also this process unless the
        // call site already set an explicit pid in extra/context.
        $pid = getmypid();
        $memoryLimit = \ini_get('memory_limit');
        foreach ([
            'pid' => false !== $pid ? $pid : null,
            'sampler_pid' => false !== $pid ? $pid : null,
            'memory_usage' => memory_get_usage(false),
            'memory_allocated' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'memory_limit' => false !== $memoryLimit && '' !== $memoryLimit ? $memoryLimit : null,
        ] as $key => $value) {
            if (!\array_key_exists($key, $extra) && !\array_key_exists($key, $record->context)) {
                $extra[$key] = $value;
            }
        }

        // Merge ambient RunLogContext fields, skipping keys already
        // set explicitly (in extra or context) so call-site log values win.
        $context = RunLogContext::current();

        foreach ($context as $key => $value) {
            if ('' === $key || null === $value) {
                continue;
            }

            // Do not overwrite fields already set at the call site.
            if (\array_key_exists($key, $extra)) {
                continue;
            }

            // Do not inject ambient fields when the log call explicitly
            // provides them (e.g. event_type='completed' in context
            // while ambient has event_type='started').
            if (\array_key_exists($key, $record->context)) {
                continue;
            }

            $extra[$key] = $value;
        }

        // Derive after merging: explicit extra wins over context, and both win
        // over ambient session identity. Explicit null suppresses correlation.
        $sessionId = \array_key_exists('session_id', $extra)
            ? $extra['session_id']
            : ($record->context['session_id'] ?? null);
        if ((\is_string($sessionId) || \is_int($sessionId)) && '' !== (string) $sessionId && 'unknown' !== $sessionId) {
            $extra['session_key'] = $this->sessionKeys->forSession((string) $sessionId);
        }

        return $record->with(extra: $extra);
    }
}
