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
        $children = $tree->getRootNode()->normalizeKeys(false)->children();
        $children->stringNode('repository')->defaultValue('')->validate()->ifTrue(static fn (string $v): bool => '' !== $v && 1 !== preg_match('~^(?:[a-zA-Z0-9.-]+/)?[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$~', $v))->thenInvalid('Expected [HOST/]OWNER/REPO.')->end()->end();
        foreach (['remote' => 'origin', 'base_branch' => 'main', 'worktree_root' => '../worktrees', 'workflow_field' => 'Workflow'] as $key => $default) {
            $children->stringNode($key)->cannotBeEmpty()->defaultValue($default)->end();
        }
        $children->enumNode('merge_method')->values(['merge', 'squash', 'rebase'])->defaultValue('merge')->end();
        $hooks = $children->arrayNode('hooks')->addDefaultsIfNotSet()->children();
        foreach (['setup', 'validate', 'cleanup'] as $name) {
            $hooks->arrayNode($name)->children()
                ->stringNode('script')->isRequired()->cannotBeEmpty()->end()
                ->integerNode('timeout_seconds')->isRequired()->min(1)->end()
                ->end()->end();
        }

        return $tree;
    }
}
