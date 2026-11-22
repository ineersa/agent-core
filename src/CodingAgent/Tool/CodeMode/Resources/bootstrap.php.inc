<?php

declare(strict_types=1);

/**
 * Minimal code-mode script bootstrap.
 *
 * Boots the configured Messenger services in the session-owned PHP process.
 *
 * Side effects run only when this file is the CLI entrypoint. Accidental includes
 * (for example packaging/DI class scans) must stay inert.
 *
 * Stored as `.php.inc` so Symfony DI class discovery (`*.php`) never loads it.
 * The host materializes a real filesystem copy before spawning PHP CLI.
 */

/** @param array<string, mixed> $arguments */
function hatfield_code_mode_broker_call(string $operation, string $name, array $arguments): mixed
{
    global $broker, $requests, $invocationId, $payloads;
    static $mutex;
    $mutex ??= new \Amp\Sync\LocalMutex();
    // The transport and reply queue are shared by all Fibers in this script.
    $lock = $mutex->acquire();
    try {
        $id = bin2hex(random_bytes(8));
        $requests->send(new \Symfony\Component\Messenger\Envelope(
            new \Ineersa\CodingAgent\Tool\CodeMode\DTO\CodeModeCallRequestDTO($invocationId, $id, $name, $arguments, $operation, $invocationId),
        ));
        while (true) {
            foreach ($broker->get() as $delivery) {
                $message = $delivery->getMessage();
                if (!$message instanceof \Ineersa\CodingAgent\Tool\CodeMode\DTO\CodeModeReplyDTO || $message->callId !== $id) {
                    $broker->ack($delivery);
                    continue;
                }
                $result = $payloads->read($message->payload);
                $broker->ack($delivery);
                if (!$result instanceof \Ineersa\AgentCore\Domain\Message\ToolCallResult || !\is_array($result->result)) {
                    throw new RuntimeException('Invalid code-mode reply payload.');
                }
                $reply = $result->result;
                if (true === ($reply['ok'] ?? false)) {
                    return $reply['result'] ?? null;
                }
                throw new RuntimeException($reply['error'] ?? 'Code-mode call failed.');
            }
            $broker->wait(1000, new \Amp\NullCancellation());
        }
    } finally {
        $lock->release();
    }
}

/** @param array<string, mixed> $frame */
function hatfield_code_mode_terminal(array $frame): void
{
    global $factory, $endpoint, $serializer, $invocationId, $runId, $payloads;
    $id = 'code-mode-terminal-'.$invocationId;
    $reference = $payloads->publish(new \Ineersa\AgentCore\Domain\Message\ToolCallResult(
        $runId, 0, $id, 0, $id, $id, 0, $frame, true !== $frame['ok'],
    ));
    $terminal = $factory->createTransport('sqlite-queue://code_mode_terminal.'.$invocationId, $endpoint, $serializer);
    try {
        $terminal->send(new \Symfony\Component\Messenger\Envelope(
            new \Ineersa\CodingAgent\Tool\CodeMode\DTO\CodeModeTerminalReportDTO($invocationId, $reference),
        ));
    } finally {
        $terminal->close();
    }
}

if (!\function_exists('notify_owner')) {
    /** @return array<string, mixed> Submission is not consumption or acknowledgement. */
    function notify_owner(string $message): array
    {
        // Refuse oversized and invalid UTF-8 payloads before dispatch.
        if (\strlen($message) > 8192 || 1 !== preg_match('//u', $message)) {
            return ['status' => 'refused', 'reason' => 'invalid_message'];
        }
        return hatfield_code_mode_broker_call('notice', 'notify_owner', ['message' => $message]);
    }
}

if (!\function_exists('tool')) {
    /**
     * Invoke an existing Hatfield tool through the host bridge.
     *
     * @param array<string, mixed> $arguments
     */
    function tool(string $name, array $arguments = []): mixed
    {
        if (\func_num_args() > 2) {
            throw new InvalidArgumentException('tool() accepts at most two arguments: tool(string $name, array $arguments = []).');
        }

        if ('' === trim($name)) {
            throw new InvalidArgumentException('Tool name must be a non-empty string.');
        }

        $unsupported = /* @unsupported-tools@ */ [];
        if (in_array($name, $unsupported, true)) {
            throw new RuntimeException(sprintf(
                'Tool "%s" is not supported inside code_mode. Child launches, agent communication, and interactive ask_human calls must run outside the script.',
                $name,
            ));
        }

        return hatfield_code_mode_broker_call('tool', $name, $arguments);
    }
}

if (!function_exists('toon_encode')) {
    /**
     * Encode a PHP value to TOON text.
     */
    function toon_encode(mixed $value): string
    {
        $value = \Ineersa\CodingAgent\Tool\CodeMode\CodeModeValueCodec::assertEncodable($value, 'toon_encode() input');

        return \HelgeSverre\Toon\Toon::encode($value);
    }
}

if (!function_exists('toon_decode')) {
    /**
     * Decode TOON text to a PHP value.
     *
     * Valid scalar text stays a scalar. Malformed structured TOON still follows
     * the HelgeSverre decoder contract.
     */
    function toon_decode(string $toon): mixed
    {
        return \HelgeSverre\Toon\Toon::decode($toon);
    }
}

$scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
$self = realpath(__FILE__);
$main = \is_string($scriptFilename) && '' !== $scriptFilename ? realpath($scriptFilename) : false;

// Both realpaths must resolve successfully. Failed realpath comparisons must not
// treat accidental includes as the CLI entrypoint (`false === false`).
if (false === $self || false === $main || $self !== $main) {
    return;
}

$scriptPath = getenv('HATFIELD_CODE_MODE_SCRIPT');

if (!\is_string($scriptPath) || '' === $scriptPath) {
    fwrite(\STDERR, "HATFIELD_CODE_MODE_SCRIPT is required.\n");
    exit(2);
}

$memoryLimit = getenv('HATFIELD_CODE_MODE_MEMORY_LIMIT');
if (is_string($memoryLimit) && '' !== $memoryLimit) {
    ini_set('memory_limit', $memoryLimit);
}

// Host starts the child with display_errors=0. Keep that so warnings go to
// stderr once without an stdout mirror. Errors still report through STDERR.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
// Empty error_log sends logged warnings/errors to STDERR for the host drain.
ini_set('error_log', '');
error_reporting(E_ALL);

require __DIR__.'/runtime/autoload.php';
$invocationId = getenv('HATFIELD_CODE_MODE_INVOCATION');
$runId = getenv('HATFIELD_CODE_MODE_RUN');
$batchDir = getenv('HATFIELD_CODE_MODE_BATCH_DIR');
$normalizer = new \Symfony\Component\Serializer\Normalizer\ObjectNormalizer(
    nameConverter: new \Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter(),
    propertyTypeExtractor: new \Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor(),
);
$codec = new \Symfony\Component\Serializer\Serializer([$normalizer], [new \Symfony\Component\Serializer\Encoder\JsonEncoder()]);
$serializer = new \Symfony\Component\Messenger\Transport\Serialization\Serializer($codec);
$factory = new \Ineersa\SqliteQueue\Messenger\TransportFactory();
$endpoint = ['endpoint' => getenv('HATFIELD_SQLITE_QUEUE_ENDPOINT')];
$requests = $factory->createTransport('sqlite-queue://code_mode_requests', $endpoint, $serializer);
$broker = $factory->createTransport('sqlite-queue://code_mode_reply.'.$invocationId, $endpoint, $serializer);
$paths = new class($runId, $batchDir) implements \Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface {
    public function __construct(private string $runId, private string $path) {}

    public function resolveToolBatchesDirectory(string $runId): string
    {
        if ($runId !== $this->runId) {
            throw new RuntimeException('Unexpected code-mode payload run.');
        }

        return $this->path;
    }

    public function withStorage(string $runId, callable $write): mixed
    {
        $this->resolveToolBatchesDirectory($runId);

        return $write();
    }
};
$payloads = new \Ineersa\CodingAgent\Session\SessionToolPayloadStore(
    $paths, $codec, \Symfony\Component\Validator\Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
    new \Symfony\Component\Filesystem\Filesystem(),
);
hatfield_code_mode_broker_call('admit', 'admit', []);

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if (null === $error) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!\in_array($error['type'], $fatalTypes, true)) {
        return;
    }

    $message = (string) ($error['message'] ?? 'Fatal error');
    if ('' !== $message) {
        fwrite(\STDERR, $message."\n");
    }
});

try {
    $result = (static function () use ($scriptPath): mixed {
        return include $scriptPath;
    })();

    $result = \Ineersa\CodingAgent\Tool\CodeMode\CodeModeValueCodec::assertEncodable($result, 'Code-mode script return value');
    hatfield_code_mode_terminal([
        'ok' => true,
        'result' => $result,
    ]);
    exit(0);
} catch (Throwable $exception) {
    try {
        $message = $exception->getMessage();
        $file = $exception->getFile();
        $line = $exception->getLine();
        if (\is_string($file) && '' !== $file && $line > 0) {
            $message .= $file === $scriptPath
                ? ' in '.\Ineersa\CodingAgent\Tool\CodeMode\CodeModeDiagnostics::scriptLocation($line)
                : \sprintf(' in %s on line %d', $file, $line);
        }
        if (!mb_check_encoding($message, 'UTF-8') || $exception->getMessage() === \Ineersa\CodingAgent\Tool\CodeMode\CodeModeValueCodec::MALFORMED_MESSAGE) {
            $message = \Ineersa\CodingAgent\Tool\CodeMode\CodeModeValueCodec::MALFORMED_MESSAGE;
        }
        hatfield_code_mode_terminal([
            'ok' => false,
            'error' => $message,
        ]);
    } catch (Throwable $deliveryException) {
        // Preserve the execution/read failure when the peer cannot receive it.
        fwrite(\STDERR, $message."\nCode-mode failed to deliver its error result: ".$deliveryException->getMessage()."\n");
    }
    exit(1);
}
