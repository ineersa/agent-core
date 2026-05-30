<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Logging;

use Ineersa\AgentCore\Contract\SpanProviderInterface;

/**
 * Datadog trace span provider backed by the ddtrace PHP extension.
 *
 * Uses \DDTrace\start_span and \DDTrace\close_span to create real
 * distributed tracing spans. Degrades gracefully when the extension
 * is not loaded or disabled.
 *
 * Tracks the mapping from opaque string IDs to internal SpanData objects
 * so callers can pair start/close without depending on ddtrace internals.
 *
 * @see SpanProviderInterface
 */
final class DdtraceSpanProvider implements SpanProviderInterface
{
    /** @var array<string, \DDTrace\SpanData> */
    private array $spans = [];

    public function startSpan(string $operationName, array $tags = []): ?string
    {
        if (!\function_exists('DDTrace\\start_span')) {
            return null;
        }

        $spanData = \DDTrace\start_span();

        if (false === $spanData) {
            return null;
        }

        $spanData->name = $operationName;

        foreach ($tags as $key => $value) {
            $spanData->meta[$key] = $this->normalizeTag($value);
        }

        $spanId = spl_object_id($spanData);
        $this->spans[(string) $spanId] = $spanData;

        return (string) $spanId;
    }

    public function closeSpan(?string $spanId, array $tags = []): void
    {
        if (null === $spanId || !isset($this->spans[$spanId])) {
            return;
        }

        $spanData = $this->spans[$spanId];
        unset($this->spans[$spanId]);

        foreach ($tags as $key => $value) {
            $spanData->meta[$key] = $this->normalizeTag($value);
        }

        \DDTrace\close_span();
    }

    public function currentContext(): array
    {
        if (!\function_exists('DDTrace\\current_context')) {
            return [];
        }

        $ctx = \DDTrace\current_context();

        $result = [];

        if (null !== ($ctx['trace_id'] ?? null)) {
            $result['trace_id'] = (string) $ctx['trace_id'];
        }

        if (null !== ($ctx['span_id'] ?? null)) {
            $result['span_id'] = (string) $ctx['span_id'];
        }

        return $result;
    }

    /**
     * Normalize a tag value to a string for ddtrace meta.
     */
    private function normalizeTag(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        if (null === $value) {
            return '';
        }

        return (string) $value;
    }
}
