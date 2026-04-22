#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Export Symfony DI wiring metadata derived from a compiled container.
 *
 * Output schema (Toon):
 * - spec: agent-core.di-wiring/v1
 * - classes[]:
 *   - class
 *   - serviceDefinitions[]
 *   - aliases[]
 *   - injectedInto[]
 *
 * Usage:
 *   php scripts/export-wiring-map.php [--output=var/reports/di-wiring.toon] [--dry-run]
 */

require dirname(__DIR__).'/vendor/autoload.php';

use HelgeSverre\Toon\Toon;
use Ineersa\AgentCore\AgentLoopBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\Argument\AbstractArgument;
use Symfony\Component\DependencyInjection\Argument\BoundArgument;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel;

const PROJECT_NAMESPACE_PREFIX = 'Ineersa\\AgentCore\\';

$projectRoot = dirname(__DIR__);
$outputPath = $projectRoot.'/var/reports/di-wiring.toon';
$dryRun = false;

$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++) {
    $arg = $args[$i];

    if ('--dry-run' === $arg) {
        $dryRun = true;

        continue;
    }

    if ('--output' === $arg) {
        $candidate = $args[$i + 1] ?? '';
        if ('' === trim($candidate) || str_starts_with($candidate, '--')) {
            fwrite(STDERR, "Missing value for --output\n");
            exit(1);
        }
        $outputPath = str_starts_with($candidate, '/') ? $candidate : $projectRoot.'/'.$candidate;
        $i++;

        continue;
    }

    if (str_starts_with($arg, '--output=')) {
        $candidate = trim(substr($arg, strlen('--output=')));
        if ('' === $candidate) {
            fwrite(STDERR, "Missing value for --output\n");
            exit(1);
        }
        $outputPath = str_starts_with($candidate, '/') ? $candidate : $projectRoot.'/'.$candidate;

        continue;
    }

    fwrite(STDERR, "Unknown option: {$arg}\n");
    exit(1);
}

try {
    $container = buildCompiledContainer();

    $aliases = $container->getAliases();
    $definitions = $container->getDefinitions();

    /** @var array<string, string> $classByService */
    $classByService = [];
    /** @var array<string, list<array<string, mixed>>> $serviceDefinitionsByClass */
    $serviceDefinitionsByClass = [];
    /** @var array<string, list<array<string, string>>> $aliasesByClass */
    $aliasesByClass = [];
    /** @var array<string, list<array<string, int|string>>> $injectedIntoByClass */
    $injectedIntoByClass = [];

    /** @var array<string, array{file: string, line?: int}|null> $classLocationCache */
    $classLocationCache = [];

    foreach ($definitions as $serviceId => $definition) {
        if (!isEligibleServiceId($serviceId) || $definition->isAbstract()) {
            continue;
        }

        $className = resolveDefinitionClass($serviceId, $definition, $container);
        if (null === $className || !isProjectClass($className)) {
            continue;
        }

        $classByService[$serviceId] = $className;

        $entry = [
            'serviceId' => $serviceId,
            'visibility' => $definition->isPublic() ? 'public' : 'private',
            'autowire' => $definition->isAutowired(),
            'autoconfigure' => $definition->isAutoconfigured(),
        ];

        $location = classLocation($className, $projectRoot, $classLocationCache);
        if (null !== $location) {
            $entry['file'] = $location['file'];
            if (isset($location['line'])) {
                $entry['line'] = $location['line'];
            }
        }

        $argumentRefs = normalizeDefinitionArgumentReferences($definition, $aliases, $className);
        if ([] !== $argumentRefs) {
            $entry['args'] = $argumentRefs;
        }

        $serviceDefinitionsByClass[$className][] = $entry;
    }

    foreach ($aliases as $aliasId => $alias) {
        if (!isEligibleServiceId($aliasId)) {
            continue;
        }

        $targetId = resolveAliasTargetId((string) $alias, $aliases);
        $entry = [
            'serviceId' => $aliasId,
            'target' => $targetId,
        ];

        $targetClass = $classByService[$targetId] ?? (isProjectClass($targetId) ? $targetId : null);

        if (null !== $targetClass && isProjectClass($targetClass)) {
            $aliasesByClass[$targetClass][] = $entry;
        }

        if (isProjectClass($aliasId)) {
            $aliasesByClass[$aliasId][] = $entry;
        }
    }

    foreach ($definitions as $consumerServiceId => $definition) {
        if (!isEligibleServiceId($consumerServiceId) || $definition->isAbstract()) {
            continue;
        }

        $consumerClass = resolveDefinitionClass($consumerServiceId, $definition, $container);
        if (null === $consumerClass || !isProjectClass($consumerClass)) {
            continue;
        }

        $references = collectDefinitionReferenceIds($definition);
        if ([] === $references) {
            continue;
        }

        foreach ($references as $referenceId) {
            $targetId = resolveAliasTargetId($referenceId, $aliases);
            if (!isEligibleServiceId($targetId)) {
                continue;
            }

            $providerClass = $classByService[$targetId] ?? (isProjectClass($targetId) ? $targetId : null);
            if (null === $providerClass || !isProjectClass($providerClass) || $providerClass === $consumerClass) {
                continue;
            }

            $entry = ['fqcn' => $consumerClass];
            if ($consumerServiceId !== $consumerClass) {
                $entry['serviceId'] = $consumerServiceId;
            }

            $location = classLocation($consumerClass, $projectRoot, $classLocationCache);
            if (null !== $location) {
                $entry['file'] = $location['file'];
                if (isset($location['line'])) {
                    $entry['line'] = $location['line'];
                }
            }

            $injectedIntoByClass[$providerClass][] = $entry;
        }
    }

    $allClasses = array_values(array_unique([
        ...array_keys($serviceDefinitionsByClass),
        ...array_keys($aliasesByClass),
        ...array_keys($injectedIntoByClass),
    ]));
    sort($allClasses);

    $classEntries = [];
    foreach ($allClasses as $className) {
        $entry = ['class' => $className];

        if (isset($serviceDefinitionsByClass[$className])) {
            $serviceEntries = dedupeEntries($serviceDefinitionsByClass[$className]);
            usort($serviceEntries, static fn (array $left, array $right): int => $left['serviceId'] <=> $right['serviceId']);
            $entry['serviceDefinitions'] = $serviceEntries;
        }

        if (isset($aliasesByClass[$className])) {
            $aliasEntries = dedupeEntries($aliasesByClass[$className]);
            usort(
                $aliasEntries,
                static fn (array $left, array $right): int => [$left['serviceId'], $left['target']] <=> [$right['serviceId'], $right['target']],
            );
            $entry['aliases'] = $aliasEntries;
        }

        if (isset($injectedIntoByClass[$className])) {
            $injectedEntries = dedupeEntries($injectedIntoByClass[$className]);
            usort(
                $injectedEntries,
                static fn (array $left, array $right): int => [
                    $left['fqcn'],
                    $left['serviceId'] ?? $left['fqcn'],
                ] <=> [
                    $right['fqcn'],
                    $right['serviceId'] ?? $right['fqcn'],
                ],
            );
            $entry['injectedInto'] = $injectedEntries;
        }

        $classEntries[] = $entry;
    }

    $payload = [
        'spec' => 'agent-core.di-wiring/v1',
        'classes' => $classEntries,
    ];

    $toon = Toon::encode($payload);

    if ($dryRun) {
        $outRel = str_starts_with($outputPath, $projectRoot.'/')
            ? substr($outputPath, strlen($projectRoot) + 1)
            : $outputPath;
        echo "wiring-map: ok (dry-run, output={$outRel}, classes=".count($classEntries).")\n";
        exit(0);
    }

    $dir = dirname($outputPath);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException(sprintf('Could not create output directory: %s', $dir));
    }

    file_put_contents($outputPath, $toon);

    $definitionCount = array_sum(array_map(static fn (array $entries): int => count($entries), $serviceDefinitionsByClass));
    $aliasCount = array_sum(array_map(static fn (array $entries): int => count($entries), $aliasesByClass));
    $injectedEdgeCount = array_sum(array_map(static fn (array $entries): int => count($entries), $injectedIntoByClass));

    $outRel = str_starts_with($outputPath, $projectRoot.'/')
        ? substr($outputPath, strlen($projectRoot) + 1)
        : $outputPath;

    echo sprintf(
        'wiring-map: ok (classes=%d,service_definitions=%d,aliases=%d,injected_edges=%d,output=%s)',
        count($classEntries),
        $definitionCount,
        $aliasCount,
        $injectedEdgeCount,
        $outRel,
    )."\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'wiring-map: failed - '.$exception->getMessage()."\n");
    exit(1);
}

function buildCompiledContainer(): ContainerBuilder
{
    $kernel = new class ('test', false) extends Kernel {
        use MicroKernelTrait;

        public function registerBundles(): iterable
        {
            yield new FrameworkBundle();
            yield new AgentLoopBundle();
        }

        public function getProjectDir(): string
        {
            return dirname(__DIR__);
        }

        public function getCacheDir(): string
        {
            return sys_get_temp_dir().'/agent-core/wiring-map/cache/'.$this->environment;
        }

        public function getLogDir(): string
        {
            return sys_get_temp_dir().'/agent-core/wiring-map/log/'.$this->environment;
        }

        protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
        {
            unset($loader, $builder);

            $container->extension('framework', [
                'secret' => 'wiring-map-secret',
                'test' => true,
                'http_method_override' => false,
                'messenger' => [
                    'default_bus' => 'agent.command.bus',
                ],
            ]);

            $container->extension('agent_loop', []);
        }
    };

    $initializeBundles = Closure::bind(fn (): mixed => $this->initializeBundles(), $kernel, Kernel::class);
    $initializeBundles();

    $buildContainer = Closure::bind(fn (): mixed => $this->buildContainer(), $kernel, Kernel::class);

    /** @var ContainerBuilder $container */
    $container = $buildContainer();

    $passConfig = $container->getCompilerPassConfig();
    $passConfig->setRemovingPasses([]);
    $passConfig->setAfterRemovingPasses([]);

    $container->compile();

    return $container;
}

function isEligibleServiceId(string $serviceId): bool
{
    if ('' === $serviceId) {
        return false;
    }

    if (str_starts_with($serviceId, '.')) {
        return false;
    }

    if (str_starts_with($serviceId, 'test.')) {
        return false;
    }

    return true;
}

function isProjectClass(string $className): bool
{
    return str_starts_with($className, PROJECT_NAMESPACE_PREFIX);
}

/**
 * @param array<string, Alias> $aliases
 */
function resolveAliasTargetId(string $serviceId, array $aliases): string
{
    $current = $serviceId;
    $visited = [];

    while (isset($aliases[$current])) {
        if (isset($visited[$current])) {
            break;
        }

        $visited[$current] = true;
        $current = (string) $aliases[$current];
    }

    return $current;
}

function resolveDefinitionClass(string $serviceId, Definition $definition, ContainerBuilder $container): ?string
{
    $className = $definition->getClass();

    if (is_string($className) && '' !== trim($className)) {
        try {
            $resolved = $container->getParameterBag()->resolveValue($className);
            if (is_string($resolved) && '' !== trim($resolved)) {
                $className = $resolved;
            }
        } catch (Throwable) {
            // Keep unresolved class literal if container parameters cannot resolve it.
        }
    }

    if (!is_string($className) || '' === trim($className)) {
        if (!str_contains($serviceId, '\\')) {
            return null;
        }
        $className = $serviceId;
    }

    $className = ltrim($className, '\\');

    if (!str_contains($className, '\\')) {
        return null;
    }

    return $className;
}

/**
 * @param array<string, array{file: string, line?: int}|null> $cache
 *
 * @return array{file: string, line?: int}|null
 */
function classLocation(string $className, string $projectRoot, array &$cache): ?array
{
    if (array_key_exists($className, $cache)) {
        return $cache[$className];
    }

    if (!class_exists($className) && !interface_exists($className) && !trait_exists($className) && (!function_exists('enum_exists') || !enum_exists($className))) {
        $cache[$className] = null;

        return null;
    }

    try {
        $reflection = new ReflectionClass($className);
    } catch (ReflectionException) {
        $cache[$className] = null;

        return null;
    }

    $file = $reflection->getFileName();
    if (false === $file) {
        $cache[$className] = null;

        return null;
    }

    $location = ['file' => toProjectRelativePath($file, $projectRoot)];

    $line = $reflection->getStartLine();
    if (is_int($line) && $line > 0) {
        $location['line'] = $line;
    }

    $cache[$className] = $location;

    return $location;
}

function toProjectRelativePath(string $path, string $projectRoot): string
{
    $normalizedPath = str_replace('\\\\', '/', $path);
    $normalizedRoot = rtrim(str_replace('\\\\', '/', $projectRoot), '/').'/';

    if (str_starts_with($normalizedPath, $normalizedRoot)) {
        return substr($normalizedPath, strlen($normalizedRoot));
    }

    return $path;
}

/**
 * @param Definition $definition
 * @param array<string, Alias> $aliases
 *
 * @return array<string, string>
 */
function normalizeDefinitionArgumentReferences(Definition $definition, array $aliases, string $className): array
{
    $constructorParams = constructorParameterNames($className);

    $normalized = [];
    foreach ($definition->getArguments() as $index => $argument) {
        $referenceIds = extractReferencesFromValue($argument);
        if ([] === $referenceIds) {
            continue;
        }

        $resolvedRefs = [];
        foreach ($referenceIds as $referenceId) {
            $resolvedRefs[] = resolveAliasTargetId($referenceId, $aliases);
        }
        $resolvedRefs = array_values(array_unique($resolvedRefs));
        sort($resolvedRefs);

        $argName = match (true) {
            is_string($index) => str_starts_with($index, '$') ? $index : '$'.$index,
            is_int($index) && isset($constructorParams[$index]) => '$'.$constructorParams[$index],
            default => '#'.(string) $index,
        };

        $normalized[$argName] = 1 === count($resolvedRefs)
            ? 'service('.$resolvedRefs[0].')'
            : 'services('.implode('|', $resolvedRefs).')';
    }

    ksort($normalized);

    return $normalized;
}

/**
 * @return array<int, string>
 */
function constructorParameterNames(string $className): array
{
    if (!class_exists($className)) {
        return [];
    }

    try {
        $reflection = new ReflectionClass($className);
    } catch (ReflectionException) {
        return [];
    }

    $constructor = $reflection->getConstructor();
    if (null === $constructor) {
        return [];
    }

    $names = [];
    foreach ($constructor->getParameters() as $index => $parameter) {
        $names[$index] = $parameter->getName();
    }

    return $names;
}

/**
 * @return list<string>
 */
function collectDefinitionReferenceIds(Definition $definition): array
{
    $references = [];

    $references = [...$references, ...extractReferencesFromValue($definition->getArguments())];
    $references = [...$references, ...extractReferencesFromValue($definition->getProperties())];
    $references = [...$references, ...extractReferencesFromValue($definition->getFactory())];
    $references = [...$references, ...extractReferencesFromValue($definition->getConfigurator())];

    foreach ($definition->getMethodCalls() as [, $methodArguments]) {
        $references = [...$references, ...extractReferencesFromValue($methodArguments)];
    }

    $decorated = $definition->getDecoratedService();
    if (is_array($decorated) && isset($decorated[0]) && is_string($decorated[0]) && '' !== $decorated[0]) {
        $references[] = $decorated[0];
    }

    $references = array_values(array_unique($references));
    sort($references);

    return $references;
}

/**
 * @return list<string>
 */
function extractReferencesFromValue(mixed $value): array
{
    if ($value instanceof Reference) {
        return [(string) $value];
    }

    if (
        $value instanceof IteratorArgument
        || $value instanceof TaggedIteratorArgument
        || $value instanceof ServiceLocatorArgument
        || $value instanceof ServiceClosureArgument
        || $value instanceof BoundArgument
    ) {
        return extractReferencesFromValue($value->getValues());
    }

    if ($value instanceof AbstractArgument) {
        return [];
    }

    if ($value instanceof Definition) {
        return collectDefinitionReferenceIds($value);
    }

    if (!is_array($value)) {
        return [];
    }

    $references = [];
    foreach ($value as $item) {
        $references = [...$references, ...extractReferencesFromValue($item)];
    }

    $references = array_values(array_unique($references));
    sort($references);

    return $references;
}

/**
 * @template T of array<string, mixed>
 *
 * @param list<T> $entries
 *
 * @return list<T>
 */
function dedupeEntries(array $entries): array
{
    $deduped = [];

    foreach ($entries as $entry) {
        $key = json_encode($entry, JSON_THROW_ON_ERROR);
        $deduped[$key] = $entry;
    }

    return array_values($deduped);
}
