<?php

declare(strict_types=1);

namespace Ineersa\Tools\PHPStan\DeadCode;

use Ineersa\AgentCore\Domain\Notification\ModelNotificationDTO;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildProjectionDTO;
use Ineersa\CodingAgent\Extension\ChildRun\Metadata\RunStartedMetadataDTO;
use Ineersa\CodingAgent\Extension\ChildRun\Metadata\RunStartedSessionMetadataDTO;
use Ineersa\CodingAgent\Extension\ChildRun\Metadata\RunStartedToolsScopeDTO;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressChildRowDTO;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressParallelSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSingleSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSnapshotInterface;
use Ineersa\Tui\Runtime\TuiSessionLifecycleEventDTO;
use Ineersa\Tui\Theme\ThemeColorEnum;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Marks Hatfield dynamic entry points that ShipMonk cannot infer from PHP alone.
 *
 * Coverage is intentionally narrow:
 * - EventSubscriberInterface::getSubscribedEvents (Symfony provider marks
 *   returned listener methods, not the entrypoint itself)
 * - published ExtensionApi contracts
 * - theme YAML palette tokens
 * - proven Symfony Serializer / durable projection / event payload types
 * - verified MCP / subagent progress interface contracts
 */
final class HatfieldDeadCodeUsageProvider extends ReflectionBasedMemberUsageProvider
{
    /**
     * Exact classes whose public properties are read by Symfony Serializer,
     * durable projection persistence, or event-payload denormalization.
     *
     * @var list<class-string>
     */
    private const array SERIALIZED_PROPERTY_CLASSES = [
        ModelNotificationDTO::class,
        AgentDefinitionDTO::class,
        DeferredSubagentBatchProjectionDTO::class,
        DeferredSubagentChildProjectionDTO::class,
        RunStartedMetadataDTO::class,
        RunStartedSessionMetadataDTO::class,
        RunStartedToolsScopeDTO::class,
        SubagentProgressChildRowDTO::class,
        SubagentProgressParallelSnapshotDTO::class,
        SubagentProgressSingleSnapshotDTO::class,
        TuiSessionLifecycleEventDTO::class,
    ];

    /**
     * Exact internal interfaces whose methods are invoked over the interface
     * type in production (or only via test doubles typed as the interface).
     *
     * @var list<class-string>
     */
    private const array PRODUCTION_INTERFACE_CONTRACTS = [
        SubagentProgressSnapshotInterface::class,
        \Ineersa\CodingAgent\Mcp\Client\McpClientInterface::class,
        \Ineersa\CodingAgent\Mcp\Client\McpConnectionManagerInterface::class,
    ];

    protected function shouldMarkMethodAsUsed(\ReflectionMethod $method): ?VirtualUsageData
    {
        if ('getSubscribedEvents' === $method->getName()
            && $method->getDeclaringClass()->implementsInterface(EventSubscriberInterface::class)) {
            return VirtualUsageData::withNote('Symfony EventSubscriberInterface DIC entrypoint');
        }

        $className = $method->getDeclaringClass()->getName();

        if (str_starts_with($className, 'Ineersa\\Hatfield\\ExtensionApi\\')) {
            return VirtualUsageData::withNote('Published Hatfield ExtensionApi contract');
        }

        if (\in_array($className, self::PRODUCTION_INTERFACE_CONTRACTS, true)) {
            return VirtualUsageData::withNote('Production interface contract invoked over interface type / test doubles');
        }

        return null;
    }

    protected function shouldMarkPropertyAsRead(\ReflectionProperty $property): ?VirtualUsageData
    {
        $className = $property->getDeclaringClass()->getName();

        if (str_starts_with($className, 'Ineersa\\Hatfield\\ExtensionApi\\')) {
            return VirtualUsageData::withNote('Published Hatfield ExtensionApi contract');
        }

        if (\in_array($className, self::SERIALIZED_PROPERTY_CLASSES, true)) {
            return VirtualUsageData::withNote('Symfony Serializer / durable projection / event payload property');
        }

        if ($property->getDeclaringClass()->implementsInterface(SubagentProgressSnapshotInterface::class)) {
            return VirtualUsageData::withNote('Subagent progress wire snapshot property');
        }

        // PHP stream wrappers expose a public $context filled by the engine.
        if ('context' === $property->getName()
            && $property->getDeclaringClass()->hasMethod('stream_open')) {
            return VirtualUsageData::withNote('PHP stream-wrapper engine-managed $context property');
        }

        return null;
    }

    protected function shouldMarkEnumCaseAsUsed(\ReflectionEnumUnitCase $enumCase): ?VirtualUsageData
    {
        if (ThemeColorEnum::class === $enumCase->getDeclaringClass()->getName()) {
            return VirtualUsageData::withNote('Theme YAML palette token resolved by enum string value');
        }

        return null;
    }
}
