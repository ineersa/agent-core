<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

/**
 * Length-prefixed JSON framing for the code-mode host/script connection.
 *
 * Frame layout: unsigned 32-bit big-endian length + UTF-8 JSON object.
 */
final class CodeModeIpc
{
    public const int MAX_FRAME_BYTES = 8_388_608;

    /**
     * @param resource             $stream
     * @param array<string, mixed> $payload
     */
    public static function write(mixed $stream, array $payload): void
    {
        $json = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
        $length = \strlen($json);
        if ($length > self::MAX_FRAME_BYTES) {
            throw new \RuntimeException(\sprintf('Code-mode IPC frame exceeds %d bytes.', self::MAX_FRAME_BYTES));
        }

        $header = pack('N', $length);
        self::writeAll($stream, $header.$json);
    }

    /**
     * @param resource $stream
     *
     * @return array<string, mixed>|null null on clean EOF before a frame starts
     */
    public static function read(mixed $stream): ?array
    {
        $header = self::readExact($stream, 4);
        if (null === $header) {
            return null;
        }

        $unpacked = unpack('Nlength', $header);
        if (false === $unpacked) {
            throw new \RuntimeException('Failed to decode code-mode IPC frame length.');
        }

        $length = (int) $unpacked['length'];
        if ($length < 1 || $length > self::MAX_FRAME_BYTES) {
            throw new \RuntimeException(\sprintf('Invalid code-mode IPC frame length: %d.', $length));
        }

        $body = self::readExact($stream, $length);
        if (null === $body) {
            throw new \RuntimeException('Unexpected EOF while reading a code-mode IPC frame body.');
        }

        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('Code-mode IPC frame must decode to a JSON object.');
        }

        /* @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param resource $stream
     */
    private static function writeAll(mixed $stream, string $data): void
    {
        $offset = 0;
        $length = \strlen($data);
        while ($offset < $length) {
            $written = @fwrite($stream, substr($data, $offset));
            if (false === $written || 0 === $written) {
                throw new \RuntimeException('Failed to write code-mode IPC frame.');
            }
            $offset += $written;
        }
    }

    /**
     * @param resource $stream
     */
    private static function readExact(mixed $stream, int $bytes): ?string
    {
        $buffer = '';
        while (\strlen($buffer) < $bytes) {
            $chunk = @fread($stream, $bytes - \strlen($buffer));
            if (false === $chunk) {
                throw new \RuntimeException('Failed to read code-mode IPC frame.');
            }
            if ('' === $chunk) {
                if ('' === $buffer) {
                    return null;
                }

                throw new \RuntimeException('Unexpected EOF while reading a code-mode IPC frame.');
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }
}
