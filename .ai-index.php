<?php

declare(strict_types=1);

use Ineersa\AgentCore\AgentLoopBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\AI\Platform\PlatformInterface as SymfonyPlatformInterface;
use Symfony\AI\Platform\Test\InMemoryPlatform;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelInterface;

return [
    'srcDir' => 'src',
    'projectNamespacePrefix' => 'Ineersa\\AgentCore\\',
    'callGraph' => [
        'outputPath' => 'callgraph.json',
        'phpstanBin' => 'vendor/bin/phpstan',
        'configPath' => 'vendor/ineersa/call-graph/callgraph.neon',
    ],
    'wiring' => [
        'outputPath' => 'var/reports/di-wiring.toon',
        'kernelFactory' => static function (string $environment, bool $debug, string $projectRoot): KernelInterface {
            return new class ($environment, $debug, $projectRoot) extends Kernel {
                use MicroKernelTrait;

                public function __construct(
                    string $environment,
                    bool $debug,
                    private readonly string $projectRoot,
                ) {
                    parent::__construct($environment, $debug);
                }

                public function registerBundles(): iterable
                {
                    yield new FrameworkBundle();
                    yield new AgentLoopBundle();
                }

                public function getProjectDir(): string
                {
                    return $this->projectRoot;
                }

                public function getCacheDir(): string
                {
                    return sys_get_temp_dir().'/agent-core/ai-index/cache/'.$this->environment;
                }

                public function getLogDir(): string
                {
                    return sys_get_temp_dir().'/agent-core/ai-index/log/'.$this->environment;
                }

                protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
                {
                    unset($loader, $builder);

                    $container->extension('framework', [
                        'secret' => 'ai-index-secret',
                        'test' => true,
                        'http_method_override' => false,
                        'messenger' => [
                            'default_bus' => 'agent.command.bus',
                        ],
                    ]);

                    $container->extension('agent_loop', [
                        'llm' => [
                            'default_model' => 'ai-index-model',
                        ],
                    ]);

                    $container->services()
                        ->set(InMemoryPlatform::class)
                        ->arg('$mockResult', 'ai-index-platform-response')
                    ;
                    $container->services()->alias(SymfonyPlatformInterface::class, InMemoryPlatform::class);
                }
            };
        },
        'environment' => 'test',
        'debug' => false,
        'spec' => 'agent-core.di-wiring/v1',
    ],
    'index' => [
        'spec' => [
            'file' => 'agent-core.file-index/v1',
            'namespace' => 'agent-core.ai-docs/v1',
        ],
    ],
];
