<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class AgentLoopExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        foreach (['messenger.php', 'doctrine.php'] as $configFile) {
            /** @var array<string, array<array<string, mixed>>> $configs */
            $configs = require \dirname(__DIR__, 2).'/config/'.$configFile;

            foreach ($configs as $extensionAlias => $extensionConfigs) {
                if (!$container->hasExtension($extensionAlias)) {
                    continue;
                }

                foreach ($extensionConfigs as $extensionConfig) {
                    $container->prependExtensionConfig($extensionAlias, $extensionConfig);
                }
            }
        }
    }

    /**
     * @param array<mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('agent_loop.config', $config);
        $container->setParameter('agent_loop.runtime', $config['runtime']);
        $container->setParameter('agent_loop.streaming', $config['streaming']);
        $container->setParameter('agent_loop.llm.default_model', $config['llm']['default_model']);
        $container->setParameter('agent_loop.storage.run_log.base_path', $config['storage']['run_log']['base_path']);
        $container->setParameter('agent_loop.storage.run_log.flysystem_storage', $config['storage']['run_log']['flysystem_storage']);
        $container->setParameter('agent_loop.storage.hot_prompt.backend', $config['storage']['hot_prompt']['backend']);
        $container->setParameter('agent_loop.tools.defaults.mode', $config['tools']['defaults']['mode']);
        $container->setParameter('agent_loop.tools.defaults.timeout_seconds', $config['tools']['defaults']['timeout_seconds']);
        $container->setParameter('agent_loop.tools.max_parallelism', $config['tools']['max_parallelism']);
        $container->setParameter('agent_loop.tools.overrides', $config['tools']['overrides']);
        $container->setParameter('agent_loop.commands.max_pending_per_run', $config['commands']['max_pending_per_run']);
        $container->setParameter('agent_loop.commands.steer_drain_mode', $config['commands']['steer_drain_mode']);
        $container->setParameter('agent_loop.commands.resume_stale_after_seconds', $config['commands']['resume_stale_after_seconds']);
        $container->setParameter('agent_loop.commands.custom_kind_prefix', $config['commands']['custom_kind_prefix']);
        $container->setParameter('agent_loop.events.custom_type_prefix', $config['events']['custom_type_prefix']);
        $container->setParameter('agent_loop.checkpoints.every_turns', $config['checkpoints']['every_turns']);
        $container->setParameter('agent_loop.checkpoints.max_delta_kb', $config['checkpoints']['max_delta_kb']);
        $container->setParameter('agent_loop.retention.hot_prompt_ttl_hours', $config['retention']['hot_prompt_ttl_hours']);
        $container->setParameter('agent_loop.retention.archive_after_days', $config['retention']['archive_after_days']);

        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config'));
        $loader->load('services.php');

        $container
            ->setAlias('agent_loop.run_log.storage', $config['storage']['run_log']['flysystem_storage'])
            ->setPublic(false)
        ;
    }
}
