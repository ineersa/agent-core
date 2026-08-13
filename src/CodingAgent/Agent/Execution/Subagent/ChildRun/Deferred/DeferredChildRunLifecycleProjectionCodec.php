<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred;

use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Doctrine JSON boundary for deferred child lifecycle projections.
 *
 * Normalize only when writing {@see DeferredSubagentChild::$childLifecycleProjection}.
 * Denormalize + validate once when reading that JSON column into the typed DTO.
 *
 * Injected with the FrameworkBundle container serializer/validator — never construct
 * a private normalizer stack in production.
 */
final class DeferredChildRunLifecycleProjectionCodec
{
    public function __construct(
        private readonly NormalizerInterface&DenormalizerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function normalize(DeferredChildRunLifecycleProjectionDTO $projection): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->serializer->normalize(
            $projection,
            null,
            [AbstractObjectNormalizer::SKIP_NULL_VALUES => true],
        );

        return $this->applyHistoricalOmissionRules($payload);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \InvalidArgumentException when denormalization/validation fails
     */
    public function denormalize(array $data): DeferredChildRunLifecycleProjectionDTO
    {
        try {
            $projection = $this->serializer->denormalize(
                $this->rewriteHistoricalPendingToolCallAliases($data),
                DeferredChildRunLifecycleProjectionDTO::class,
            );
        } catch (SerializerExceptionInterface|\TypeError|\ValueError $exception) {
            throw new \InvalidArgumentException(\sprintf('Invalid deferred child lifecycle projection: %s', $exception->getMessage()), 0, $exception);
        }

        if (!$projection instanceof DeferredChildRunLifecycleProjectionDTO) {
            throw new \InvalidArgumentException(\sprintf('Invalid deferred child lifecycle projection: expected %s, got %s.', DeferredChildRunLifecycleProjectionDTO::class, get_debug_type($projection)));
        }

        $violations = $this->validator->validate($projection);
        if ($violations->count() > 0) {
            throw new \InvalidArgumentException(\sprintf('Invalid deferred child lifecycle projection: validation failed with %d violation(s).', $violations->count()), 0, new ValidationFailedException($projection, $violations));
        }

        return $projection;
    }

    /**
     * Read-only historical alias: nested pending rows may store display_line.
     * Canonical wire key remains displayLine; canonical wins when both exist.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function rewriteHistoricalPendingToolCallAliases(array $data): array
    {
        if (!\array_key_exists('pending_tool_calls', $data)) {
            return $data;
        }

        $raw = $data['pending_tool_calls'];
        if (!\is_array($raw)) {
            throw new \InvalidArgumentException('Invalid deferred child lifecycle projection: pending_tool_calls must be an array.');
        }

        $rewritten = [];
        foreach ($raw as $id => $entry) {
            if (!\is_string($id) || !\is_array($entry)) {
                throw new \InvalidArgumentException('Invalid deferred child lifecycle projection: pending_tool_calls entries must be string-keyed object rows.');
            }

            $row = $entry;
            $hasCanonical = \array_key_exists('displayLine', $row) && \is_string($row['displayLine']);
            if (!$hasCanonical && \array_key_exists('display_line', $row) && \is_string($row['display_line'])) {
                $row['displayLine'] = $row['display_line'];
            }
            unset($row['display_line']);
            $rewritten[$id] = $row;
        }

        // Copy top-level so caller input is not mutated unexpectedly.
        $data['pending_tool_calls'] = $rewritten;

        return $data;
    }

    /**
     * Preserve pre-Serializer omission rules for optional enrichment keys.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function applyHistoricalOmissionRules(array $payload): array
    {
        foreach (['error_message', 'assistant_result_text', 'assistant_excerpt', 'model', 'provider', 'active_tool'] as $key) {
            if (!\array_key_exists($key, $payload)) {
                continue;
            }
            $value = $payload[$key];
            if (!\is_string($value) || '' === $value) {
                unset($payload[$key]);
            }
        }

        if (\array_key_exists('context_window', $payload)) {
            $window = $payload['context_window'];
            if (!is_numeric($window) || (int) $window <= 0) {
                unset($payload['context_window']);
            }
        }

        if (\array_key_exists('cost', $payload)) {
            $cost = $payload['cost'];
            if (!is_numeric($cost) || (float) $cost <= 0.0) {
                unset($payload['cost']);
            }
        }

        return $payload;
    }
}
