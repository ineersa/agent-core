<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support\Mcp;

use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Mcp\Config\McpConfigLoader;
use Ineersa\CodingAgent\Mcp\Config\McpServerAvailabilityEnum;
use Ineersa\CodingAgent\Mcp\Config\McpServerDefinitionDTO;
use Ineersa\CodingAgent\Mcp\Config\McpTransportTypeEnum;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Builds real McpConfigLoader instances for tests (Serializer + Validator, no kernel).
 */
final class TestMcpConfigLoaderFactory
{
    /**
     * @param array<string, McpServerDefinitionDTO> $servers
     */
    public static function loaderForServers(array $servers): McpConfigLoader
    {
        $root = TestDirectoryIsolation::createProjectTempDir('mcp-config');
        TestDirectoryIsolation::ensureDirectory($root.'/.hatfield');
        $payload = ['mcpServers' => []];
        foreach ($servers as $name => $server) {
            $payload['mcpServers'][$name] = [
                'url' => $server->url,
                'availability' => $server->availability->value,
            ];
        }
        file_put_contents($root.'/.hatfield/mcp.json', json_encode($payload, \JSON_THROW_ON_ERROR));

        return self::create(new SettingsPathResolver(getenv('HOME') ?: '/tmp'), $root);
    }

    public static function smokeLoader(): McpConfigLoader
    {
        return self::loaderForServers([
            'context7' => new McpServerDefinitionDTO('context7', url: 'https://example.test/mcp', transportType: McpTransportTypeEnum::HTTP, availability: McpServerAvailabilityEnum::All),
            'websearch' => new McpServerDefinitionDTO('websearch', url: 'https://example.test/sse', transportType: McpTransportTypeEnum::HTTP, availability: McpServerAvailabilityEnum::Specific),
        ]);
    }

    public static function create(SettingsPathResolver $pathResolver, string $projectCwd): McpConfigLoader
    {
        return new McpConfigLoader(
            $pathResolver,
            $projectCwd,
            self::serializer(),
            self::validator(),
        );
    }

    public static function serializer(): Serializer
    {
        $reflectionExtractor = new ReflectionExtractor();
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $objectNormalizer = new ObjectNormalizer(
            classMetadataFactory: $classMetadataFactory,
            nameConverter: null,
            propertyAccessor: PropertyAccess::createPropertyAccessor(),
            propertyTypeExtractor: $reflectionExtractor,
        );

        return new Serializer(
            normalizers: [new BackedEnumNormalizer(), $objectNormalizer],
            encoders: [],
        );
    }

    public static function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }
}
