<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;
use Psr\Log\LoggerInterface;

/**
 * Discovers and loads enabled Hatfield extensions at startup.
 *
 * Responsibilities:
 *  1. Read the list of enabled extension class names from merged Hatfield
 *     settings (extensions.enabled), which follows the standard precedence:
 *     built-in defaults < home settings < project settings.
 *  2. Require the project-local extension Composer autoloader when the
 *     extensions directory exists: `<cwd>/.hatfield/extensions/vendor/autoload.php`.
 *  3. For each enabled class name, verify the class exists, instantiate it,
 *     confirm it implements HatfieldExtensionInterface, and call register($api).
 *  4. Handle lifecycle errors deterministically: log and continue processing
 *     remaining extensions; do not crash the startup sequence.
 *
 * This service is invoked once at the beginning of the agent command lifecycle,
 * before the interactive mode or controller loop starts.
 */
final readonly class ExtensionManager
{
    public function __construct(
        private AppConfig $config,
        private ExtensionApiInterface $extensionApi,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Load all enabled extensions.
     *
     * Safe to call even when no extensions are configured or
     * when the extension autoload file is absent.
     */
    public function loadExtensions(): void
    {
        $enabled = $this->getEnabledClasses();

        if ([] === $enabled) {
            return;
        }

        $this->requireExtensionAutoload();
        $this->loadEach($enabled);
    }

    /**
     * @return list<class-string>
     */
    private function getEnabledClasses(): array
    {
        $classes = $this->config->extensions->enabled;

        if (!\is_array($classes)) {
            $this->logger->warning('extensions.enabled is not a list; ignoring', [
                'value' => $classes,
            ]);

            return [];
        }

        /* @var list<class-string> */
        return array_values(array_filter($classes, \is_string(...)));
    }

    /**
     * Require the project-local extension Composer autoloader if present.
     *
     * This loads extension packages installed via Composer in the
     * project-local .hatfield/extensions/ environment. It is safe to call
     * when the autoload file does not exist — it is simply a no-op.
     */
    private function requireExtensionAutoload(): void
    {
        $autoloadPath = $this->config->cwd.'/.hatfield/extensions/vendor/autoload.php';

        if (!is_file($autoloadPath)) {
            return;
        }

        // The autoloader registers itself with spl_autoload_register().
        // require_once prevents double-loading if the same file path is
        // required again (e.g. during test isolation).
        require_once $autoloadPath;
    }

    /**
     * @param list<class-string> $classNames
     */
    private function loadEach(array $classNames): void
    {
        foreach ($classNames as $className) {
            $this->loadSingle($className);
        }
    }

    /**
     * Instantiate a single extension and register it.
     *
     * Logs a warning for missing classes and invalid implementations
     * without aborting the startup sequence, so a misconfigured extension
     * does not prevent other extensions from loading.
     */
    private function loadSingle(string $className): void
    {
        if ('' === $className) {
            return;
        }

        if (!class_exists($className)) {
            $this->logger->warning('Extension class not found; skipping', [
                'class' => $className,
            ]);

            return;
        }

        $instance = new $className();

        if (!$instance instanceof HatfieldExtensionInterface) {
            $this->logger->warning('Extension class does not implement HatfieldExtensionInterface; skipping', [
                'class' => $className,
            ]);

            return;
        }

        try {
            $instance->register($this->extensionApi);
        } catch (\Throwable $e) {
            $this->logger->error('Extension registration threw an exception', [
                'class' => $className,
                'exception' => $e,
            ]);
        }
    }
}
