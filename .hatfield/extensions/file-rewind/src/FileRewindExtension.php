<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\FileRewind;

use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiProjectExtensionInterface;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Psr\Log\NullLogger;

final class FileRewindExtension implements HatfieldExtensionInterface, TuiProjectExtensionInterface
{
    private ?FileRewindService $service = null;

    public function register(ExtensionApiInterface $api): void
    {
        $config = FileRewindConfig::fromSettings($api->getSettings('file_rewind'));
        $cwd = $api->getCwd();
        $paths = new RewindStoragePaths($cwd);
        $git = new GitProcessRunner($config->gitTimeoutSeconds);
        $backend = new HiddenGitSnapshotBackend($git, new NullLogger());
        $ledger = new FileRewindLedgerStore($cwd);
        $projector = new FileRewindLedgerProjector();
        $this->service = new FileRewindService($backend, $git, $paths, $ledger, $projector, $config, new NullLogger(), $cwd);

        $api->registerAfterTurnCommitHook(new FileRewindAfterTurnCommitHook($this->service, $config));
        $api->registerCommand(
            new CommandDefinitionDTO('rewind', [], 'Restore files to a prior checkpoint turn', '/rewind', false),
            new FileRewindCommandHandler($this->service),
        );
    }

    public function registerTui(object $tuiRuntimeContext): void
    {
        if (!$tuiRuntimeContext instanceof TuiRuntimeContext || null === $this->service) {
            return;
        }
        $picker = new FileRewindPickerController(
            $this->service,
            $tuiRuntimeContext->turnTreeProvider,
        );
        $picker->wire($tuiRuntimeContext);
        FileRewindPickerRegistry::set($picker);
    }
}
