<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\Hatfield\ExtensionApi\Command\CommandContextInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Command\OmViewCommandHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmQueryService;
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmSessionContext;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OmSessionContextCommandTest extends OmTestCase
{
    public function testViewResolvesLiveSessionOnEveryInvocation(): void
    {
        $tui = $this->createMock(TuiExtensionContextInterface::class);
        $tui->expects($this->exactly(2))->method('getSessionId')->willReturnOnConsecutiveCalls('first', 'second');
        $session = new OmSessionContext();
        $session->bindTui($tui);
        $urls = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return new MockResponse('{"ok":true,"contract_version":1,"text":"remote memory"}');
        });
        $handler = new OmViewCommandHandler(new OmQueryService($this->api(), $this->settings(), librarianHttpClient: $http), $session);
        $context = $this->createMock(CommandContextInterface::class);
        $context->expects($this->exactly(2))->method('notify')->with('remote memory', 'info');
        $handler->handle('', $context);
        $handler->handle('', $context);
        $this->assertSame(['http://librarian.test:8093/api/om/v1/sessions/hatfield/first/projection', 'http://librarian.test:8093/api/om/v1/sessions/hatfield/second/projection'], $urls);
    }

    public function testMissingSessionShowsSafeError(): void
    {
        $handler = new OmViewCommandHandler(new OmQueryService($this->api(), $this->settings()), new OmSessionContext());
        $context = $this->createMock(CommandContextInterface::class);
        $context->expects($this->once())->method('notify')->with('OM view unavailable.', 'error');
        $handler->handle('', $context);
    }
}
