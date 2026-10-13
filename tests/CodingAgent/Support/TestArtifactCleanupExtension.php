<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use CastorTasks\TestArtifactLifecycle;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

require_once \dirname(__DIR__, 3).'/.castor/TestArtifactLifecycle.php';

/** Keep automatic cleanup after class teardown, not after each method's shared kernel. */
final class TestArtifactCleanupExtension implements Extension, ExecutionFinishedSubscriber
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        TestArtifactLifecycle::current(ProjectDir::get())->recover();
        $facade->registerSubscriber($this);
    }

    public function notify(ExecutionFinished $event): void
    {
        TestArtifactLifecycle::current(ProjectDir::get())->finish();
    }
}
