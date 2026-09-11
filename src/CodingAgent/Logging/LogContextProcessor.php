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
 */
final class LogContextProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;

        // Record both live PHP memory and allocator-reserved memory; Messenger
        // uses the latter for its worker memory limit.
        $pid = getmypid();
        foreach ([
            'pid' => false !== $pid ? $pid : null,
            'memory_usage' => memory_get_usage(false),
            'memory_allocated' => memory_get_usage(true),
        ] as $key => $value) {
            if (!\array_key_exists($key, $extra) && !\array_key_exists($key, $record->context)) {
                $extra[$key] = $value;
            }
        }

        // Merge ambient RunLogContext fields, skipping keys already
        // set explicitly (in extra or context) so call-site log values win.
        $context = RunLogContext::current();

        if ([] === $context) {
            return $record->with(extra: $extra);
        }

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

        return $record->with(extra: $extra);
    }
}
