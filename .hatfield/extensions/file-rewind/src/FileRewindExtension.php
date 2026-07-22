<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\FileRewind;

use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionInterface;
use Psr\Log\NullLogger;

final class FileRewindExtension implements HatfieldExtensionInterface, TuiExtensionInterface
{
    private ?FileRewindService $service = null;
    private ?FileRewindPickerController $picker = null;

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
        $this->picker = new FileRewindPickerController($this->service);
        $api->registerCommand(
            new CommandDefinitionDTO('rewind', [], 'Restore files to a prior checkpoint turn', '/rewind', false),
            new FileRewindCommandHandler($this->picker),
        );
    }

    public function registerTui(TuiExtensionContextInterface $context): void
    {
        $this->picker?->wire($context);
    }
}
