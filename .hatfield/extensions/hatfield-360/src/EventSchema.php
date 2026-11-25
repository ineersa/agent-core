<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Hatfield360;

/** Strict mirror of the Hatfield 360 v1 event schema, excluding generated credentials and event ID. */
final class EventSchema
{
    private const array EVENTS = [
        'session.snapshot' => ['provider' => ['hatfield'], 'turn' => 'turn', 'attention' => 'attention', 'activeSubagents' => 'count', 'backgroundScripts' => 'count', 'tool' => 'tool'],
        'session.ended' => [],
        'notification' => ['title' => 200, 'body' => 2000],
    ];

    /** @param array<array-key, mixed> $fields */
    public static function accepts(string $kind, string $sessionId, int $sequence, int $sessionEpoch, array $fields): bool
    {
        $required = self::EVENTS[$kind] ?? null;
        if (null === $required || !self::id($sessionId) || !self::sequence($sequence)
            || !self::sequence($sessionEpoch) || $sessionEpoch > $sequence) {
            return false;
        }
        $allowed = $required + ['at' => 64];
        if ('session.snapshot' === $kind) {
            $allowed['title'] = 'session-title';
            $allowed['schedules'] = 'schedules';
        }
        if ([] !== array_diff_key($required, $fields) || [] !== array_diff_key($fields, $allowed)) {
            return false;
        }
        foreach ($allowed as $key => $rule) {
            if (\array_key_exists($key, $fields) && !self::matches($fields[$key], $rule)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<array-key, mixed> $fields */
    public static function acceptsNotification(array $fields): bool
    {
        return 2 === \count($fields) && self::matches($fields['title'] ?? null, self::EVENTS['notification']['title'])
            && self::matches($fields['body'] ?? null, self::EVENTS['notification']['body'])
            && '' !== $fields['title'] && '' !== $fields['body'];
    }

    public static function id(mixed $value): bool
    {
        return \is_string($value) && 1 === preg_match('/\A[a-zA-Z0-9_.:-]{1,128}\z/', $value);
    }

    public static function sequence(mixed $value): bool
    {
        return \is_int($value) && $value > 0 && $value <= 9_007_199_254_740_991;
    }

    /** @param int|string|list<string> $rule */
    private static function matches(mixed $value, int|string|array $rule): bool
    {
        if (\is_array($rule)) {
            return \in_array($value, $rule, true);
        }
        if (\is_int($rule)) {
            return \is_string($value) && mb_check_encoding($value, 'UTF-8') && mb_strlen($value, 'UTF-8') <= $rule;
        }

        return match ($rule) {
            'id' => self::id($value),
            'turn' => self::turn($value),
            'schedules' => self::schedules($value),
            'attention' => self::record($value, 'reason', ['permission', 'question', 'external-input']),
            'count' => null === $value || (\is_int($value) && $value >= 0 && $value <= 1024),
            'session-title' => null === $value || self::matches($value, 1024),
            'tool' => null === $value || self::matches($value, 128),
            default => false,
        };
    }

    private static function turn(mixed $value): bool
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (\is_array($value) && \array_key_exists('trigger', $value)) {
            if (!\in_array($value['trigger'], ['user', 'schedule'], true)) {
                return false;
            }
            unset($value['trigger']);
        }

        return self::record($value, 'status', ['working', 'completed', 'failed', 'cancelled', 'unknown']);
    }

    private static function schedules(mixed $value): bool
    {
        if (null === $value) {
            return true;
        }
        if (!\is_array($value) || !array_is_list($value) || \count($value) > 16) {
            return false;
        }

        $seen = [];
        foreach ($value as $entry) {
            if ($entry instanceof \stdClass) {
                $entry = get_object_vars($entry);
            }
            if (!\is_array($entry) || 4 !== \count($entry) || !self::id($entry['id'] ?? null)
                || !self::matches($entry['label'] ?? null, 64) || !\is_bool($entry['recurring'] ?? null)
                || !\array_key_exists('nextRunAt', $entry) || isset($seen[$entry['id']])) {
                return false;
            }
            $next = $entry['nextRunAt'];
            if (null !== $next && (!\is_int($next) || $next < 0 || $next > 9_007_199_254_740_991)) {
                return false;
            }
            $seen[$entry['id']] = true;
        }

        return true;
    }

    /** @param list<string> $values */
    private static function record(mixed $value, string $field, array $values): bool
    {
        if (null === $value) {
            return true;
        }
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }

        return \is_array($value) && 2 === \count($value) && self::id($value['id'] ?? null)
            && \in_array($value[$field] ?? null, $values, true);
    }
}
