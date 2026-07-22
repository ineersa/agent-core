<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI;

use Ineersa\CodingAgent\Extension\ExtensionManager;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Runtime\ExtensionEntrypointInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

/**
 * Generic host bootstrap for extension-owned CLI entrypoints.
 *
 * Boots the normal Hatfield kernel/console path. Extension loading happens on
 * ConsoleEvents::COMMAND before this command runs, so the selected extension
 * receives a fresh process-local ExtensionApi (including agent/session APIs).
 *
 * Identification is the fully-qualified extension class name. Exact match only
 * — no host-side background registry or process supervision.
 */
#[AsCommand(
    name: 'extension:run',
    description: 'Run a named entrypoint on an enabled extension class',
)]
final class ExtensionRunCommand
{
    public function __construct(
        private readonly ExtensionManager $extensionManager,
        private readonly ExtensionApiInterface $extensionApi,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        #[Argument(description: 'Fully-qualified Hatfield extension class name')]
        string $extensionClass,
        #[Argument(description: 'Entrypoint name declared by the extension')]
        string $entrypoint,
    ): int {
        $instance = $this->extensionManager->getLoadedExtension($extensionClass);
        if (null === $instance) {
            $this->logger->error('extension.run.unknown_extension', [
                'component' => 'extension_run',
                'event_type' => 'extension.run.unknown_extension',
                'extension_class' => $extensionClass,
                'entrypoint' => $entrypoint,
            ]);

            return Command::FAILURE;
        }

        if (!$instance instanceof ExtensionEntrypointInterface) {
            $this->logger->error('extension.run.not_entrypoint', [
                'component' => 'extension_run',
                'event_type' => 'extension.run.not_entrypoint',
                'extension_class' => $instance::class,
                'entrypoint' => $entrypoint,
            ]);

            return Command::FAILURE;
        }

        $supported = $instance::entrypoints();
        if (!\in_array($entrypoint, $supported, true)) {
            $this->logger->error('extension.run.unknown_entrypoint', [
                'component' => 'extension_run',
                'event_type' => 'extension.run.unknown_entrypoint',
                'extension_class' => $instance::class,
                'entrypoint' => $entrypoint,
                'supported_count' => \count($supported),
            ]);

            return Command::FAILURE;
        }

        $this->logger->info('extension.run.invoke', [
            'component' => 'extension_run',
            'event_type' => 'extension.run.invoke',
            'extension_class' => $instance::class,
            'entrypoint' => $entrypoint,
        ]);

        try {
            return $instance->runEntrypoint($entrypoint, $this->extensionApi);
        } catch (\Throwable $e) {
            $this->logger->error('extension.run.failed', [
                'component' => 'extension_run',
                'event_type' => 'extension.run.failed',
                'extension_class' => $instance::class,
                'entrypoint' => $entrypoint,
                'exception_class' => $e::class,
            ]);

            return Command::FAILURE;
        }
    }
}
