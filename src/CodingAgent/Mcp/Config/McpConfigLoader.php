<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Config;

use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Serializer\Exception\MissingConstructorArgumentsException;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Loads typed MCP configuration from global and project .hatfield/mcp.json files.
 *
 * Owns JSON/root shape, source-aware merge/disable semantics, env/header
 * interpolation, cwd resolution, and secret-safe exception translation.
 * Per-server field hydration/type validation uses Symfony Serializer + Validator
 * on {@see McpServerDefinitionDTO}.
 *
 * Merge semantics:
 *  - Project server definitions replace whole global definitions by server name.
 *  - A project server with only `{ "enabled": false }` disables an inherited server.
 *  - Non-inherited disable-only entries fail validation.
 *
 * Empty or missing files produce an empty McpConfigDTO (no error).
 */
final class McpConfigLoader
{
    public function __construct(
        private readonly SettingsPathResolver $pathResolver,
        private readonly string $projectCwd,
        private readonly DenormalizerInterface $denormalizer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * Load the merged MCP configuration.
     *
     * @throws \RuntimeException for validation or interpolation failures
     */
    public function load(): McpConfigDTO
    {
        $globalRaw = $this->loadJsonFile('~/.hatfield/mcp.json', $this->pathResolver->getHomeDir());
        $projectRaw = $this->loadJsonFile($this->projectCwd.'/.hatfield/mcp.json', $this->projectCwd);

        $globalServers = $this->extractServers($globalRaw, '~/.hatfield/mcp.json');
        if ([] !== $globalServers) {
            $globalServers = $this->validate($globalServers, null);
        }

        $projectServers = $this->extractServers($projectRaw, $this->projectCwd.'/.hatfield/mcp.json');
        if ([] !== $projectServers) {
            $projectServers = $this->validate($projectServers, $globalServers);
        }

        // Merge: project overrides global by whole-server replacement
        $mergedRaw = array_merge($globalServers, $projectServers);

        // Remove any server with enabled:false from the final config
        foreach ($mergedRaw as $name => $data) {
            if (\is_array($data) && ($data['enabled'] ?? true) === false) {
                unset($mergedRaw[$name]);
            }
        }

        $servers = [];
        foreach ($mergedRaw as $name => $data) {
            if (!\is_array($data) || !\is_string($name)) {
                continue;
            }

            // Drop transport-inapplicable fields before interpolation (historical ignore semantics).
            $data = $this->stripTransportInapplicableFields($data);

            // Interpolate env and headers BEFORE building DTO
            if (isset($data['env']) && \is_array($data['env'])) {
                /** @var array<string, string> $env */
                $env = $data['env'];
                $data['env'] = $this->interpolateMap($env, $name, 'env');
            }

            if (isset($data['headers']) && \is_array($data['headers'])) {
                /** @var array<string, string> $headers */
                $headers = $data['headers'];
                $data['headers'] = $this->interpolateMap($headers, $name, 'headers');
            }

            // Resolve relative cwd to project CWD
            if (isset($data['cwd']) && \is_string($data['cwd']) && '' !== $data['cwd']) {
                $data['cwd'] = $this->resolveCwd($data['cwd']);
            }

            $servers[$name] = $this->hydrateServerDefinition($name, $data);
        }

        return new McpConfigDTO(servers: $servers);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJsonFile(string $pathPattern, string $baseDir): array
    {
        $resolved = $this->pathResolver->resolve($pathPattern, $baseDir);

        $content = @file_get_contents($resolved);

        if (false === $content) {
            return [];
        }

        $decoded = json_decode($content, true);

        if (\JSON_ERROR_NONE !== json_last_error()) {
            throw new \RuntimeException(\sprintf('MCP config file "%s" is not valid JSON: %s.', $resolved, json_last_error_msg()));
        }

        if (!\is_array($decoded)) {
            throw new \RuntimeException(\sprintf('MCP config file "%s" must contain a JSON object.', $resolved));
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function extractServers(array $raw, string $source): array
    {
        if (!\array_key_exists('mcpServers', $raw)) {
            return [];
        }

        $servers = $raw['mcpServers'];

        if (!\is_array($servers)) {
            throw new \RuntimeException(\sprintf('MCP config file "%s": "mcpServers" must be a JSON object, got %s.', $source, \gettype($servers)));
        }

        return $servers;
    }

    /**
     * @param array<string, mixed>      $rawServers
     * @param array<string, mixed>|null $globalServers
     *
     * @return array<string, mixed>
     */
    private function validate(array $rawServers, ?array $globalServers = null): array
    {
        foreach ($rawServers as $name => $data) {
            if (!\is_string($name) || '' === $name) {
                throw new \RuntimeException('MCP config: server name must be a non-empty string.');
            }

            if (!\is_array($data)) {
                throw new \RuntimeException(\sprintf('MCP server "%s": server definition must be an object.', $name));
            }

            $this->validateServer($name, $data, $globalServers);
        }

        return $rawServers;
    }

    /**
     * Diagnostic priority matches pre-Serializer semantics:
     *  1) unknown fields
     *  2) wrong-type enabled
     *  3) source-aware transport/inheritance (including early return for inherited disable-only)
     *  4) Serializer+Validator for normal per-server field rules
     *
     * Inherited disable-only deliberately bypasses DTO hydration so extra allowed
     * fields are ignored even when malformed (historical behavior).
     *
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $globalServers
     */
    private function validateServer(string $name, array $data, ?array $globalServers): void
    {
        $allowedFields = [
            'enabled', 'command', 'args', 'env', 'cwd',
            'url', 'headers', 'timeoutMs', 'startupTimeoutMs', 'availability', 'excludeTools',
        ];

        foreach (array_keys($data) as $key) {
            if (!\in_array($key, $allowedFields, true)) {
                throw new \RuntimeException(\sprintf('MCP server "%s": unknown field "%s". Allowed fields: %s.', $name, $key, implode(', ', $allowedFields)));
            }
        }

        if (\array_key_exists('enabled', $data) && !\is_bool($data['enabled'])) {
            throw new \RuntimeException(\sprintf('MCP server "%s": "enabled" must be a boolean, got %s.', $name, \gettype($data['enabled'])));
        }

        $enabled = $data['enabled'] ?? true;
        // isset: null is treated as absent (matches prior transport presence checks).
        $hasCommand = isset($data['command']);
        $hasUrl = isset($data['url']);

        // No transport defined — only loader can evaluate inheritance context.
        if (!$hasCommand && !$hasUrl) {
            if (null !== $globalServers && \array_key_exists($name, $globalServers)) {
                // Inherited disable-only: return after unknown/enabled checks; ignore other fields.
                if (\array_key_exists('enabled', $data) && false === $data['enabled']) {
                    return;
                }

                throw new \RuntimeException(\sprintf('MCP server "%s": missing transport (command or url). An inherited server override must define a transport or explicitly set "enabled": false.', $name));
            }

            if (false === $enabled) {
                throw new \RuntimeException(\sprintf('MCP server "%s": cannot define a server with only "enabled": false and no transport. This server is not inherited from global config.', $name));
            }

            throw new \RuntimeException(\sprintf('MCP server "%s": missing transport. Define "command" for a STDIO server or "url" for an HTTP server.', $name));
        }

        // Field types, list/map shapes, command xor url, timeouts, availability.
        $this->hydrateServerDefinition($name, $data);
    }

    /**
     * Denormalize + validate one server definition.
     *
     * Strips transport-inapplicable fields and the non-user transportType key so
     * historical ignore-semantics and derived transport are preserved.
     *
     * @param array<string, mixed> $data
     */
    private function hydrateServerDefinition(string $name, array $data): McpServerDefinitionDTO
    {
        $payload = $this->prepareServerPayload($name, $data);

        try {
            /** @var McpServerDefinitionDTO $dto */
            $dto = $this->denormalizer->denormalize(
                $payload,
                McpServerDefinitionDTO::class,
                context: [
                    AbstractObjectNormalizer::ALLOW_EXTRA_ATTRIBUTES => false,
                ],
            );
        } catch (ExtraAttributesException $e) {
            $extra = $e->getExtraAttributes();
            $first = (string) reset($extra);

            throw new \RuntimeException(\sprintf('MCP server "%s": unknown field "%s". Allowed fields: enabled, command, args, env, cwd, url, headers, timeoutMs, startupTimeoutMs, availability, excludeTools.', $name, $first));
        } catch (MissingConstructorArgumentsException $e) {
            $missing = $e->getMissingConstructorArguments();
            $first = ltrim((string) reset($missing), '$');

            throw new \RuntimeException(\sprintf('MCP server "%s": "%s" is required.', $name, $first));
        } catch (NotNormalizableValueException $e) {
            $path = $e->getPath() ?? 'a field';
            $expected = $e->getExpectedTypes() ?? [];
            $typeHint = [] === $expected ? 'a valid value' : implode('|', $expected);

            throw new \RuntimeException(\sprintf('MCP server "%s": "%s" must be of type %s.', $name, $path, $typeHint));
        } catch (\TypeError $e) {
            $field = 'a field';
            if (preg_match('/Argument #\d+ \(\$(\w+)\)/', $e->getMessage(), $matches)) {
                $field = $matches[1];
            }

            throw new \RuntimeException(\sprintf('MCP server "%s": "%s" has an invalid type.', $name, $field));
        } catch (\ValueError $e) {
            // Backed enum construction failures (e.g. invalid availability).
            if (str_contains($e->getMessage(), 'McpServerAvailabilityEnum')) {
                throw new \RuntimeException(\sprintf('MCP server "%s": "availability" must be one of: all, specific.', $name));
            }

            throw new \RuntimeException(\sprintf('MCP server "%s": %s', $name, $e->getMessage()));
        }

        $violations = $this->validator->validate($dto);
        if (0 === $violations->count()) {
            return $dto;
        }

        /** @var ConstraintViolationInterface $violation */
        $violation = $violations->get(0);
        $propertyPath = $violation->getPropertyPath();
        $message = (string) $violation->getMessage();

        if ('' === $propertyPath) {
            throw new \RuntimeException(\sprintf('MCP server "%s": %s', $name, $message));
        }

        throw new \RuntimeException(\sprintf('MCP server "%s": "%s": %s', $name, $propertyPath, $message));
    }

    /**
     * Inject server name, drop non-user transportType, ignore transport-inapplicable fields.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function prepareServerPayload(string $name, array $data): array
    {
        $payload = $this->stripTransportInapplicableFields($data);
        $payload['name'] = $name;
        unset($payload['transportType']);

        return $payload;
    }

    /**
     * Preserve historical behavior: transport-inapplicable fields are ignored, not rejected.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function stripTransportInapplicableFields(array $data): array
    {
        $hasCommand = isset($data['command']);
        $hasUrl = isset($data['url']);

        if (!$hasCommand) {
            unset($data['args'], $data['env'], $data['cwd']);
        }
        if (!$hasUrl) {
            unset($data['headers']);
        }

        return $data;
    }

    /**
     * @param array<string, string> $map
     *
     * @return array<string, string>
     */
    private function interpolateMap(array $map, string $server, string $field): array
    {
        $result = [];

        foreach ($map as $key => $value) {
            $result[$key] = $this->interpolateValue($value, $server, \sprintf('%s.%s', $field, $key));
        }

        return $result;
    }

    private function interpolateValue(string $value, string $server, string $context): string
    {
        if (!str_contains($value, '${')) {
            return $value;
        }

        return preg_replace_callback(
            '/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            static function (array $matches) use ($server, $context): string {
                $varName = $matches[1];
                $resolved = getenv($varName);

                if (false === $resolved) {
                    throw new \RuntimeException(\sprintf('MCP server "%s": environment variable "%s" referenced in "%s" is not set.', $server, $varName, $context));
                }

                if ('' === $resolved) {
                    throw new \RuntimeException(\sprintf('MCP server "%s": environment variable "%s" referenced in "%s" is empty. Set the variable or remove the interpolation reference.', $server, $varName, $context));
                }

                return $resolved;
            },
            $value,
        );
    }

    private function resolveCwd(string $cwd): string
    {
        if (str_starts_with($cwd, '/')) {
            return $cwd;
        }

        if (str_starts_with($cwd, '~')) {
            return $this->pathResolver->resolve($cwd, $this->projectCwd);
        }

        return $this->pathResolver->resolve($cwd, $this->projectCwd);
    }
}
