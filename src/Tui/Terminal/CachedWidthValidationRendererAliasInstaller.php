<?php

declare(strict_types=1);

namespace Ineersa\Tui\Terminal;

/**
 * Installs Hatfield's width-caching Renderer before Symfony TUI constructs it.
 *
 * Symfony Tui accepts a Renderer constructor argument, but it is typed as the
 * concrete final Renderer class, so an independent WidgetRendererInterface
 * implementation cannot be injected. Keep this alias until upstream accepts a
 * replaceable renderer type and width-validation reuse lands
 * (https://github.com/ineersa/agent-core/issues/467).
 */
final class CachedWidthValidationRendererAliasInstaller
{
    private const string SYMFONY_RENDERER = 'Symfony\\Component\\Tui\\Render\\Renderer';

    public static function install(): void
    {
        if (class_exists(self::SYMFONY_RENDERER, false)) {
            if (CachedWidthValidationRenderer::class === (new \ReflectionClass(self::SYMFONY_RENDERER))->getName()) {
                return;
            }

            throw new \RuntimeException('Cached width-validation Renderer must be installed before Symfony Renderer loads.');
        }

        if (!class_exists(CachedWidthValidationRenderer::class, true)) {
            throw new \RuntimeException('Cached width-validation Renderer could not be loaded.');
        }

        if (!class_alias(CachedWidthValidationRenderer::class, self::SYMFONY_RENDERER, false)) {
            throw new \RuntimeException('Unable to install cached width-validation Renderer.');
        }
    }
}
