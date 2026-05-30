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
 * When ddtrace is available, also injects dd.trace_id and dd.span_id
 * from the current trace context for automatic log-trace correlation
 * in Datadog.
 */
final class LogContextProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $context = RunLogContext::current();

        if ([] === $context) {
            return $record;
        }

        $extra = $record->extra;

        foreach ($context as $key => $value) {
            if ('' === $key || null === $value) {
                continue;
            }

            // Do not overwrite fields already set at the call site.
            if (\array_key_exists($key, $extra)) {
                continue;
            }

            $extra[$key] = $value;
        }

        // Inject Datadog trace correlation IDs for log-trace linking.
        if (!\array_key_exists('dd.trace_id', $extra) && \function_exists('DDTrace\\current_context')) {
            $ddContext = \DDTrace\current_context();
            if (null !== ($ddContext['trace_id'] ?? null)) {
                $extra['dd.trace_id'] = $ddContext['trace_id'];
            }
            if (null !== ($ddContext['span_id'] ?? null)) {
                $extra['dd.span_id'] = $ddContext['span_id'];
            }
        }

        return $record->with(extra: $extra);
    }
}
