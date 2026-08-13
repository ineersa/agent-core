<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract\SubagentProgress;

use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Symfony Serializer + Validator boundary for subagent_progress payloads.
 *
 * Normalize only when writing RunEvent/transcript/JSONL arrays.
 * Denormalize + validate once when reading wire/meta arrays into typed objects.
 */
final class SubagentProgressSnapshotCodec
{
    public function __construct(
        private readonly NormalizerInterface&DenormalizerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * Standalone stack matching FrameworkBundle Serializer attributes + ArrayDenormalizer
     * for non-container TUI/test construction paths.
     */
    public static function createStandalone(): self
    {
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $propertyTypeExtractor = new PropertyInfoExtractor(
            typeExtractors: [new PhpDocExtractor(), new ReflectionExtractor()],
        );
        $serializer = new Serializer([
            new ArrayDenormalizer(),
            new ObjectNormalizer(
                classMetadataFactory: $classMetadataFactory,
                nameConverter: new MetadataAwareNameConverter($classMetadataFactory),
                propertyTypeExtractor: $propertyTypeExtractor,
            ),
        ]);
        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        return new self($serializer, $validator);
    }

    /**
     * @return array<string, mixed>
     */
    public function normalize(SubagentProgressSnapshotInterface $snapshot): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->serializer->normalize(
            $snapshot,
            null,
            [AbstractObjectNormalizer::SKIP_NULL_VALUES => true],
        );

        return $payload;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \InvalidArgumentException                                  when mode is missing/unsupported
     * @throws \Symfony\Component\Serializer\Exception\ExceptionInterface on denormalization failure
     * @throws ValidationFailedException                                  when fixed fields fail validation
     */
    public function denormalize(array $data): SubagentProgressSnapshotInterface
    {
        $mode = $data['mode'] ?? null;
        $type = match ($mode) {
            'single' => SubagentProgressSingleSnapshotDTO::class,
            'parallel' => SubagentProgressParallelSnapshotDTO::class,
            default => throw new \InvalidArgumentException(\sprintf('subagent_progress.mode must be "single" or "parallel", got %s.', \is_string($mode) ? '"'.$mode.'"' : get_debug_type($mode))),
        };

        $snapshot = $this->serializer->denormalize($data, $type);
        if (!$snapshot instanceof SubagentProgressSingleSnapshotDTO && !$snapshot instanceof SubagentProgressParallelSnapshotDTO) {
            throw new \InvalidArgumentException(\sprintf('subagent_progress denormalize expected single/parallel DTO, got %s.', get_debug_type($snapshot)));
        }

        $violations = $this->validator->validate($snapshot);
        if ($violations->count() > 0) {
            throw new ValidationFailedException($snapshot, $violations);
        }

        return $snapshot;
    }
}
