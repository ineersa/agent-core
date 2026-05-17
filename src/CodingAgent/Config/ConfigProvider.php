<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Abstracts config resolution behind a simple interface so that
 * {@see CompatRequestShaper} can be unit-tested without depending on the
 * final {@see AppConfigResolver} class.
 *
 * {@see AppConfigResolverConfigProvider} adapts the real resolver; tests
 * substitute an anonymous implementation that returns a canned
 * {@see AppConfig}.
 */
interface ConfigProvider
{
    /**
     * Return the resolved application config.
     *
     * @param string $projectCwd Target project directory (defaults to process cwd)
     */
    public function resolve(string $projectCwd = ''): AppConfig;
}
