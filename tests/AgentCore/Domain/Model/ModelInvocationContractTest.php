<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Model;

use Ineersa\AgentCore\Domain\Model\ProviderRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModelInvocationContractTest extends TestCase
{
    /* ─── ProviderRequest::applyOn() ─── */

    #[DataProvider('applyOnProvider')]
    public function testApplyOnMergeOverride(
        ?string $model,
        ?array $input,
        ?array $options,
        string $defaultModel,
        array $defaultInput,
        array $defaultOptions,
        string $expectedModel,
        array $expectedInput,
        array $expectedOptions,
    ): void {
        $request = new ProviderRequest(model: $model, input: $input, options: $options);

        $result = $request->applyOn($defaultModel, $defaultInput, $defaultOptions);

        $this->assertSame($expectedModel, $result['model']);
        $this->assertSame($expectedInput, $result['input']);
        $this->assertSame($expectedOptions, $result['options']);
    }

    /**
     * @return array<string, array{0: ?string, 1: ?array, 2: ?array, 3: string, 4: array, 5: array, 6: string, 7: array, 8: array}>
     */
    public static function applyOnProvider(): array
    {
        return [
            'all_null_uses_defaults' => [
                null, null, null,
                'default-model', ['msg' => 'hello'], ['temperature' => 0.5],
                'default-model', ['msg' => 'hello'], ['temperature' => 0.5],
            ],
            'model_only_override' => [
                'override-model', null, null,
                'default-model', ['msg' => 'hello'], ['temperature' => 0.5],
                'override-model', ['msg' => 'hello'], ['temperature' => 0.5],
            ],
            'input_only_override' => [
                null, ['msg' => 'overridden'], null,
                'default-model', ['msg' => 'hello'], ['temperature' => 0.5],
                'default-model', ['msg' => 'overridden'], ['temperature' => 0.5],
            ],
            'options_only_override' => [
                null, null, ['max_tokens' => 2000],
                'default-model', ['msg' => 'hello'], ['temperature' => 0.5],
                'default-model', ['msg' => 'hello'], ['max_tokens' => 2000],
            ],
            'full_override' => [
                'final-model', ['msg' => 'final'], ['stop' => ['!']],
                'default-model', ['msg' => 'hello'], ['temperature' => 0.5],
                'final-model', ['msg' => 'final'], ['stop' => ['!']],
            ],
        ];
    }
}
