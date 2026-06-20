<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Controller\ConsumerSupervisor;
use Ineersa\CodingAgent\Runtime\Controller\LlmStdoutPoller;
use Ineersa\CodingAgent\Runtime\Controller\RuntimeEventEmitter;
use Ineersa\CodingAgent\Runtime\Process\AppExecutableLocator;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * @covers \Ineersa\CodingAgent\Runtime\Controller\LlmStdoutPoller
 */
final class LlmStdoutPollerTest extends TestCase
{
    public function testConstructsWithDefaults(): void
    {
        $supervisor = $this->createSupervisor();
        $emitter = $this->createEmitter();
        $boundary = new RuntimeExceptionBoundary(new EventDispatcher());
        $logger = self::createStub(LoggerInterface::class);

        $poller = new LlmStdoutPoller($supervisor, $emitter, $boundary, $logger);

        self::assertInstanceOf(LlmStdoutPoller::class, $poller);
    }

    public function testConstructsWithCustomMaxBadLines(): void
    {
        $supervisor = $this->createSupervisor();
        $emitter = $this->createEmitter();
        $boundary = new RuntimeExceptionBoundary(new EventDispatcher());
        $logger = self::createStub(LoggerInterface::class);

        $poller = new LlmStdoutPoller($supervisor, $emitter, $boundary, $logger, 5);

        self::assertInstanceOf(LlmStdoutPoller::class, $poller);
    }

    private function createSupervisor(): ConsumerSupervisor
    {
        $logger = self::createStub(LoggerInterface::class);
        $locator = self::createStub(AppExecutableLocator::class);
        $locator->method('path')->willReturn('/tmp/test/console');
        $locator->method('command')->willReturn(['php', '/tmp/test/console']);
        $config = new RuntimeProcessConfig($locator, '/tmp');

        return new ConsumerSupervisor($logger, $config);
    }

    private function createEmitter(): RuntimeEventEmitter
    {
        $boundary = new RuntimeExceptionBoundary(new EventDispatcher());
        $logger = self::createStub(LoggerInterface::class);

        return new RuntimeEventEmitter(
            eventClient: null,
            boundary: $boundary,
            logger: $logger,
        );
    }
}
