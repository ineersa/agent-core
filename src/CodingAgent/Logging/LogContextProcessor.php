<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Logging;

use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor that injects the current {@see RunLogContext} correlation
 * fields into every log record's `extra` key.
 *
 * Registered via monolog.yaml using the `monolog.processor` tag so it runs
 * for every handler. Supports nesting: context fields set at outer scopes
 * (e.g. run_id in RunOrchestrator) are preserved when inner scopes add
 * more fields (e.g. handler in RunMessageProcessor).
 *
 * Ambient context fields are only injected when they are not already
 * present in either `extra` or `context`, allowing call sites to
 * explicitly override any ambient value for a specific log record.
 *
 * When ddtrace is available, also injects dd.trace_id and dd.span_id
 * from the current trace context for automatic log-trace correlation
 * in Datadog. This runs before the ambient context merge so that a
 * ddtrace context entry cannot be blocked by a stale ambient key.
 */
final class LogContextProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;

        // Inject Datadog trace correlation IDs FIRST so ambient context
        // (which may contain unrelated keys) never blocks real trace IDs.
        if (!\array_key_exists('dd.trace_id', $extra) && !\array_key_exists('dd.trace_id', $record->context) && \function_exists('DDTrace\\current_context')) {
            $ddContext = \DDTrace\current_context();
            if (null !== ($ddContext['trace_id'] ?? null)) {
                $extra['dd.trace_id'] = $ddContext['trace_id'];
            }
            if (null !== ($ddContext['span_id'] ?? null)) {
                $extra['dd.span_id'] = $ddContext['span_id'];
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
