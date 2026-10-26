<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Settings;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class TaskWorkflowConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('task_workflow');
        $tree->getRootNode()->ignoreExtraKeys(false)->normalizeKeys(false)->children()
            ->stringNode('task_root')->info('External task-board directory; blank uses automatic discovery.')->defaultValue('')->end()
        ->end();

        return $tree;
    }
}
