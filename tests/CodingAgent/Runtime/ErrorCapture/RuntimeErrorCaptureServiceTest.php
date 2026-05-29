<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\ErrorCapture;

use Ineersa\CodingAgent\Runtime\ErrorCapture\RuntimeErrorCaptureConfig;
use Ineersa\CodingAgent\Runtime\ErrorCapture\RuntimeErrorCaptureService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RuntimeErrorCaptureServiceTest extends TestCase
{
    /** @return LoggerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private function createLogger(): LoggerInterface
    {
        return $this->createMock(LoggerInterface::class);
    }

    // ── handleError ──────────────────────────────────────────────

    #[Test]
    public function handleErrorWhenCaptureEnabledLogsAndReturns(): void
    {
        $config = new RuntimeErrorCaptureConfig(envValue: '1');
        $service = new RuntimeErrorCaptureService($config);
        $logger = $this->createLogger();
        $service->setLogger($logger);

        $exception = new \RuntimeException('test error');

        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->equalTo('Runtime error captured'),
                $this->callback(fn (array $ctx) => 'test_operation' === ($ctx['capture_context'] ?? null)
                    && ($ctx['exception'] ?? null) === $exception),
            );

        // Should not throw when capture is enabled.
        $service->handleError($exception, 'test_operation', ['key' => 'value']);
        $this->assertTrue(true); // Reached — didn't throw.
    }

    #[Test]
    public function handleErrorWhenCaptureDisabledRethrows(): void
    {
        $config = new RuntimeErrorCaptureConfig(envValue: '0');
        $service = new RuntimeErrorCaptureService($config);
        $logger = $this->createLogger();
        $service->setLogger($logger);

        $exception = new \RuntimeException('crash error');

        $logger->expects($this->once())
            ->method('notice')
            ->with(
                $this->equalTo('Error capture disabled — rethrowing exception'),
                $this->callback(fn (array $ctx) => 'crash_operation' === ($ctx['capture_context'] ?? null)),
            );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('crash error');

        $service->handleError($exception, 'crash_operation');
    }

    #[Test]
    public function handleErrorRethrowsOriginalExceptionNotWrapped(): void
    {
        $config = new RuntimeErrorCaptureConfig(envValue: '0');
        $service = new RuntimeErrorCaptureService($config);
        $logger = $this->createLogger();
        $logger->expects($this->once())
            ->method('notice');
        $service->setLogger($logger);

        $original = new \LogicException('specific error');

        try {
            $service->handleError($original, 'test');
        } catch (\Throwable $e) {
            $this->assertSame($original, $e, 'The rethrown exception must be the original, not a wrapper.');

            return;
        }

        $this->fail('Expected exception was not thrown.');
    }

    // ── handleDegradation (never rethrows) ───────────────────────

    #[Test]
    public function handleDegradationLogsWarningButNeverRethrows(): void
    {
        // Even with capture disabled, degradation must NOT rethrow.
        $config = new RuntimeErrorCaptureConfig(envValue: '0');
        $service = new RuntimeErrorCaptureService($config);
        $logger = $this->createLogger();
        $service->setLogger($logger);

        $exception = new \RuntimeException('exif parse failed');

        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->equalTo('Local degradation handled'),
                $this->callback(fn (array $ctx) => 'image_exif_fallback' === ($ctx['capture_context'] ?? null)),
            );

        // Must not throw — degradation is always safe.
        $service->handleDegradation($exception, 'image_exif_fallback');
        $this->assertTrue(true); // Reached.
    }

    #[Test]
    public function handleDegradationWorksWhenCaptureEnabled(): void
    {
        $config = new RuntimeErrorCaptureConfig(envValue: '1');
        $service = new RuntimeErrorCaptureService($config);
        $logger = $this->createLogger();
        $service->setLogger($logger);

        $exception = new \RuntimeException('observer hook failed');

        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->equalTo('Local degradation handled'),
                $this->anything(),
            );

        $service->handleDegradation($exception, 'observer_hook');
        // No assertion needed — just verifying no throw.
    }

    // ── LoggerAwareInterface ──────────────────────────────────────

    #[Test]
    public function worksWithoutLoggerSet(): void
    {
        // No logger injected — must not crash. Do not call setLogger().
        $config = new RuntimeErrorCaptureConfig(envValue: '1');
        $service = new RuntimeErrorCaptureService($config);

        $exception = new \RuntimeException('no logger');

        // Must not crash when logger is null.
        $service->handleError($exception, 'test');
        $this->assertTrue(true);
    }
}
