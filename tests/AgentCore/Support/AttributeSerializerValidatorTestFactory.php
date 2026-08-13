<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
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
    /**
     * @return array{0: NormalizerInterface&DenormalizerInterface, 1: ValidatorInterface}
     */
    public static function create(bool $withBackedEnumNormalizer = false): array
    {
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $propertyTypeExtractor = new PropertyInfoExtractor(
            typeExtractors: [new PhpDocExtractor(), new ReflectionExtractor()],
        );

        $normalizers = [new ArrayDenormalizer()];
        if ($withBackedEnumNormalizer) {
            $normalizers[] = new BackedEnumNormalizer();
        }
        $normalizers[] = new ObjectNormalizer(
            classMetadataFactory: $classMetadataFactory,
            nameConverter: new MetadataAwareNameConverter($classMetadataFactory),
            propertyTypeExtractor: $propertyTypeExtractor,
        );

        $serializer = new Serializer($normalizers);
        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        return [$serializer, $validator];
    }
}
