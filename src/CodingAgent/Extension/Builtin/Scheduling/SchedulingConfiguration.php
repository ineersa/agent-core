<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\Scheduling;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/** Scheduling has membership controls, but no extension settings. */
final class SchedulingConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        return new TreeBuilder('scheduling');
    }
}
