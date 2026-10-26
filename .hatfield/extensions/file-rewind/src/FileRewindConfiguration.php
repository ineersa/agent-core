<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\FileRewind;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class FileRewindConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('file_rewind');
        $tree->getRootNode()->ignoreExtraKeys(false)->normalizeKeys(false)->children()
            ->booleanNode('enabled')->info('Record file checkpoints.')->defaultValue(FileRewindConfig::DEFAULT_ENABLED)->end()
            ->integerNode('max_retained_turns')->info('Maximum retained checkpoint turns.')->min(1)->defaultValue(FileRewindConfig::DEFAULT_MAX_RETAINED_TURNS)->end()
            ->integerNode('git_timeout_seconds')->info('Git command timeout in seconds.')->min(1)->defaultValue(FileRewindConfig::DEFAULT_GIT_TIMEOUT_SECONDS)->end()
        ->end();

        return $tree;
    }
}
