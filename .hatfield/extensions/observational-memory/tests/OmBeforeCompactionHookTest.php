<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookContextDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Compaction\OmBeforeCompactionHook;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OmBeforeCompactionHookTest extends OmTestCase
{
    #[DataProvider('responses')]
    public function testProjectionOrOrdinaryCompaction(string $body, int $status, bool $replacement, bool $timeout = false): void
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($body, $status, $timeout): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('http://librarian.test:8093/api/om/v1/sessions/hatfield/parent%2Fid/projection', $url);
            self::assertSame(0, $options['max_redirects']);
            self::assertSame(30.0, $options['max_duration']);
            self::assertContains('X-Librarian-Project: '.$this->directory, $options['headers']);
            if ($timeout) {
                throw new TimeoutException('fixture timeout');
            }

            return new MockResponse($body, ['http_code' => $status]);
        });
        $result = (new OmBeforeCompactionHook($this->api(), $this->settings(), new NullLogger(), $http))->beforeCompaction($this->context());
        $this->assertFalse($result->cancels());
        $this->assertSame($replacement, $result->hasReplacementSummary());
        $this->assertSame($timeout ? 0 : 1, $http->getRequestsCount());
    }

    public static function responses(): iterable
    {
        yield 'success parent snapshot' => ['{"ok":true,"contract_version":1,"text":"memory"}', 200, true];
        yield 'empty' => ['{"ok":true,"contract_version":1,"text":" "}', 200, false];
        yield 'malformed' => ['not json', 200, false];
        yield 'missing text' => ['{"ok":true,"contract_version":1}', 200, false];
        yield 'wrong version' => ['{"ok":true,"contract_version":2,"text":"memory"}', 200, false];
        yield 'wrong type' => ['{"ok":true,"contract_version":1,"text":42}', 200, false];
        yield 'client failure' => ['{}', 404, false];
        yield 'server failure' => ['{}', 503, false];
        yield 'timeout' => ['', 200, false, true];
        yield 'bounded body' => [str_repeat('x', 1048577), 200, false];
    }

    #[DataProvider('exclusions')]
    public function testExcludedContextsNeverRequest(bool $child, bool $worktree): void
    {
        $http = new MockHttpClient();
        $result = (new OmBeforeCompactionHook($this->api($child, $worktree), $this->settings(), new NullLogger(), $http))->beforeCompaction($this->context());
        $this->assertFalse($result->cancels());
        $this->assertFalse($result->hasReplacementSummary());
        $this->assertSame(0, $http->getRequestsCount());
    }

    public static function exclusions(): iterable
    {
        yield 'child' => [true, false];
        yield 'worktree' => [false, true];
    }

    private function context(): BeforeCompactionHookContextDTO
    {
        return new BeforeCompactionHookContextDTO(runId: 'parent/id', turnNo: 1, trigger: 'fork', requiredStartSeq: null, requiredEndSeq: null, tokenEstimateBefore: 1000, messagesCompacted: 10, messagesRetained: 5, firstRetainedIndex: 10, priorSummaryPresent: false, customInstructions: null, resolvedModel: null, thinkingLevel: null);
    }
}
