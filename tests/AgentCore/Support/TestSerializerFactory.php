<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Serializer;

final class TestSerializerFactory
{
    public static function normalizer(): NormalizerInterface
    {
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());

        return new Serializer([
            new DateTimeNormalizer(),
            new ObjectNormalizer(
                classMetadataFactory: $classMetadataFactory,
                nameConverter: new MetadataAwareNameConverter($classMetadataFactory),
                propertyTypeExtractor: new ReflectionExtractor(),
            ),
        ]);
    }
}
