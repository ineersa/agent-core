<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\CodingAgent\Runtime\Messenger\MessengerDeliveryEnvelopeRestorer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\EnvelopeAwareExceptionInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Messenger\Stamp\AckStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Validator\ConstraintViolationList;

final class MessengerDeliveryEnvelopeRestorerTest extends TestCase
{
    public function testRestoresDeliveryAndAckIdentityWithoutDroppingResultStamps(): void
    {
        $ack = new AckStamp(static fn () => null);
        $original = new Envelope(new \stdClass(), [$ack]);
        $stamp = new HandledStamp('result', 'handler');
        $result = new Envelope(clone $original->getMessage(), [$stamp, new AckStamp(static fn () => null)]);
        $restored = new MessengerDeliveryEnvelopeRestorer()->restoreReference($result, $original);
        $this->assertSame($original->getMessage(), $restored->getMessage());
        $this->assertSame($stamp, $restored->last(HandledStamp::class));
        $this->assertSame([$ack], $restored->all(AckStamp::class));
    }

    public function testRestoresKnownExceptionEnvelopesAndPreservesFailureSemantics(): void
    {
        $original = new Envelope(new \stdClass());
        $stamp = new HandledStamp('partial result', 'handler');
        $result = new Envelope(clone $original->getMessage(), [$stamp]);
        $cause = new RecoverableMessageHandlingException('retry', retryDelay: 123);
        $violations = new ConstraintViolationList();
        $failures = [
            new HandlerFailedException($result, [$cause]),
            new DelayedMessageHandlingException([$cause], $result),
            new ValidationFailedException($result->getMessage(), $violations, $result),
        ];
        $restorer = new MessengerDeliveryEnvelopeRestorer();
        foreach ($failures as $failure) {
            $restored = $restorer->restoreException($failure, $original);
            $this->assertSame($failure::class, $restored::class);
            $this->assertInstanceOf(EnvelopeAwareExceptionInterface::class, $restored);
            $this->assertSame($original->getMessage(), $restored->getEnvelope()->getMessage());
            $this->assertSame($stamp, $restored->getEnvelope()->last(HandledStamp::class));
            if ($restored instanceof ValidationFailedException) {
                $this->assertSame($violations, $restored->getViolations());
                $this->assertSame($result->getMessage(), $restored->getViolatingMessage());
            } else {
                $this->assertSame([$cause], $restored->getWrappedExceptions());
            }
        }
        $this->assertSame($cause, $restorer->restoreException($cause, $original));
    }
}
