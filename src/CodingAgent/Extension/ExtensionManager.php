<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Composer\Autoload\ClassLoader;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Runtime\Contract\LoadedExtensionItemDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Discovers and loads enabled Hatfield extensions at startup.
 *
 * Responsibilities:
 *  1. Read the list of enabled extension class names from merged Hatfield
 *     settings (extensions.enabled), which follows the standard precedence:
 *     built-in defaults < home settings < project settings.
 *  2. Require extension Composer autoloaders when present:
 *     project `<cwd>/.hatfield/extensions/vendor/autoload.php`, then user
 *     `~/.hatfield/extensions/vendor/autoload.php`. Project registers first so
 *     project packages win collisions. Installer-pinned autoloader-suffix values
 *     keep project/user ComposerAutoloaderInit class names distinct when both
 *     roots share the same package set. Both keep prepend-autoloader=false so
 *     host libraries remain highest priority. Identical resolved paths load once.
 *  3. For each enabled class name, verify the class exists, instantiate it,
 *     inject logger when LoggerAwareInterface, confirm HatfieldExtensionInterface,
 *     call register($api), and if EventSubscriberInterface register on the host
 *     EventDispatcher.
 *  4. Handle lifecycle errors deterministically: log and continue processing
 *     remaining extensions; do not crash the startup sequence.
 *
 * This service is invoked once at the beginning of the agent command lifecycle,
 * before the interactive mode or controller loop starts.
 */
final class ExtensionManager
{
    /** @var list<LoadedExtensionItemDTO> */
    private array $loadOutcomes = [];

    /** @var list<TuiExtensionInterface> */
    private array $tuiExtensions = [];

    private bool $loaded = false;

    public function __construct(
        private readonly AppConfig $config,
        private readonly ExtensionApiInterface $extensionApi,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly SettingsPathResolver $pathResolver,
    ) {
    }

    /**
     * @return list<TuiExtensionInterface>
     */
    public function getTuiExtensions(): array
    {
        return $this->tuiExtensions;
    }

    /**
     * Structured outcomes from the most recent {@see loadExtensions()} call.
     *
     * @return list<LoadedExtensionItemDTO>
     */
    public function getLoadOutcomes(): array
    {
        return $this->loadOutcomes;
    }

    /**
     * Load all enabled extensions.
     *
     * Returns diagnostic messages for any extension that failed to
     * load. An empty array means all extensions loaded cleanly.
     *
     * Safe to call even when no extensions are configured or
     * when the extension autoload file is absent.
     *
     * @return list<string> Human-readable diagnostic messages for failed extensions
     */
    public function loadExtensions(): array
    {
        // Idempotent per process. ExtensionLoaderSubscriber and explicit test
        // callers must not re-register tools/hooks or wipe load outcomes that
        // ChildExtensionSelectionService uses for fail-closed validation.
        if ($this->loaded) {
            return $this->diagnosticsFromOutcomes();
        }

        $this->loaded = true;
        $this->loadOutcomes = [];
        $this->tuiExtensions = [];
        $enabled = $this->getEnabledClasses();

        if ([] === $enabled) {
            return [];
        }

        $this->loadAutoloaders();

        return $this->loadEach($enabled);
    }

    /**
     * Require project then user extension Composer autoloaders when present.
     *
     * Physical-root suffixes let distinct roots load identical package sets;
     * aliases of one root share a loader. Refresh maps in place after Composer
     * installs another package, without changing host/project/user priority.
     */
    public function loadAutoloaders(): void
    {
        foreach ($this->extensionAutoloadPaths() as $autoloadPath) {
            if (!is_file($autoloadPath)) {
                continue;
            }

            $vendor = \dirname($autoloadPath);
            $loader = ClassLoader::getRegisteredLoaders()[$vendor] ?? null;
            if (null === $loader) {
                // Installer sets prepend-autoloader=false: host libraries win.
                require_once $autoloadPath;
                continue;
            }
            // Composer's init class caches its loader, so requiring autoload.php
            // again cannot discover packages installed during this wizard.
            foreach (require $vendor.'/composer/autoload_psr4.php' as $prefix => $paths) {
                $loader->setPsr4($prefix, $paths);
            }
            foreach (require $vendor.'/composer/autoload_namespaces.php' as $prefix => $paths) {
                $loader->add($prefix, $paths);
            }
            $loader->addClassMap(require $vendor.'/composer/autoload_classmap.php');
        }
    }

    /**
     * @return list<string>
     */
    private function diagnosticsFromOutcomes(): array
    {
        $diagnostics = [];
        foreach ($this->loadOutcomes as $item) {
            if (!$item->loaded) {
                $diagnostics[] = '' !== $item->errorMessage
                    ? $item->errorMessage
                    : \sprintf('Extension "%s" failed to load.', $item->className);
            }
        }

        return $diagnostics;
    }

    /**
     * @return list<class-string>
     */
    private function getEnabledClasses(): array
    {
        /* @var list<class-string> */
        return array_values(array_filter($this->config->extensions->enabled, \is_string(...)));
    }

    /**
     * @return list<string>
     */
    private function extensionAutoloadPaths(): array
    {
        $paths = [];
        $seen = [];

        $project = rtrim($this->config->cwd, '/').'/.hatfield/extensions/vendor/autoload.php';
        $resolvedProject = $this->resolveExistingPath($project) ?? $project;
        $paths[] = $resolvedProject;
        $seen[$resolvedProject] = true;

        $homeDir = $this->pathResolver->getHomeDir();
        if ('' !== $homeDir) {
            $user = rtrim($homeDir, '/').'/.hatfield/extensions/vendor/autoload.php';
            $resolvedUser = $this->resolveExistingPath($user) ?? $user;
            if (!isset($seen[$resolvedUser])) {
                $paths[] = $resolvedUser;
            }
        }

        return $paths;
    }

    private function resolveExistingPath(string $path): ?string
    {
        $resolved = realpath($path);

        return false === $resolved ? null : $resolved;
    }

    /**
     * @param list<class-string> $classNames
     *
     * @return list<string> Diagnostic messages for failed extensions
     */
    private function loadEach(array $classNames): array
    {
        $diagnostics = [];

        foreach ($classNames as $className) {
            $msg = $this->loadSingle($className);
            if (null !== $msg) {
                $diagnostics[] = $msg;
            }
        }

        // Outcomes are appended in loadSingle().

        return $diagnostics;
    }

    /**
     * Instantiate a single extension and register it.
     *
     * Logs a warning for missing classes and invalid implementations
     * without aborting the startup sequence, so a misconfigured extension
     * does not prevent other extensions from loading.
     *
     * @return string|null Diagnostic message if the extension failed to load, null if successful
     */
    private function loadSingle(string $className): ?string
    {
        if ('' === $className) {
            return null;
        }

        try {
            if (!class_exists($className)) {
                $msg = \sprintf('Extension class "%s" not found — skipping.', $className);
                $this->logger->warning($msg, ['class' => $className]);
                $this->loadOutcomes[] = new LoadedExtensionItemDTO($className, false, $msg);

                return $msg;
            }

            $instance = new $className();

            if (!$instance instanceof HatfieldExtensionInterface) {
                $msg = \sprintf('Extension class "%s" does not implement HatfieldExtensionInterface — skipping.', $className);
                $this->logger->warning($msg, ['class' => $className]);
                $this->loadOutcomes[] = new LoadedExtensionItemDTO($className, false, $msg);

                return $msg;
            }

            // Inject the host logger before register() so LoggerAware extensions can
            // log registration failures with the process-local Monolog channel.
            if ($instance instanceof LoggerAwareInterface) {
                $instance->setLogger($this->logger);
            }

            ExtensionRegistrationContext::withOwner($className, function () use ($instance): void {
                $instance->register($this->extensionApi);
            });

            // Native Symfony subscribers use the host dispatcher for console
            // events without a custom hook registry. Attach before recording success.
            // Partial registrations are not rolled back when attachment fails.
            if ($instance instanceof EventSubscriberInterface) {
                $this->eventDispatcher->addSubscriber($instance);
            }
        } catch (\Throwable $e) {
            $msg = \sprintf('Extension "%s" failed to load: %s', $className, $e->getMessage());
            $this->logger->error($msg, [
                'class' => $className,
                'exception' => $e,
            ]);
            $this->loadOutcomes[] = new LoadedExtensionItemDTO($className, false, $msg);

            return $msg;
        }

        $this->loadOutcomes[] = new LoadedExtensionItemDTO($className, true);
        if ($instance instanceof TuiExtensionInterface) {
            $this->tuiExtensions[] = $instance;
        }

        return null;
    }
}
