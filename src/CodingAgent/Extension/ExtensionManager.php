<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Runtime\Contract\LoadedExtensionItemDTO;
use Ineersa\CodingAgent\Runtime\Contract\TuiExtensionRegistryInterface;
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
 *  2. Require the project-local extension Composer autoloader when the
 *     extensions directory exists: `<cwd>/.hatfield/extensions/vendor/autoload.php`.
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
final class ExtensionManager implements TuiExtensionRegistryInterface
{
    /** @var list<LoadedExtensionItemDTO> */
    private array $loadOutcomes = [];

    /** @var list<TuiExtensionInterface> */
    private array $tuiExtensions = [];

    public function __construct(
        private readonly AppConfig $config,
        private readonly ExtensionApiInterface $extensionApi,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $eventDispatcher,
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
     * register. An empty array means all extensions loaded cleanly.
     *
     * Safe to call even when no extensions are configured or
     * when the extension autoload file is absent.
     *
     * @return list<string> Human-readable diagnostic messages for failed extensions
     */
    public function loadExtensions(): array
    {
        $this->loadOutcomes = [];
        $this->tuiExtensions = [];
        $enabled = $this->getEnabledClasses();

        if ([] === $enabled) {
            return [];
        }

        $this->requireExtensionAutoload();

        return $this->loadEach($enabled);
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

        try {
            $instance->register($this->extensionApi);
        } catch (\Throwable $e) {
            $msg = \sprintf('Extension "%s" failed to register: %s', $className, $e->getMessage());
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

        // Native Symfony EventSubscriberInterface extensions subscribe to the
        // host dispatcher (ConsoleEvents, etc.) without a custom hook registry.
        if ($instance instanceof EventSubscriberInterface) {
            $this->eventDispatcher->addSubscriber($instance);
        }

        return null;
    }
}
