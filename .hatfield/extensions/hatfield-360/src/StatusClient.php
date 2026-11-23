<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Hatfield360;

use Amp\ByteStream\StreamException;
use Amp\CancelledException;
use Amp\Socket\DnsSocketConnector;
use Amp\TimeoutCancellation;
use Ineersa\HatfieldExt\Hatfield360\DTO\SessionCheckpointDTO;
use Symfony\Component\Uid\Uuid;

final readonly class StatusClient
{
    public const int MAX_FRAME_BYTES = 16_384;

    private function __construct(
        private string $endpoint,
        private string $launchId,
        #[\SensitiveParameter] private string $token,
    ) {
    }

    public static function fromEnvironment(): ?self
    {
        $endpoint = self::endpoint();
        $launchId = getenv('H360_STATUS_LAUNCH_ID');
        $token = getenv('H360_STATUS_TOKEN');
        if (null === $endpoint || !self::uuid($launchId) || !self::token($token)) {
            return null;
        }

        return new self($endpoint, $launchId, $token);
    }

    /** Retry a transient response once with exactly the same owner identity. */
    public static function registerManual(string $provider, string $instanceId, int $pid, string $pidNamespace, string $startTicks): ?self
    {
        $endpoint = self::endpoint();
        $bootstrap = getenv('H360_STATUS_BOOTSTRAP_TOKEN');
        if (false !== getenv('H360_STATUS_LAUNCH_ID') || false !== getenv('H360_STATUS_TOKEN')
            || null === $endpoint || !self::token($bootstrap) || !\in_array($provider, ['hatfield', 'claude'], true)
            || !self::uuid($instanceId) || $pid < 1 || $pid > 0x7FFF_FFFF
            || 1 !== preg_match('/\A[1-9][0-9]{0,19}\z/', $pidNamespace)
            || 1 !== preg_match('/\A[1-9][0-9]{0,19}\z/', $startTicks)) {
            return null;
        }
        $request = [
            'version' => 1, 'kind' => 'launch.register', 'bootstrapToken' => $bootstrap,
            'instanceId' => $instanceId, 'provider' => $provider,
            'owner' => ['pid' => $pid, 'pidNamespace' => $pidNamespace, 'startTicks' => $startTicks],
        ];
        $reply = self::request($endpoint, $request);
        if (null === $reply || \in_array($reply['error'] ?? null, ['unavailable', 'limit'], true)) {
            $reply = self::request($endpoint, $request);
        }
        $launchId = $reply['launchId'] ?? null;
        $token = $reply['token'] ?? null;
        if (true !== ($reply['ok'] ?? null) || !self::uuid($launchId) || !self::token($token)) {
            return null;
        }

        return new self($endpoint, $launchId, $token);
    }

    /** @param array<array-key, mixed> $fields */
    public function send(string $kind, string $sessionId, int $sequence, int $sessionEpoch, array $fields = [], bool $requireApplied = false): bool
    {
        $reply = $this->sendEvent($kind, $sessionId, $sequence, $sessionEpoch, (string) Uuid::v7(), $fields);

        return \in_array($reply, ['accepted', 'duplicate'], true) || (!$requireApplied && 'stale' === $reply);
    }

    public function ensureSession(SessionCheckpointDTO $checkpoint): bool
    {
        $reply = $this->sendEvent('session.started', $checkpoint->sessionId, $checkpoint->epoch, $checkpoint->epoch, $checkpoint->eventId, [
            'provider' => $checkpoint->provider, 'capabilities' => $checkpoint->capabilities,
        ]);

        // Stale says only that the launch high-water mark has advanced, not
        // that this registration (or even this session) was ever accepted.
        return \in_array($reply, ['accepted', 'duplicate'], true);
    }

    /** @param array<array-key, mixed> $fields */
    private function sendEvent(string $kind, string $sessionId, int $sequence, int $sessionEpoch, string $eventId, array $fields): ?string
    {
        if (!EventSchema::id($eventId) || !EventSchema::accepts($kind, $sessionId, $sequence, $sessionEpoch, $fields)) {
            return null;
        }

        $reply = self::request($this->endpoint, [
            'version' => 1,
            'kind' => $kind,
            'launchId' => $this->launchId,
            'token' => $this->token,
            'eventId' => $eventId,
            'sessionId' => $sessionId,
            'sequence' => $sequence,
            'sessionEpoch' => $sessionEpoch,
        ] + $fields);

        return true === ($reply['ok'] ?? null) && \is_string($reply['status'] ?? null) ? $reply['status'] : null;
    }

    private static function endpoint(): ?string
    {
        $endpoint = getenv('H360_STATUS_ENDPOINT');

        return \is_string($endpoint) && str_starts_with($endpoint, '/') && !str_contains($endpoint, "\0") && \strlen($endpoint) <= 103
            ? $endpoint : null;
    }

    /** @phpstan-assert-if-true =string $value */
    private static function uuid(mixed $value): bool
    {
        return \is_string($value) && 1 === preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/', $value);
    }

    /** @phpstan-assert-if-true string $value */
    private static function token(#[\SensitiveParameter] mixed $value): bool
    {
        return \is_string($value) && 1 === preg_match('/\A[0-9a-f]{64}\z/', $value);
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>|null
     */
    private static function request(string $endpoint, #[\SensitiveParameter] array $payload): ?array
    {
        try {
            $frame = json_encode($payload, \JSON_THROW_ON_ERROR)."\n";
            if (\strlen($frame) > self::MAX_FRAME_BYTES) {
                return null;
            }

            $deadline = new TimeoutCancellation(0.25);
            // The default Amp connector backs off without observing cancellation.
            // One connection attempt keeps the launch reporter's deadline bounded.
            $socket = (new DnsSocketConnector())->connect('unix://'.$endpoint, cancellation: $deadline);
            // Close on the same absolute deadline to bound uncancellable socket writes.
            $subscription = $deadline->subscribe(static fn () => $socket->close());
            try {
                $socket->write($frame);
                $reply = '';
                while (\strlen($reply) < self::MAX_FRAME_BYTES) {
                    $chunk = $socket->read($deadline, self::MAX_FRAME_BYTES - \strlen($reply));
                    if (null === $chunk) {
                        return null;
                    }
                    $reply .= $chunk;
                    $end = strpos($reply, "\n");
                    if (false !== $end) {
                        $decoded = json_decode(substr($reply, 0, $end), true, flags: \JSON_THROW_ON_ERROR);

                        return \is_array($decoded) ? $decoded : null;
                    }
                }
            } finally {
                $deadline->unsubscribe($subscription);
                $socket->close();
            }
        } catch (CancelledException|StreamException|\JsonException) {
            // Deliberate fail-open boundary; the caller receives a refusal without raw wire data.
            return null;
        }

        return null;
    }
}
