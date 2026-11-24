<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Bootstrap;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapDescriptorDTO;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapSpoolStore;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final class SessionBootstrapSpoolStoreTest extends IsolatedKernelTestCase
{
    public function testPrivateSealStreamsBoundedFramesAndRequiresExactCutAcknowledgement(): void
    {
        $store = static::getContainer()->get(SessionBootstrapSpoolStore::class);
        $run = static::getContainer()->get(HatfieldSessionStore::class)->createSession('bootstrap');
        $block = new TranscriptBlock('user', TranscriptBlockKindEnum::UserMessage, $run, 3, str_repeat('bounded ', 15000));
        $foreground = ['current_foreground_response_seq' => 7, 'pending_foreground_requests' => [['request_id' => 'question-1', 'category' => 'question']]];
        $descriptor = $store->seal($run, 11, 100, 3, [$block], $foreground + ['status' => 'completed', 'model' => null, 'turn_no' => 1]);
        $content = '';
        foreach ($store->frames($descriptor) as $frame) {
            $this->assertLessThanOrEqual(SessionBootstrapSpoolStore::FRAME_BYTES, \strlen($frame));
            $content .= $frame;
        }
        $this->assertSame(\strlen($content), $descriptor->bytes);
        $this->assertSame(hash('sha256', $content), $descriptor->checksum);
        $this->assertSame(2, $descriptor->records);
        $this->assertStringNotContainsString('RunState', $content);
        $this->assertArrayNotHasKey('path', $descriptor->toArray());
        $path = static::getContainer()->get(HatfieldSessionStore::class)->resolveSessionsBasePath().'/'.$run.'/runtime/bootstrap';
        $this->assertSame(0700, fileperms($path) & 0777);
        $this->assertSame(0600, fileperms($path.'/'.$descriptor->bootstrapId.'.jsonl') & 0777);
        $wrong = new SessionBootstrapDescriptorDTO($run, $descriptor->bootstrapId, $descriptor->viewEpoch, 10, $descriptor->endOffset, $descriptor->selectedAnchor, $descriptor->records, $descriptor->bytes, $descriptor->checksum);
        try {
            $store->acknowledge($wrong);
            $this->fail('Wrong cursors cannot acknowledge a spool.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('cut changed', $exception->getMessage());
        }
        $this->assertFileExists($path.'/'.$descriptor->bootstrapId.'.jsonl');
        $this->assertSame($foreground, $store->acknowledge($descriptor)->foreground());
        $this->assertFileDoesNotExist($path.'/active.json');
        $this->assertFileDoesNotExist($path.'/'.$descriptor->bootstrapId.'.jsonl');
    }

    #[DataProvider('malformedForeground')]
    public function testAcknowledgementRejectsMalformedSealedBaselineWithoutDeletingIt(array $foreground): void
    {
        $store = static::getContainer()->get(SessionBootstrapSpoolStore::class);
        $run = static::getContainer()->get(HatfieldSessionStore::class)->createSession('invalid baseline');
        $descriptor = $store->seal($run, 11, 100, 3, [], $foreground);
        try {
            $store->acknowledge($descriptor);
            $this->fail('Malformed sealed metadata must not become a ready baseline.');
        } catch (ValidationFailedException $exception) {
            $this->assertGreaterThan(0, \count($exception->getViolations()));
            $this->assertTrue($store->isActive($run));
        } finally {
            $store->cancel($run);
        }
    }

    public static function malformedForeground(): iterable
    {
        yield 'missing' => [[]];
        yield 'future sequence' => [['current_foreground_response_seq' => 12, 'pending_foreground_requests' => []]];
        yield 'string sequence' => [['current_foreground_response_seq' => '2', 'pending_foreground_requests' => []]];
        yield 'null attention' => [['current_foreground_response_seq' => null, 'pending_foreground_requests' => null]];
        yield 'content instead of category' => [['current_foreground_response_seq' => 2, 'pending_foreground_requests' => [['request_id' => 'q', 'category' => 'SECRET prompt']]]];
        yield 'extra content' => [['current_foreground_response_seq' => 2, 'pending_foreground_requests' => [['request_id' => 'q', 'category' => 'question', 'prompt' => 'SECRET']]]];
    }

    public function testSupersededAndCancelledOpenTransfersFailClosed(): void
    {
        $store = static::getContainer()->get(SessionBootstrapSpoolStore::class);
        $run = static::getContainer()->get(HatfieldSessionStore::class)->createSession('supersession');
        $blocks = [new TranscriptBlock('large', TranscriptBlockKindEnum::UserMessage, $run, 3, str_repeat('x', 100000))];
        $old = $store->seal($run, 11, 100, 3, $blocks, ['status' => 'completed', 'model' => null, 'turn_no' => 1]);
        $open = $store->frames($old);
        $this->assertNotEmpty($open->current());
        $current = $store->seal($run, 15, 140, 3, $blocks, ['status' => 'completed', 'model' => null, 'turn_no' => 1]);
        $this->assertGreaterThan($old->viewEpoch, $current->viewEpoch);
        try {
            $open->next();
            $this->fail('Supersession invalidates an already-open transfer.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('identity', $exception->getMessage());
        }
        $store->cancel($run);
        $this->expectException(\RuntimeException::class);
        iterator_to_array($store->frames($current));
    }

    public function testCorruptSpoolNeverCompletesSuccessfully(): void
    {
        $store = static::getContainer()->get(SessionBootstrapSpoolStore::class);
        $sessions = static::getContainer()->get(HatfieldSessionStore::class);
        $run = $sessions->createSession('corruption');
        $descriptor = $store->seal($run, 11, 100, 3, [], ['status' => 'completed', 'model' => null, 'turn_no' => 1]);
        $path = $sessions->resolveSessionsBasePath().'/'.$run.'/runtime/bootstrap/'.$descriptor->bootstrapId.'.jsonl';
        $body = file_get_contents($path);
        $this->assertNotFalse($body);
        file_put_contents($path, str_replace('completed', 'cancelled', $body));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('checksum mismatch');
        iterator_to_array($store->frames($descriptor));
    }

    public function testExpiryAndPathTokensRefuseWithoutChangingTheActiveSpool(): void
    {
        $store = static::getContainer()->get(SessionBootstrapSpoolStore::class);
        $run = static::getContainer()->get(HatfieldSessionStore::class)->createSession('expiry');
        $clock = Clock::get();
        Clock::set($mock = new MockClock('2026-10-09 00:00:00 UTC'));
        try {
            $descriptor = $store->seal($run, 11, 100, 3, [], ['status' => 'completed', 'model' => null, 'turn_no' => 1]);
            $invalid = new SessionBootstrapDescriptorDTO($run, '../events', $descriptor->viewEpoch, 11, 100, 3, $descriptor->records, $descriptor->bytes, $descriptor->checksum);
            try {
                iterator_to_array($store->frames($invalid));
                $this->fail('File paths cannot be used as tokens.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertSame('Invalid bootstrap token.', $exception->getMessage());
            }
            $mock->sleep(61);
            try {
                $store->acknowledge($descriptor);
                $this->fail('Expired transfers cannot be acknowledged.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Bootstrap transfer expired.', $exception->getMessage());
            }
            $store->cancel($run);
        } finally {
            Clock::set($clock);
        }
    }
}
