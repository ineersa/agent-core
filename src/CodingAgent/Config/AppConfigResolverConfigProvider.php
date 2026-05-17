<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Adapts the final {@see AppConfigResolver} to the {@see ConfigProvider}
 * interface so that consumers like {@see CompatRequestShaper} can depend
 * on the interface rather than on a final class.
 */
final readonly class AppConfigResolverConfigProvider implements ConfigProvider
{
    public function __construct(
        private AppConfigResolver $resolver,
    ) {
    }

    public function resolve(string $projectCwd = ''): AppConfig
    {
        return $this->resolver->resolve($projectCwd);
    }
}
