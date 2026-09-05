<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\HatfieldExt\Jbcontext\JbcontextExtension;
use Ineersa\HatfieldExt\Jbcontext\Job\JbcontextEligibilityJobHandler;
use Ineersa\HatfieldExt\Jbcontext\Tests\Support\RecordingExec;
use Ineersa\HatfieldExt\Jbcontext\Tests\Support\TestExtensionApi;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JbcontextExtensionRegistrationTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createOsTempDir('jbcontext-reg-');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    #[Test]
    public function registerExposesCodeSearchAndStartupEligibilityJob(): void
    {
        $api = new TestExtensionApi($this->projectDir, new RecordingExec());
        (new JbcontextExtension())->register($api);

        $this->assertCount(1, $api->tools);
        $this->assertSame('code_search', $api->tools[0]->name);
        $this->assertSame(['text'], $api->tools[0]->parametersJsonSchema['required']);
        $this->assertArrayHasKey('path_filter', $api->tools[0]->parametersJsonSchema['properties']);
        $this->assertArrayHasKey(JbcontextEligibilityJobHandler::HANDLER_ID, $api->handlers);
        $this->assertCount(1, $api->afterTurnHooks);
        $this->assertNotEmpty($api->jobs);
        $this->assertSame(JbcontextEligibilityJobHandler::HANDLER_ID, $api->jobs[0]->handlerId);
        $this->assertSame(1, $api->jobs[0]->payload['attempt']);
    }
}
