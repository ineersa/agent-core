<?php

declare(strict_types=1);

namespace Ineersa\Tools\PHPStan\DeadCode;

use Ineersa\CodingAgent\Config\Ai\AiAgentRetryConfig;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\ToolExecutionConfig;
use Ineersa\CodingAgent\Config\ToolsConfig;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;

/**
 * Marks members referenced from Symfony DIC ExpressionLanguage arguments.
 *
 * ShipMonk's Symfony provider reads constructors/calls/factories from container
 * XML, but ignores {@code <argument type="expression">...</argument>} bodies.
 *
 * This provider parses those expressions and maps known Hatfield config-graph
 * chains onto exact owning classes (no namespace blankets).
 */
final class SymfonyExpressionServiceCallUsageProvider extends ReflectionBasedMemberUsageProvider
{
    private const string CONTAINER_XML = __DIR__.'/../../../var/phpstan-dead-code/symfony-container.xml';

    /**
     * Root service class => property/method name => next class or null for terminal scalar/method.
     *
     * @var array<class-string, array<string, class-string|null>>
     */
    private const array GRAPH = [
        AppResourceLocator::class => [
            'getAiCatalogPath' => null,
        ],
        SettingsPathResolver::class => [
            'getHomeDir' => null,
        ],
        AppConfig::class => [
            'ai' => AiConfig::class,
            'catalog' => null,
            'cwd' => null,
            'tools' => ToolsConfig::class,
        ],
        AiConfig::class => [
            'agentRetry' => AiAgentRetryConfig::class,
        ],
        AiAgentRetryConfig::class => [
            'resolveMaxAttempts' => null,
        ],
        ToolsConfig::class => [
            'execution' => ToolExecutionConfig::class,
        ],
        ToolExecutionConfig::class => [
            'maxParallelism' => null,
        ],
        LoggingConfig::class => [
            'logDir' => null,
        ],
    ];

    /** @var array<string, VirtualUsageData>|null */
    private ?array $memberIndex = null;

    protected function shouldMarkMethodAsUsed(\ReflectionMethod $method): ?VirtualUsageData
    {
        $className = $method->getDeclaringClass()->getName();
        $member = $method->getName();

        return $this->memberIndex()[$className.'::'.$member.'()'] ?? null;
    }

    protected function shouldMarkPropertyAsRead(\ReflectionProperty $property): ?VirtualUsageData
    {
        $className = $property->getDeclaringClass()->getName();
        $member = $property->getName();

        return $this->memberIndex()[$className.'::'.$member] ?? null;
    }

    /**
     * @return array<string, VirtualUsageData>
     */
    private function memberIndex(): array
    {
        if (null !== $this->memberIndex) {
            return $this->memberIndex;
        }

        $this->memberIndex = [];
        if (!is_file(self::CONTAINER_XML)) {
            return $this->memberIndex;
        }

        $xml = file_get_contents(self::CONTAINER_XML);
        if (false === $xml) {
            return $this->memberIndex;
        }

        if (!preg_match_all('/<argument type="expression">(.*?)<\\/argument>/s', $xml, $matches)) {
            return $this->memberIndex;
        }

        $note = VirtualUsageData::withNote('Referenced from Symfony DIC ExpressionLanguage argument');
        foreach ($matches[1] as $expression) {
            $expression = html_entity_decode($expression, \ENT_QUOTES | \ENT_XML1);
            foreach ($this->extractMembers($expression) as [$class, $member, $isMethod]) {
                $key = $class.'::'.$member.($isMethod ? '()' : '');
                $this->memberIndex[$key] = $note;
            }
        }

        return $this->memberIndex;
    }

    /**
     * @return list<array{0: class-string, 1: string, 2: bool}>
     */
    private function extractMembers(string $expression): array
    {
        $out = [];
        if (!preg_match_all(
            '/service\\(\s*["\']([^"\']+)["\']\s*\\)((?:\.[A-Za-z_][A-Za-z0-9_]*(?:\\(\\))?)+)/',
            $expression,
            $matches,
            \PREG_SET_ORDER,
        )) {
            return $out;
        }

        foreach ($matches as $match) {
            $current = str_replace('\\\\', '\\', $match[1]);
            if (!preg_match_all('/\.([A-Za-z_][A-Za-z0-9_]*)(\\(\\))?/', $match[2], $parts, \PREG_SET_ORDER)) {
                continue;
            }

            foreach ($parts as $part) {
                $member = $part[1];
                // Optional capture group is either absent or exactly "()".
                $isMethod = isset($part[2]);
                $graph = self::GRAPH[$current] ?? null;
                if (null === $graph || !\array_key_exists($member, $graph)) {
                    // Unknown chain — stop rather than guessing a class.
                    break;
                }

                $out[] = [$current, $member, $isMethod];
                $next = $graph[$member];
                if (null === $next || $isMethod) {
                    break;
                }
                $current = $next;
            }
        }

        return $out;
    }
}
