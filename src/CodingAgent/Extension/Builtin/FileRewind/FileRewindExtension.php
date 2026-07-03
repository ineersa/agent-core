<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\FileRewind;

use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;
use Psr\Log\NullLogger;

final class FileRewindExtension implements HatfieldExtensionInterface
{
    public function register(ExtensionApiInterface $api): void
    {
        $config = FileRewindConfig::fromSettings($api->getSettings('file_rewind'));
        $cwd = $api->getCwd();
        $paths = new RewindStoragePaths($cwd);
        $git = new GitProcessRunner($config->gitTimeoutSeconds);
        $backend = new HiddenGitSnapshotBackend($git, new NullLogger());
        $ledger = new FileRewindLedgerStore($cwd);
        $projector = new FileRewindLedgerProjector();
        $service = new FileRewindService($backend, $git, $paths, $ledger, $projector, $config, new NullLogger(), $cwd);
        $actionHandler = new FileRewindTuiActionHandler($service);
        $api->bindFileRewindRuntime($service, $actionHandler);

        $api->registerAfterTurnCommitHook(new FileRewindAfterTurnCommitHook($service, $config));
        $api->registerCommand(
            new CommandDefinitionDTO('rewind', [], 'Restore files and optionally rewind conversation to a prior turn', '/rewind', false),
            new FileRewindCommandHandler($api, $service, $actionHandler),
        );
    }
}
