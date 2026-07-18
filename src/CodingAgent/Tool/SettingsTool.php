<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SettingsResolutionDTO;
use Ineersa\CodingAgent\Config\SettingsValueResolver;

/**
 * Singular parent-agent settings tool: one read/set/remove per call.
 *
 * Mutations write sparse user/project overrides only; defaults are never
 * writable. Disk changes require a Hatfield restart to take effect.
 * Do not use generic file tools to read or edit settings YAML.
 */
final class SettingsTool implements HatfieldToolProviderInterface, ToolHandlerInterface
{
    public function __construct(
        private readonly ToolRuntime $toolRuntime,
        private readonly AppConfigLoader $loader,
        private readonly AppResourceLocator $resources,
        private readonly AppConfig $activeConfig,
        private readonly SettingsValueResolver $valueResolver,
        private readonly SettingsOverrideWriter $writer,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public function __invoke(array $arguments): array
    {
        return $this->toolRuntime->run(function () use ($arguments): array {
            $operation = $this->requireOperation($arguments);
            $path = $this->requirePath($arguments);

            return match ($operation) {
                'read' => $this->read($path, $arguments),
                'set' => $this->set($path, $arguments),
                'remove' => $this->remove($path, $arguments),
                default => throw new ToolCallException('The "operation" argument must be one of: read, set, remove.', retryable: false),
            };
        });
    }

    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: 'settings',
            description: 'Read, set, or remove one Hatfield setting by dotted path.',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'operation' => [
                        'type' => 'string',
                        'enum' => ['read', 'set', 'remove'],
                        'description' => 'Exactly one operation per call.',
                    ],
                    'path' => [
                        'type' => 'string',
                        'description' => 'Dotted settings path (e.g. tui.theme).',
                    ],
                    'scope' => [
                        'type' => 'string',
                        'enum' => ['effective', 'user', 'project'],
                        'description' => 'read: defaults to effective. set/remove: required user or project only.',
                    ],
                    'value' => [
                        'description' => 'Native JSON value for set (explicit null allowed). Required for set.',
                        // Open-ended JSON value; keep explicit null valid without inventing a typed union.
                        'type' => ['string', 'number', 'boolean', 'object', 'array', 'null'],
                    ],
                ],
                'required' => ['operation', 'path'],
                'additionalProperties' => false,
            ],
            handler: $this,
            executionMode: ToolExecutionMode::Sequential,
            promptLine: 'settings operation path [scope] [value] — read, set, or remove one Hatfield setting',
            promptGuidelines: [
                'MUST use the `settings` tool for every Hatfield runtime-setting read, set, or removal. NEVER inspect or modify `~/.hatfield/settings.yaml` or `.hatfield/settings.yaml` using `read`, `edit`, `write`, or `bash` commands such as `cat`, `grep`, or `sed`.',
            ],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function read(string $path, array $arguments): array
    {
        $scope = $this->readScope($arguments);
        $resolution = $this->loadResolution();

        if ('effective' === $scope) {
            return $this->effectiveSnapshot('read', $path, $resolution, restartRequired: false);
        }

        // Explicit layer: only the raw sparse map, not inherited effective values.
        $layerRaw = 'user' === $scope ? $resolution->userRaw : $resolution->projectRaw;
        $layerOnly = new SettingsResolutionDTO(
            defaultsRaw: [],
            userRaw: 'user' === $scope ? $layerRaw : [],
            projectRaw: 'project' === $scope ? $layerRaw : [],
            effective: $layerRaw,
        );
        $resolved = $this->valueResolver->resolve($layerOnly, $path);

        return [
            'operation' => 'read',
            'path' => $path,
            'scope' => $scope,
            'exists' => $resolved->exists,
            'value' => $resolved->exists ? $resolved->value : null,
            'source' => $resolved->exists ? $scope : null,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function set(string $path, array $arguments): array
    {
        $layer = $this->mutationLayer($arguments);
        if (!\array_key_exists('value', $arguments)) {
            throw new ToolCallException('The "value" argument is required for set (use explicit null to set null).', retryable: false, hint: 'Pass value as native JSON.');
        }

        try {
            $this->writer->set($layer, $this->activeConfig->cwd, $path, $arguments['value']);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            throw new ToolCallException($e->getMessage(), retryable: false, previous: $e);
        }

        return $this->effectiveSnapshot('set', $path, $this->loadResolution(), restartRequired: true, scope: $layer->value, changed: true);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function remove(string $path, array $arguments): array
    {
        $layer = $this->mutationLayer($arguments);

        try {
            $changed = $this->writer->remove($layer, $this->activeConfig->cwd, $path);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            throw new ToolCallException($e->getMessage(), retryable: false, previous: $e);
        }

        return $this->effectiveSnapshot('remove', $path, $this->loadResolution(), restartRequired: $changed, scope: $layer->value, changed: $changed);
    }

    /**
     * @return array<string, mixed>
     */
    private function effectiveSnapshot(
        string $operation,
        string $path,
        SettingsResolutionDTO $resolution,
        bool $restartRequired,
        ?string $scope = null,
        ?bool $changed = null,
    ): array {
        $resolved = $this->valueResolver->resolve($resolution, $path);
        $result = [
            'operation' => $operation,
            'path' => $path,
            'scope' => $scope ?? 'effective',
            'exists' => $resolved->exists,
            'value' => $resolved->exists ? $resolved->value : null,
            'source' => $resolved->exists ? ($resolved->composite ? 'mixed' : (null !== $resolved->layer ? $resolved->layer->value : null)) : null,
        ];
        if (null !== $changed) {
            $result['changed'] = $changed;
        }
        if ($restartRequired) {
            $result['restart_required'] = true;
        }

        return $result;
    }

    private function loadResolution(): SettingsResolutionDTO
    {
        return $this->loader->load($this->resources->getDefaultsPath(), $this->activeConfig->cwd);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function requireOperation(array $arguments): string
    {
        $operation = $arguments['operation'] ?? null;
        if (!\is_string($operation) || !\in_array($operation, ['read', 'set', 'remove'], true)) {
            throw new ToolCallException('The "operation" argument must be one of: read, set, remove.', retryable: false);
        }

        return $operation;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function requirePath(array $arguments): string
    {
        $path = $arguments['path'] ?? null;
        if (!\is_string($path) || '' === trim($path) || null === SettingsValueResolver::propertyPath($path)) {
            throw new ToolCallException('The "path" argument must be a non-empty dotted settings path.', retryable: false, hint: 'Example: tui.theme');
        }

        return trim($path);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function readScope(array $arguments): string
    {
        if (!\array_key_exists('scope', $arguments) || null === $arguments['scope'] || '' === $arguments['scope']) {
            return 'effective';
        }

        $scope = $arguments['scope'];
        if (!\is_string($scope) || !\in_array($scope, ['effective', 'user', 'project'], true)) {
            throw new ToolCallException('The "scope" argument for read must be one of: effective, user, project.', retryable: false);
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function mutationLayer(array $arguments): SettingsLayerEnum
    {
        if (!\array_key_exists('scope', $arguments) || null === $arguments['scope'] || '' === $arguments['scope']) {
            throw new ToolCallException('set/remove require explicit scope "user" or "project".', retryable: false, hint: 'Do not omit scope and do not use effective.');
        }

        $scope = $arguments['scope'];
        if (!\is_string($scope) || !\in_array($scope, ['user', 'project'], true)) {
            throw new ToolCallException(\sprintf('Invalid mutation scope "%s"; must be user or project.', \is_scalar($scope) ? (string) $scope : get_debug_type($scope)), retryable: false);
        }

        return SettingsLayerEnum::from($scope);
    }
}
