<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\EnvelopeAwareExceptionInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\ValidationFailedException as MessageValidationFailedException;
use Symfony\Component\Messenger\Stamp\AckStamp;

/** Preserve Messenger's delivery identity when a handler replaces its message. */
final class MessengerDeliveryEnvelopeRestorer
{
    public function restoreReference(Envelope $handled, Envelope $original): Envelope
    {
        $restored = new Envelope($original->getMessage(), array_merge(...array_values($handled->all())));
        if (null !== $ack = $original->last(AckStamp::class)) {
            $restored = $restored->withoutAll(AckStamp::class)->with($ack);
        }

        return $restored;
    }

    public function restoreException(\Throwable $exception, Envelope $original): \Throwable
    {
        if ($exception instanceof HandlerFailedException) {
            return new HandlerFailedException(
                $this->restoreReference($exception->getEnvelope(), $original),
                $exception->getWrappedExceptions(),
            );
        }
        if ($exception instanceof DelayedMessageHandlingException && null !== $exception->getEnvelope()) {
            return new DelayedMessageHandlingException(
                $exception->getWrappedExceptions(),
                $this->restoreReference($exception->getEnvelope(), $original),
            );
        }
        if ($exception instanceof MessageValidationFailedException && null !== $exception->getEnvelope()) {
            return new MessageValidationFailedException(
                $exception->getViolatingMessage(), $exception->getViolations(),
                $this->restoreReference($exception->getEnvelope(), $original),
            );
        }
        if ($exception instanceof EnvelopeAwareExceptionInterface && null !== $exception->getEnvelope()) {
            // Symfony inspects wrapped recoverable/unrecoverable causes and their
            // retry delays. Keep an unknown implementation intact as that cause.
            return new HandlerFailedException($this->restoreReference($exception->getEnvelope(), $original), [$exception]);
        }

        return $exception;
    }
}
