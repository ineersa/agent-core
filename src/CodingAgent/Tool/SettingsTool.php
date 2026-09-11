<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use HelgeSverre\Toon\Toon;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SettingsResolutionDTO;
use Ineersa\CodingAgent\Config\SettingsValueResolver;
use Ineersa\CodingAgent\Tool\Arguments\SettingsArgumentsDTO;

/**
 * Singular parent-agent settings tool: one read/set/remove per call.
 *
 * Mutations write sparse user/project overrides only; defaults are never
 * writable. Disk changes require a Hatfield restart to take effect.
 * Do not use generic file tools to read or edit settings YAML.
 *
 * Input shape and operation-dependent rules live on
 * {@see SettingsArgumentsDTO}. This handler only executes the resolved
 * operation and maps writer/resolver failures.
 */
final class SettingsTool implements HatfieldToolProviderInterface
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
     * @return string TOON-encoded operation result
     */
    public function __invoke(SettingsArgumentsDTO $arguments): string
    {
        return $this->toolRuntime->run(function () use ($arguments): string {
            $path = trim($arguments->path);

            $result = match ($arguments->operation) {
                'read' => $this->read($path, $arguments),
                'set' => $this->set($path, $arguments),
                'remove' => $this->remove($path, $arguments),
                default => throw new \LogicException('Unreachable: operation is Choice-constrained on SettingsArgumentsDTO and rejected before invocation.'),
            };

            return Toon::encode($result);
        });
    }

    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: 'settings',
            description: 'Read, set, or remove one Hatfield setting by dotted path.',
            handler: $this,
            // Explicit flat provider schema with a typed DTO handler: RegistryBackedToolbox
            // keeps typed resolution (no raw_arguments) while preserving the historical
            // model-visible shape that native JsonSchema generation cannot emit exactly.
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
                        'type' => ['string', 'number', 'boolean', 'object', 'array', 'null'],
                    ],
                ],
                'required' => ['operation', 'path'],
                'additionalProperties' => false,
            ],
            executionMode: ToolExecutionMode::Sequential,
            promptLine: 'settings operation path [scope] [value] — read, set, or remove one Hatfield setting',
            promptGuidelines: [
                'MUST use the `settings` tool for every Hatfield runtime-setting read, set, or removal. NEVER inspect or modify `~/.hatfield/settings.yaml` or `.hatfield/settings.yaml` using `read`, `edit`, `write`, or `bash` commands such as `cat`, `grep`, or `sed`.',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $path, SettingsArgumentsDTO $arguments): array
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
     * @return array<string, mixed>
     */
    private function set(string $path, SettingsArgumentsDTO $arguments): array
    {
        // Validation guarantees scope is user|project and value is present
        // (including explicit null) before this handler runs.
        $layer = SettingsLayerEnum::from((string) $arguments->scope);

        try {
            $this->writer->set($layer, $this->activeConfig->cwd, $path, $arguments->value);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            throw new ToolCallException($e->getMessage(), retryable: false, previous: $e);
        }

        return $this->effectiveSnapshot('set', $path, $this->loadResolution(), restartRequired: true, scope: $layer->value, changed: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function remove(string $path, SettingsArgumentsDTO $arguments): array
    {
        $layer = SettingsLayerEnum::from((string) $arguments->scope);

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

    private function readScope(SettingsArgumentsDTO $arguments): string
    {
        if (null === $arguments->scope || '' === $arguments->scope) {
            return 'effective';
        }

        return $arguments->scope;
    }
}
