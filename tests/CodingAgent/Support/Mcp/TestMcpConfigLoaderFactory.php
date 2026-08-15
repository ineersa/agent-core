<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support\Mcp;

use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Mcp\Config\McpConfigLoader;
use Ineersa\CodingAgent\Mcp\Config\McpServerAvailabilityEnum;
use Ineersa\CodingAgent\Mcp\Config\McpServerDefinitionDTO;
use Ineersa\CodingAgent\Mcp\Config\McpTransportTypeEnum;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Builds real McpConfigLoader instances for tests (Serializer + Validator, no kernel).
 *
 * Uses AttributeSerializerValidatorTestFactory so MetadataAwareNameConverter +
 * camel_case_to_snake_case match production (public MCP camelCase keys stay via SerializedName).
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
        /** @var array{0: SerializerInterface&NormalizerInterface&DenormalizerInterface, 1: ValidatorInterface} $stack */
        $stack = AttributeSerializerValidatorTestFactory::create(withBackedEnumNormalizer: true);

        return new McpConfigLoader(
            $pathResolver,
            $projectCwd,
            $stack[0],
            $stack[1],
        );
    }
}
