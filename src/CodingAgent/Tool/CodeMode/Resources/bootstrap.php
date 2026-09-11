<?php

declare(strict_types=1);

/**
 * Minimal code-mode script bootstrap.
 *
 * Loads only this file and the user script. No application autoloader.
 * Communicates with the Hatfield host over a Unix socket using length-prefixed JSON.
 */
const HATFIELD_CODE_MODE_MAX_FRAME_BYTES = 8_388_608;

/**
 * @param resource             $stream
 * @param array<string, mixed> $payload
 */
function hatfield_code_mode_write(mixed $stream, array $payload): void
{
    $json = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
    $length = strlen($json);
    if ($length > HATFIELD_CODE_MODE_MAX_FRAME_BYTES) {
        throw new RuntimeException(sprintf('Code-mode IPC frame exceeds %d bytes.', HATFIELD_CODE_MODE_MAX_FRAME_BYTES));
    }

    $packet = pack('N', $length).$json;
    $offset = 0;
    $total = strlen($packet);
    while ($offset < $total) {
        $written = fwrite($stream, substr($packet, $offset));
        if (false === $written || 0 === $written) {
            throw new RuntimeException('Failed to write code-mode IPC frame.');
        }
        $offset += $written;
    }
}

/**
 * @param resource $stream
 *
 * @return array<string, mixed>
 */
function hatfield_code_mode_read(mixed $stream): array
{
    $header = '';
    while (strlen($header) < 4) {
        $chunk = fread($stream, 4 - strlen($header));
        if (false === $chunk || '' === $chunk) {
            throw new RuntimeException('Unexpected EOF while reading code-mode IPC header.');
        }
        $header .= $chunk;
    }

    $unpacked = unpack('Nlength', $header);
    if (false === $unpacked) {
        throw new RuntimeException('Failed to decode code-mode IPC frame length.');
    }

    $length = (int) $unpacked['length'];
    if ($length < 1 || $length > HATFIELD_CODE_MODE_MAX_FRAME_BYTES) {
        throw new RuntimeException(sprintf('Invalid code-mode IPC frame length: %d.', $length));
    }

    $body = '';
    while (strlen($body) < $length) {
        $chunk = fread($stream, $length - strlen($body));
        if (false === $chunk || '' === $chunk) {
            throw new RuntimeException('Unexpected EOF while reading code-mode IPC body.');
        }
        $body .= $chunk;
    }

    $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Code-mode IPC frame must decode to a JSON object.');
    }

    return $decoded;
}

$socketPath = getenv('HATFIELD_CODE_MODE_SOCKET');
$scriptPath = getenv('HATFIELD_CODE_MODE_SCRIPT');

if (!is_string($socketPath) || '' === $socketPath) {
    fwrite(\STDERR, "HATFIELD_CODE_MODE_SOCKET is required.\n");
    exit(2);
}

if (!is_string($scriptPath) || '' === $scriptPath) {
    fwrite(\STDERR, "HATFIELD_CODE_MODE_SCRIPT is required.\n");
    exit(2);
}

$connection = @stream_socket_client('unix://'.$socketPath, $errno, $errstr, 5.0);
if (false === $connection) {
    fwrite(\STDERR, sprintf("Failed to connect to code-mode host: [%d] %s\n", $errno, $errstr));
    exit(2);
}

stream_set_blocking($connection, true);

/**
 * Invoke an existing Hatfield tool through the host bridge.
 *
 * @param array<string, mixed> $arguments
 */
function tool(string $name, array $arguments = []): mixed
{
    global $connection;

    if ('' === trim($name)) {
        throw new InvalidArgumentException('Tool name must be a non-empty string.');
    }

    $id = bin2hex(random_bytes(8));
    hatfield_code_mode_write($connection, [
        'type' => 'tool',
        'id' => $id,
        'name' => $name,
        'arguments' => $arguments,
    ]);

    $response = hatfield_code_mode_read($connection);
    if (($response['id'] ?? null) !== $id) {
        throw new RuntimeException('Code-mode host returned a mismatched response id.');
    }

    if (($response['ok'] ?? false) === true) {
        return $response['result'] ?? null;
    }

    $message = is_string($response['error'] ?? null) ? $response['error'] : 'Tool call failed.';
    throw new RuntimeException($message);
}

try {
    $result = (static function () use ($scriptPath): mixed {
        return include $scriptPath;
    })();

    hatfield_code_mode_write($connection, [
        'type' => 'return',
        'ok' => true,
        'result' => $result,
    ]);
    exit(0);
} catch (Throwable $exception) {
    try {
        hatfield_code_mode_write($connection, [
            'type' => 'return',
            'ok' => false,
            'error' => $exception->getMessage(),
        ]);
    } catch (Throwable) {
        fwrite(\STDERR, $exception->getMessage()."\n");
    }
    exit(1);
}
