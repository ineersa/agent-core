<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\Hatfield360;

use Amp\TimeoutCancellation;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiSessionObserverInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Uid\Uuid;

use function Amp\async;
use function Amp\Socket\connect;

final class Hatfield360Extension implements HatfieldExtensionInterface, TuiSessionObserverInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const int MAX_FRAME_BYTES = 16_384;
    // /reload replaces the extension instance, not the explicit launch.
    private static int $sequence = 0;
    private bool $sending = false;
    private ?string $endpoint = null;
    private string $launchId = '';
    private string $token = '';

    /** @var array{sessionId: string, sequence: int}|null */
    private ?array $pending = null;

    public function register(ExtensionApiInterface $api): void
    {
        $endpoint = getenv('H360_STATUS_ENDPOINT');
        $launchId = getenv('H360_STATUS_LAUNCH_ID');
        $token = getenv('H360_STATUS_TOKEN');
        if (\is_string($endpoint) && str_starts_with($endpoint, '/') && !str_contains($endpoint, "\0")
            && \is_string($launchId) && Uuid::isValid($launchId)
            && \is_string($token) && 1 === preg_match('/\A[0-9a-f]{64}\z/', $token)) {
            $this->endpoint = $endpoint;
            $this->launchId = $launchId;
            $this->token = $token;
        }
    }

    public function onTuiSessionSelected(string $sessionId): void
    {
        if (null === $this->endpoint) {
            return;
        }

        // Observe order here, not when the asynchronous sender gets CPU time.
        // Retain only the newest unsent selection; there is no delivery backlog.
        $this->pending = ['sessionId' => $sessionId, 'sequence' => ++self::$sequence];
        if ($this->sending) {
            return;
        }
        $this->sending = true;
        async(function (): void {
            try {
                while (null !== ($selection = $this->pending)) {
                    $this->pending = null;
                    $this->send($selection['sessionId'], $selection['sequence']);
                }
            } finally {
                $this->sending = false;
            }
        })->ignore();
    }

    private function send(string $sessionId, int $sequence): void
    {
        try {
            $frame = json_encode([
                'version' => 1,
                'kind' => 'session.started',
                'launchId' => $this->launchId,
                'token' => $this->token,
                'eventId' => (string) Uuid::v7(),
                'sequence' => $sequence,
                'sessionId' => $sessionId,
                'sessionEpoch' => $sequence,
                'provider' => 'hatfield',
                'capabilities' => [],
            ], \JSON_THROW_ON_ERROR)."\n";
            if (\strlen($frame) > self::MAX_FRAME_BYTES) {
                throw new \LengthException('Status request exceeds the protocol frame limit.');
            }
            $deadline = new TimeoutCancellation(0.25);
            $socket = connect('unix://'.$this->endpoint, cancellation: $deadline);
            // Amp socket writes have no cancellation argument; closing the socket
            // on the same absolute deadline also bounds partial writes.
            $subscription = $deadline->subscribe(static fn () => $socket->close());
            try {
                $socket->write($frame);
                $reply = '';
                while (!str_contains($reply, "\n") && \strlen($reply) < self::MAX_FRAME_BYTES) {
                    $chunk = $socket->read($deadline, self::MAX_FRAME_BYTES - \strlen($reply));
                    if (null === $chunk) {
                        break;
                    }
                    $reply .= $chunk;
                }
                $end = strpos($reply, "\n");
                $decoded = false === $end ? null : json_decode(substr($reply, 0, $end), true, flags: \JSON_THROW_ON_ERROR);
                if (!\is_array($decoded) || true !== ($decoded['ok'] ?? null)
                    || !\in_array($decoded['status'] ?? null, ['accepted', 'duplicate', 'stale'], true)) {
                    throw new \UnexpectedValueException('Status registration was not acknowledged.');
                }
            } finally {
                $deadline->unsubscribe($subscription);
                $socket->close();
            }
        } catch (\Throwable $exception) {
            $this->logger?->warning('hatfield_360.session_registration_failed', [
                'component' => 'hatfield_360', 'event_type' => 'session_registration_failed',
                'run_id' => $sessionId, 'session_id' => $sessionId, 'exception_class' => $exception::class,
            ]);
        }
    }
}
