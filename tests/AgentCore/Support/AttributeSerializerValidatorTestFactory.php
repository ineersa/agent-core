<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Test-only attribute Serializer + Validator stack mirroring FrameworkBundle.
 *
 * Prefer the container service in KernelTestCase paths; use this only when the
 * test cannot boot the container.
 */
final class AttributeSerializerValidatorTestFactory
{
    public static function denormalizer(bool $withBackedEnumNormalizer = false): DenormalizerInterface
    {
        return self::create($withBackedEnumNormalizer)[0];
    }

    public static function serializer(bool $withBackedEnumNormalizer = false): SerializerInterface&NormalizerInterface&DenormalizerInterface
    {
        return self::create($withBackedEnumNormalizer)[0];
    }

    /**
     * @return array{0: SerializerInterface&NormalizerInterface&DenormalizerInterface, 1: ValidatorInterface}
     */
    public static function create(bool $withBackedEnumNormalizer = false): array
    {
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $propertyTypeExtractor = new PropertyInfoExtractor(
            typeExtractors: [new PhpDocExtractor(), new ReflectionExtractor()],
        );

        $normalizers = [
            new ArrayDenormalizer(),
            new DateTimeNormalizer(),
        ];
        if ($withBackedEnumNormalizer) {
            $normalizers[] = new BackedEnumNormalizer();
        }
        $normalizers[] = new ObjectNormalizer(
            classMetadataFactory: $classMetadataFactory,
            nameConverter: new MetadataAwareNameConverter($classMetadataFactory, new CamelCaseToSnakeCaseNameConverter()),
            propertyTypeExtractor: $propertyTypeExtractor,
        );

        $serializer = new Serializer($normalizers, [new JsonEncoder()]);
        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        return [$serializer, $validator];
    }
}
