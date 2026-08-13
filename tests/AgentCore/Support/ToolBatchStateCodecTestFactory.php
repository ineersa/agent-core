<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Domain\Tool\ToolBatchStateCodec;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validation;

/**
 * Test-only codec builder mirroring FrameworkBundle attribute serializer + validator.
 *
 * Prefer the container service in KernelTestCase paths; use this only when the
 * test cannot boot the container.
 */
final class ToolBatchStateCodecTestFactory
{
    public static function create(): ToolBatchStateCodec
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

        return new ToolBatchStateCodec($serializer, $validator);
    }
}
