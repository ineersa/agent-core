<?php

declare(strict_types=1);

/**
 * Minimal code-mode script bootstrap.
 *
 * Loads only this file and the user script. No application autoloader.
 * Communicates with the Hatfield host over a Unix socket using length-prefixed JSON.
 *
 * Side effects run only when this file is the CLI entrypoint. Accidental includes
 * (for example packaging/DI class scans) must stay inert.
 *
 * Stored as `.php.inc` so Symfony DI class discovery (`*.php`) never loads it.
 * The host materializes a real filesystem copy before spawning PHP CLI.
 */

if (!\defined('HATFIELD_CODE_MODE_MAX_FRAME_BYTES')) {
    \define('HATFIELD_CODE_MODE_MAX_FRAME_BYTES', 8_388_608);
}

if (!\function_exists('hatfield_code_mode_write')) {
    /**
     * @param resource             $stream
     * @param array<string, mixed> $payload
     */
    function hatfield_code_mode_write(mixed $stream, array $payload): void
    {
        $json = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
        $length = \strlen($json);
        if ($length > HATFIELD_CODE_MODE_MAX_FRAME_BYTES) {
            throw new RuntimeException(\sprintf('Code-mode IPC frame exceeds %d bytes.', HATFIELD_CODE_MODE_MAX_FRAME_BYTES));
        }

        $packet = pack('N', $length).$json;
        $offset = 0;
        $total = \strlen($packet);
        while ($offset < $total) {
            $written = fwrite($stream, substr($packet, $offset));
            if (false === $written || 0 === $written) {
                throw new RuntimeException('Failed to write code-mode IPC frame.');
            }
            $offset += $written;
        }
    }
}

if (!\function_exists('hatfield_code_mode_read')) {
    /**
     * @param resource $stream
     *
     * @return array<string, mixed>
     */
    function hatfield_code_mode_read(mixed $stream): array
    {
        $header = '';
        while (\strlen($header) < 4) {
            $chunk = fread($stream, 4 - \strlen($header));
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
            throw new RuntimeException(\sprintf('Invalid code-mode IPC frame length: %d.', $length));
        }

        $body = '';
        while (\strlen($body) < $length) {
            $chunk = fread($stream, $length - \strlen($body));
            if (false === $chunk || '' === $chunk) {
                throw new RuntimeException('Unexpected EOF while reading code-mode IPC body.');
            }
            $body .= $chunk;
        }

        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new RuntimeException('Code-mode IPC frame must decode to a JSON object.');
        }

        return $decoded;
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
        /** @var resource|null $connection */
        global $connection;

        if (!\is_resource($connection)) {
            throw new RuntimeException('Code-mode host connection is not available.');
        }

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

        $message = \is_string($response['error'] ?? null) ? $response['error'] : 'Tool call failed.';
        throw new RuntimeException($message);
    }
}

if (!function_exists('hatfield_code_mode_register_toon_autoload')) {
    function hatfield_code_mode_register_toon_autoload(string $root): void
    {
        $root = rtrim($root, '/');
        spl_autoload_register(static function (string $class) use ($root): void {
            $prefix = 'HelgeSverre\\Toon\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
            $path = $root.'/'.$relative.'.php.inc';
            if (is_file($path)) {
                require_once $path;
            }
        });
    }
}

if (!function_exists('toon_encode')) {
    /**
     * Encode a PHP value to TOON text.
     */
    function toon_encode(mixed $value): string
    {
        return \HelgeSverre\Toon\Toon::encode($value);
    }
}

if (!function_exists('toon_decode')) {
    /**
     * Decode TOON text to a PHP value.
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

$socketPath = getenv('HATFIELD_CODE_MODE_SOCKET');
$scriptPath = getenv('HATFIELD_CODE_MODE_SCRIPT');

if (!\is_string($socketPath) || '' === $socketPath) {
    fwrite(\STDERR, "HATFIELD_CODE_MODE_SOCKET is required.\n");
    exit(2);
}

if (!\is_string($scriptPath) || '' === $scriptPath) {
    fwrite(\STDERR, "HATFIELD_CODE_MODE_SCRIPT is required.\n");
    exit(2);
}

$toonRoot = getenv('HATFIELD_CODE_MODE_TOON_ROOT');
if (!\is_string($toonRoot) || '' === $toonRoot || !is_dir($toonRoot)) {
    fwrite(\STDERR, "HATFIELD_CODE_MODE_TOON_ROOT is required.\n");
    exit(2);
}
hatfield_code_mode_register_toon_autoload($toonRoot);

$connection = @stream_socket_client('unix://'.$socketPath, $errno, $errstr, 5.0);
if (false === $connection) {
    fwrite(\STDERR, \sprintf("Failed to connect to code-mode host: [%d] %s\n", $errno, $errstr));
    exit(2);
}

stream_set_blocking($connection, true);

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
