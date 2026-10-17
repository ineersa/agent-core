<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Tool;

use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionEnvelope;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailure;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailureCodeEnum;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Configured Symfony Serializer boundary for retained tool_completion envelopes.
 *
 * Domain DTOs keep constructor invariants; this codec owns array/JSON mapping.
 */
final readonly class RetainedToolCompletionEnvelopeCodec
{
    /** @var array<string, mixed> */
    private const array CONTEXT = [
        AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
    ];

    public function __construct(
        private SerializerInterface&NormalizerInterface&DenormalizerInterface $serializer,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function normalize(RetainedToolCompletionEnvelope $envelope): array
    {
        try {
            /** @var array<string, mixed> $normalized */
            $normalized = $this->serializer->normalize($envelope, null, self::CONTEXT);
        } catch (SerializerExceptionInterface|\TypeError|\InvalidArgumentException $exception) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Retained completion envelope could not be normalized.', $exception);
        }

        if (!\is_array($normalized)) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Retained completion envelope normalization must produce an array.');
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function denormalize(array $payload): RetainedToolCompletionEnvelope
    {
        try {
            $envelope = $this->serializer->denormalize(
                $payload,
                RetainedToolCompletionEnvelope::class,
                null,
                self::CONTEXT,
            );
        } catch (SerializerExceptionInterface|\TypeError|\ValueError|\InvalidArgumentException $exception) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Retained completion envelope is corrupt.', $exception);
        }

        if (!$envelope instanceof RetainedToolCompletionEnvelope) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Retained completion envelope denormalization returned an unexpected type.');
        }

        return $envelope;
    }

    /**
     * @return array<string, mixed>
     */
    public function semanticFingerprintPayload(RetainedToolCompletionEnvelope $envelope): array
    {
        $payload = $this->normalize($envelope->forSemanticFingerprint());
        unset($payload['integrity']);

        return $payload;
    }

    public function payloadFingerprint(RetainedToolCompletionEnvelope $envelope): string
    {
        return hash(
            'sha256',
            json_encode($this->semanticFingerprintPayload($envelope), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
        );
    }
}
