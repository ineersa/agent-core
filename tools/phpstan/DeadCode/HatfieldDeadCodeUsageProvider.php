<?php

declare(strict_types=1);

namespace Ineersa\Tools\PHPStan\DeadCode;

use Ineersa\AgentCore\Domain\Notification\ModelNotificationDTO;
use Ineersa\AgentCore\Domain\Run\RunMetadata;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildProjectionDTO;
use Ineersa\CodingAgent\Extension\ChildRun\Metadata\RunStartedMetadataDTO;
use Ineersa\CodingAgent\Extension\ChildRun\Metadata\RunStartedSessionMetadataDTO;
use Ineersa\CodingAgent\Extension\ChildRun\Metadata\RunStartedToolsScopeDTO;
use Ineersa\CodingAgent\Extension\ExtensionToolRegistryBridge;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressChildRowDTO;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressParallelSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSingleSnapshotDTO;
use Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\Replay\StreamPacingHttpClient;
use Ineersa\Tui\Terminal\DeferredCursorCommitScreenWriter;
use Ineersa\Tui\Theme\ThemeColorEnum;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Marks Hatfield dynamic entry points that ShipMonk cannot infer from PHP alone.
 *
 * Coverage is intentionally narrow:
 * - EventSubscriberInterface::getSubscribedEvents (Symfony provider marks
 *   returned listener methods, not the entrypoint itself)
 * - published ExtensionApi contracts
 * - theme YAML palette tokens
 * - proven Symfony Serializer / durable projection / event payload types
 *
 * No class-wide internal-interface exemptions. Native call analysis covers
 * production interface methods; test-only interface members stay deletable.
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
        DeferredSubagentBatchProjectionDTO::class,
        DeferredSubagentChildProjectionDTO::class,
        RunMetadata::class,
        RunStartedMetadataDTO::class,
        RunStartedSessionMetadataDTO::class,
        RunStartedToolsScopeDTO::class,
        SubagentProgressChildRowDTO::class,
        SubagentProgressParallelSnapshotDTO::class,
        SubagentProgressSingleSnapshotDTO::class,
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

        // Host adapter for published ExtensionApiInterface::registerToolResultHook().
        // Concrete extensions currently register only call hooks, but the method is
        // part of the stable public API and must remain available on the host bridge.
        if (ExtensionToolRegistryBridge::class === $className
            && 'registerToolResultHook' === $method->getName()) {
            return VirtualUsageData::withNote('Published ExtensionApiInterface host implementation');
        }

        // Measured with this rule removed: ShipMonk reports both required
        // HttpClientInterface methods as "all usages excluded by tests excluder".
        if (StreamPacingHttpClient::class === $className
            && \in_array($method->getName(), ['stream', 'withOptions'], true)
            && $method->getDeclaringClass()->implementsInterface(HttpClientInterface::class)) {
            return VirtualUsageData::withNote('Required HttpClientInterface methods reported unused after test-usage exclusion');
        }

        if (DeferredCursorCommitScreenWriter::class === $className) {
            return VirtualUsageData::withNote('Symfony TUI ScreenWriter contract installed through class_alias');
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
