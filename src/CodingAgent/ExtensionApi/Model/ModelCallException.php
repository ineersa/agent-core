<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Model;

/**
 * Stable public error contract for ExtensionApiInterface::callModel().
 *
 * Messages are sanitized. Provider exception text, credentials, prompts, and
 * raw response bodies must not be forwarded.
 */
final class ModelCallException extends \RuntimeException
{
    public const string CODE_INVALID_MODEL = 'invalid_model';
    public const string CODE_UNKNOWN_MODEL = 'unknown_model';
    public const string CODE_INVALID_INPUT = 'invalid_input';
    public const string CODE_UNSUPPORTED = 'unsupported';
    public const string CODE_PROVIDER_FAILED = 'provider_failed';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?string $model = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function invalidModel(string $model): self
    {
        return new self(
            self::CODE_INVALID_MODEL,
            'Model reference must be an exact configured provider/model string.',
            $model,
        );
    }

    public static function unknownModel(string $model): self
    {
        return new self(
            self::CODE_UNKNOWN_MODEL,
            \sprintf('Configured model "%s" is not available.', $model),
            $model,
        );
    }

    public static function invalidInput(string $safeMessage): self
    {
        return new self(self::CODE_INVALID_INPUT, $safeMessage);
    }

    public static function unsupported(string $model, string $safeMessage): self
    {
        return new self(self::CODE_UNSUPPORTED, $safeMessage, $model);
    }

    public static function providerFailed(string $model, string $category = 'provider_error'): self
    {
        return new self(
            self::CODE_PROVIDER_FAILED,
            \sprintf('Model call failed for "%s" (%s).', $model, $category),
            $model,
        );
    }
}
