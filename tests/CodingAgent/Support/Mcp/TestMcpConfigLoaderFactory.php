<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support\Mcp;

use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Mcp\Config\McpConfigLoader;
use Ineersa\CodingAgent\Mcp\Config\McpConfigValidator;
use Ineersa\CodingAgent\Mcp\Config\McpEnvInterpolator;
use Ineersa\CodingAgent\Mcp\Config\McpServerAvailabilityEnum;
use Ineersa\CodingAgent\Mcp\Config\McpServerDefinitionDTO;
use Ineersa\CodingAgent\Mcp\Config\McpTransportTypeEnum;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;

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

        return new McpConfigLoader(
            new SettingsPathResolver(getenv('HOME') ?: '/tmp'),
            new McpConfigValidator(),
            new McpEnvInterpolator(),
            $root,
        );
    }

    public static function smokeLoader(): McpConfigLoader
    {
        return self::loaderForServers([
            'context7' => new McpServerDefinitionDTO('context7', url: 'https://example.test/mcp', transportType: McpTransportTypeEnum::HTTP, availability: McpServerAvailabilityEnum::All),
            'websearch' => new McpServerDefinitionDTO('websearch', url: 'https://example.test/sse', transportType: McpTransportTypeEnum::HTTP, availability: McpServerAvailabilityEnum::Specific),
        ]);
    }
}
