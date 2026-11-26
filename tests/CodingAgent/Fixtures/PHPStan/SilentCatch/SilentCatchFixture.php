<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Fixtures\PHPStan\SilentCatch;

use Psr\Log\LoggerInterface;

final class SilentCatchFixture
{
    public function __construct(
        private LoggerInterface $logger,
        private ?LoggerInterface $optionalLogger,
        private Notifier $notifier,
    ) {
    }

    public function rethrows(): void
    {
        try {
            $this->work();
        } catch (\RuntimeException $e) {
            throw $e;
        }
    }

    public function throwsNew(): void
    {
        try {
            $this->work();
        } catch (\RuntimeException $e) {
            throw new \LogicException('Wrapped.', 0, $e);
        }
    }

    public function throwExpression(): ?string
    {
        try {
            return $this->value();
        } catch (\RuntimeException $e) {
            return $this->value() ?? throw $e;
        }
    }

    public function logs(): void
    {
        try {
            $this->work();
        } catch (\RuntimeException $e) {
            $this->logger->warning('work.failed', ['exception_class' => $e::class]);
        }
    }

    public function logsThroughNullsafeLogger(): void
    {
        try {
            $this->work();
        } catch (\RuntimeException $e) {
            $this->optionalLogger?->warning('work.failed', ['exception_class' => $e::class]);
        }
    }

    public function logsInOnlyOneBranch(bool $verbose): ?string
    {
        try {
            return $this->value();
        } catch (\RuntimeException) {
            if ($verbose) {
                $this->logger->debug('work.failed');
            }

            return null;
        }
    }

    public function returnsTypedRefusal(): FailureResult
    {
        try {
            $this->work();

            return new FailureResult('ok');
        } catch (\RuntimeException $e) {
            return new FailureResult($e->getMessage());
        }
    }

    public function returnsFactoryResult(): FailureResult
    {
        try {
            $this->work();

            return new FailureResult('ok');
        } catch (\RuntimeException $e) {
            return FailureResult::fromThrowable($e);
        }
    }

    public function passesToPrivateLogHelper(): void
    {
        try {
            $this->work();
        } catch (\RuntimeException $e) {
            $this->logFailure($e);
        }
    }

    public function passesToFailureCallback(\Closure $onFailure): void
    {
        try {
            $this->work();
        } catch (\RuntimeException $e) {
            $onFailure($e);
        }
    }

    public function storesResultBuiltFromFailure(): void
    {
        try {
            $this->work();
        } catch (\RuntimeException $e) {
            $this->notifier->setResult(new FailureResult($e->getMessage()));
        }
    }

    public function notifiesUserWithMessage(): void
    {
        try {
            $this->work();
        } catch (\RuntimeException $e) {
            $this->notifier->error('Work failed: '.$e->getMessage());
        }
    }

    public function empty(): void
    {
        try {
            $this->work();
        } catch (\RuntimeException) {
        }
    }

    public function returnsNull(): ?string
    {
        try {
            return $this->value();
        } catch (\RuntimeException) {
            return null;
        }
    }

    public function returnsFalse(): bool
    {
        try {
            $this->work();

            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    /** @return list<string> */
    public function returnsEmptyArray(): array
    {
        try {
            $this->work();

            return ['ok'];
        } catch (\RuntimeException) {
            return [];
        }
    }

    public function returnsMagicString(): string
    {
        try {
            $this->work();

            return 'ok';
        } catch (\RuntimeException $e) {
            return 'error: '.$e->getMessage();
        }
    }

    public function returnsObjectNotBuiltFromFailure(): FailureResult
    {
        try {
            $this->work();

            return new FailureResult('ok');
        } catch (\RuntimeException) {
            return new FailureResult('unavailable');
        }
    }

    public function notifiesWithoutTheException(): void
    {
        try {
            $this->work();
        } catch (\RuntimeException $e) {
            $this->notifier->error('Work failed.');
        }
    }

    public function discardsMessage(): void
    {
        try {
            $this->work();
        } catch (\RuntimeException $e) {
            $e->getMessage();
        }
    }

    public function bareReturn(): void
    {
        try {
            $this->work();
        } catch (\RuntimeException $e) {
            return;
        }
    }

    public function continuesLoop(): void
    {
        foreach ([1, 2] as $ignored) {
            try {
                $this->work();
            } catch (\RuntimeException $e) {
                continue;
            }
        }
    }

    public function throwsOnlyInsideClosure(): void
    {
        try {
            $this->work();
        } catch (\RuntimeException $e) {
            $this->defer(static function () use ($e): never {
                throw $e;
            });
        }
    }

    public function nestedTryCatch(): void
    {
        try {
            $this->work();
        } catch (\RuntimeException $outer) {
            try {
                $this->work();
            } catch (\LogicException) {
            }
            $this->logger->error('outer.failed', ['exception_class' => $outer::class]);
        }
    }

    public function multipleCatchesJudgedSeparately(): void
    {
        try {
            $this->work();
        } catch (\LogicException $e) {
            throw $e;
        } catch (\RuntimeException) {
        }
    }

    private function logFailure(\Throwable $exception): void
    {
    }

    private function work(): void
    {
    }

    private function defer(\Closure $callback): void
    {
    }

    private function value(): ?string
    {
        return null;
    }
}
