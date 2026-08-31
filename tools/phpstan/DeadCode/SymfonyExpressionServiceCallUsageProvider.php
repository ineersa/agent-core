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
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use ShipMonk\PHPStan\DeadCode\Enum\AccessType;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassPropertyRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassPropertyUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use ShipMonk\PHPStan\DeadCode\Provider\MemberUsageProvider;
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
final class SymfonyExpressionServiceCallUsageProvider implements MemberUsageProvider
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

    /** @var list<ClassMemberUsage>|null */
    private ?array $usages = null;

    /**
     * @return list<ClassMemberUsage>
     */
    public function getUsages(Node $node, Scope $scope): array
    {
        if (!$node instanceof InClassNode) { // @phpstan-ignore phpstanApi.instanceofAssumption
            return [];
        }

        $className = $node->getClassReflection()->getName();
        $matched = [];
        foreach ($this->allUsages() as $usage) {
            if ($usage->getMemberRef()->getClassName() === $className) {
                $matched[] = $usage;
            }
        }

        return $matched;
    }

    /**
     * @return list<ClassMemberUsage>
     */
    private function allUsages(): array
    {
        if (null !== $this->usages) {
            return $this->usages;
        }

        $this->usages = [];
        if (!is_file(self::CONTAINER_XML)) {
            return $this->usages;
        }

        $xml = file_get_contents(self::CONTAINER_XML);
        if (false === $xml) {
            return $this->usages;
        }

        if (!preg_match_all('/<argument type="expression">(.*?)<\\/argument>/s', $xml, $matches)) {
            return $this->usages;
        }

        $origin = UsageOrigin::createVirtual(
            $this,
            VirtualUsageData::withNote('Referenced from Symfony DIC ExpressionLanguage argument'),
        );

        $seen = [];
        foreach ($matches[1] as $expression) {
            $expression = html_entity_decode($expression, \ENT_QUOTES | \ENT_XML1);
            foreach ($this->extractMembers($expression) as [$class, $member, $isMethod]) {
                $key = $class.'::'.$member.($isMethod ? '()' : '');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $this->usages[] = $isMethod
                    ? new ClassMethodUsage($origin, new ClassMethodRef($class, $member, possibleDescendant: false))
                    : new ClassPropertyUsage(
                        $origin,
                        new ClassPropertyRef($class, $member, possibleDescendant: false),
                        AccessType::READ,
                    );
            }
        }

        return $this->usages;
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
