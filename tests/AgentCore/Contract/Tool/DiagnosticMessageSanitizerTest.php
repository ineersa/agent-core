<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Contract\Tool;

use Ineersa\AgentCore\Contract\Tool\DiagnosticMessageSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DiagnosticMessageSanitizerTest extends TestCase
{
    #[DataProvider('messages')]
    public function testSanitizeBoundsAndRedactsSecrets(string $input, array $mustContain, array $mustNotContain): void
    {
        $sanitized = DiagnosticMessageSanitizer::sanitize($input);

        foreach ($mustContain as $required) {
            $this->assertStringContainsString($required, $sanitized);
        }
        foreach ($mustNotContain as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $sanitized);
        }
    }

    public static function messages(): iterable
    {
        yield 'credentials in diagnostic formats' => [
            'password=hidden token: private https://user:pass@example.test ghp_examplecredential',
            ['<redacted>', 'example.test'],
            ['hidden', 'private', 'user:pass', 'ghp_examplecredential'],
        ];
        yield 'plain actionable cause' => [
            'Selected extension "MissingExt" is not in extensions.enabled',
            ['Selected extension "MissingExt" is not in extensions.enabled'],
            [],
        ];
        yield 'bearer token redacted' => [
            'HTTP 401: Bearer sk-abc123xyz-secret-token not authorized',
            ['bearer <redacted>'],
            ['sk-abc123xyz-secret-token'],
        ];
        yield 'truncates long unicode-safe text' => [
            str_repeat("\u{2500}", 600),
            ['...'],
            [],
        ];
    }
}
