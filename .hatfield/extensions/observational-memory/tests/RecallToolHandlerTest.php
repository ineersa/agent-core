<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use HelgeSverre\Toon\Toon;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCancellationTokenInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmQueryService;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmTestCase;
use Ineersa\HatfieldExt\ObservationalMemory\Tool\RecallToolHandler;

final class RecallToolHandlerTest extends OmTestCase
{
    public function testIdOnlyValidationAndChildRefusalAreStructured(): void
    {
        $handler = new RecallToolHandler(new OmQueryService($this->api(child: true), $this->settings()));
        $this->assertSame('invalid_id', Toon::decode($handler([], new ToolInvocationContextDTO('child')))['error']);
        $this->assertSame('main_only', Toon::decode($handler(['id' => str_repeat('a', 12)], new ToolInvocationContextDTO('child')))['error']);
    }

    public function testCancellationRemainsControlMapNotEncodedText(): void
    {
        $token = $this->createStub(ToolCancellationTokenInterface::class);
        $token->method('isCancellationRequested')->willReturn(true);
        $handler = new RecallToolHandler(new OmQueryService($this->api(), $this->settings()));
        $this->assertTrue($handler(['id' => str_repeat('a', 12)], new ToolInvocationContextDTO('main', $token))['cancelled']);
    }
}
