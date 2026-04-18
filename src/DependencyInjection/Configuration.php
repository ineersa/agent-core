<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('agent_loop');

        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();
        $rootNode
            ->children()
                ->enumNode('runtime')->values(['messenger', 'inline'])->defaultValue('messenger')->end()
                ->enumNode('streaming')->values(['mercure', 'sse'])->defaultValue('mercure')->end()
                ->arrayNode('llm')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('default_model')->defaultValue('gpt-4o-mini')->cannotBeEmpty()->end()
                    ->end()
                ->end()
                ->arrayNode('storage')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('run_log')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('flysystem_storage')->defaultValue('agent_loop.run_logs')->end()
                                ->scalarNode('base_path')->defaultValue('%kernel.project_dir%/var/agent-runs')->end()
                            ->end()
                        ->end()
                        ->arrayNode('hot_prompt')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('backend')->defaultValue('doctrine')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('tools')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('defaults')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->enumNode('mode')->values(['sequential', 'parallel', 'interrupt'])->defaultValue('sequential')->end()
                                ->integerNode('timeout_seconds')->min(1)->defaultValue(90)->end()
                            ->end()
                        ->end()
                        ->integerNode('max_parallelism')->min(1)->defaultValue(4)->end()
                        ->arrayNode('overrides')
                            ->useAttributeAsKey('tool_name')
                            ->arrayPrototype()
                                ->children()
                                    ->enumNode('mode')->values(['sequential', 'parallel', 'interrupt'])->defaultNull()->end()
                                    ->integerNode('timeout_seconds')->min(1)->defaultNull()->end()
                                ->end()
                            ->end()
                            ->defaultValue([
                                'web_search' => ['mode' => 'parallel', 'timeout_seconds' => 120],
                                'ask_user' => ['mode' => 'interrupt', 'timeout_seconds' => 90],
                            ])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('commands')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_pending_per_run')->min(1)->defaultValue(100)->end()
                        ->scalarNode('custom_kind_prefix')->defaultValue('ext:')->cannotBeEmpty()->end()
                    ->end()
                ->end()
                ->arrayNode('events')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('custom_type_prefix')->defaultValue('ext_')->cannotBeEmpty()->end()
                    ->end()
                ->end()
                ->arrayNode('checkpoints')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('every_turns')->min(1)->defaultValue(5)->end()
                        ->integerNode('max_delta_kb')->min(1)->defaultValue(256)->end()
                    ->end()
                ->end()
                ->arrayNode('retention')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('hot_prompt_ttl_hours')->min(1)->defaultValue(24)->end()
                        ->integerNode('archive_after_days')->min(1)->defaultValue(7)->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $config): bool => !str_starts_with($config['commands']['custom_kind_prefix'], 'ext:'))
                ->thenInvalid('agent_loop.commands.custom_kind_prefix must start with "ext:".')
            ->end()
            ->validate()
                ->ifTrue(static fn (array $config): bool => !str_starts_with($config['events']['custom_type_prefix'], 'ext_'))
                ->thenInvalid('agent_loop.events.custom_type_prefix must start with "ext_".')
            ->end();

        return $treeBuilder;
    }
}
