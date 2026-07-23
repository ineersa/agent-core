<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\ProviderQuota;

/**
 * Shared numeric/duration formatting helpers for provider quota probes.
 *
 * Kept free of HTTP/auth concerns so OpenAI and z.ai collaborators can share
 * countdown and percent math without duplicating edge-case handling.
 */
final class ProviderQuotaProbeFormatting
{
    public function clampPercent(float $value): float
    {
        return max(0.0, min(100.0, $value));
    }

    public function parseFiniteNumber(mixed $value): ?float
    {
        if (\is_int($value) || \is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }
        if (\is_string($value) && is_numeric($value)) {
            $parsed = (float) $value;

            return is_finite($parsed) ? $parsed : null;
        }

        return null;
    }

    public function appendNote(?string $existing, string $addition): string
    {
        if (null === $existing || '' === $existing) {
            return $addition;
        }

        return $existing.' '.$addition;
    }

    public function windowLabelFromSeconds(int $seconds): string
    {
        if (0 === $seconds % 3600) {
            $hours = intdiv($seconds, 3600);
            if (0 === $hours % 24) {
                $days = intdiv($hours, 24);

                return $days.'d';
            }

            return $hours.'h';
        }
        if (0 === $seconds % 60) {
            return intdiv($seconds, 60).'m';
        }

        return $seconds.'s';
    }

    public function countdownFromSeconds(mixed $seconds): ?string
    {
        $parsed = $this->parseFiniteNumber($seconds);
        if (null === $parsed) {
            return null;
        }
        if ($parsed <= 0) {
            return 'now';
        }

        return 'in '.$this->fmtDurationMs($parsed * 1000.0);
    }

    public function fmtDurationMs(float $ms): string
    {
        $seconds = (int) floor(max(0.0, $ms) / 1000.0);
        if ($seconds < 60) {
            return $seconds.'s';
        }
        $minutes = intdiv($seconds, 60);
        $remainingSeconds = $seconds % 60;
        if ($minutes < 60) {
            return $minutes.'m'.($remainingSeconds > 0 ? $remainingSeconds.'s' : '');
        }
        $days = intdiv($minutes, 24 * 60);
        $minutesAfterDays = $minutes - ($days * 24 * 60);
        $hours = intdiv($minutesAfterDays, 60);
        $remainingMinutes = $minutesAfterDays % 60;
        if ($days > 0) {
            return $days.'d'
                .($hours > 0 ? $hours.'h' : '')
                .($remainingMinutes > 0 ? $remainingMinutes.'m' : '');
        }

        return $hours.'h'.($remainingMinutes > 0 ? $remainingMinutes.'m' : '');
    }

    /**
     * Resolve plain or `env:VAR` API keys the same way the provider factory does.
     */
    public function resolveApiKey(?string $apiKey): ?string
    {
        if (null === $apiKey) {
            return null;
        }
        if (str_starts_with($apiKey, 'env:')) {
            $var = substr($apiKey, 4);
            if ('' === $var) {
                return null;
            }
            $value = getenv($var);

            return false !== $value && '' !== $value ? $value : null;
        }

        return $apiKey;
    }

    public function transportErrorMessage(string $providerLabel, \Throwable $e): string
    {
        $message = strtolower($e->getMessage());
        if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
            return $providerLabel.' usage probe timed out.';
        }

        // Keep the message short and free of response bodies / credentials.
        return $providerLabel.' usage probe failed.';
    }
}
