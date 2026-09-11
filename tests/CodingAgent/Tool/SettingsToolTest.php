<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use HelgeSverre\Toon\Toon;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\SettingsValueResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\Tool\Support\NativeToolSchemaProbe;
use Ineersa\CodingAgent\Tests\Tool\Support\ToolValidationHarness;
use Ineersa\CodingAgent\Tool\Arguments\SettingsArgumentsDTO;
use Ineersa\CodingAgent\Tool\SettingsTool;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Yaml\Yaml;

/**
 * Thesis: settings is a typed DTO tool with an explicit flat provider schema,
 * Serializer presence for omitted-vs-null value, and Symfony validation for
 * input faults.
 */
final class SettingsToolTest extends TestCase
{
    private string $homeDir;
    private string $projectDir;
    private SettingsTool $tool;

    protected function setUp(): void
    {
        $root = TestDirectoryIsolation::createProjectTempDir('settings-tool');
        $this->homeDir = $root.'/home';
        $this->projectDir = $root.'/project';
        TestDirectoryIsolation::ensureDirectory($this->homeDir);
        TestDirectoryIsolation::createHatfieldTree($this->projectDir);

        $appRoot = \dirname(__DIR__, 3);
        $pathResolver = new SettingsPathResolver($appRoot, $this->homeDir);
        $loader = new AppConfigLoader($pathResolver);
        $resources = new AppResourceLocator($appRoot);
        $active = new AppConfig(
            tui: new TuiConfig('cyberpunk'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir,
        );
        $accessor = PropertyAccess::createPropertyAccessorBuilder()
            ->enableExceptionOnInvalidIndex()
            ->getPropertyAccessor();
        $valueResolver = new SettingsValueResolver($accessor);
        $writer = new SettingsOverrideWriter($pathResolver, $accessor, new Filesystem());

        $this->tool = new SettingsTool(
            new ToolRuntime(new StackToolExecutionContextAccessor()),
            $loader,
            $resources,
            $active,
            $valueResolver,
            $writer,
        );
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory(\dirname($this->homeDir));
    }

    public function testDefinitionEmitsExactHistoricalFlatSchemaWithoutRawFlag(): void
    {
        $def = $this->tool->definition();
        $this->assertSame('settings', $def->name);
        $this->assertSame(ToolExecutionMode::Sequential, $def->executionMode);
        $this->assertNotNull($def->parametersJsonSchema);

        $expected = [
            'type' => 'object',
            'properties' => [
                'operation' => [
                    'type' => 'string',
                    'enum' => ['read', 'set', 'remove'],
                    'description' => 'Exactly one operation per call.',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Dotted settings path (e.g. tui.theme).',
                ],
                'scope' => [
                    'type' => 'string',
                    'enum' => ['effective', 'user', 'project'],
                    'description' => 'read: defaults to effective. set/remove: required user or project only.',
                ],
                'value' => [
                    'description' => 'Native JSON value for set (explicit null allowed). Required for set.',
                    'type' => ['string', 'number', 'boolean', 'object', 'array', 'null'],
                ],
            ],
            'required' => ['operation', 'path'],
            'additionalProperties' => false,
        ];
        $this->assertSame($expected, $def->parametersJsonSchema);

        $schema = NativeToolSchemaProbe::for($this->tool);
        $this->assertSame($expected, $schema);

        $reflection = new \ReflectionMethod($this->tool, '__invoke');
        $param = $reflection->getParameters()[0]->getType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $param);
        $this->assertFalse($param->isBuiltin());
        $this->assertSame(SettingsArgumentsDTO::class, $param->getName());
    }

    public function testRegistryInvocationAcceptsObjectNullAndOmissionSemantics(): void
    {
        $toolbox = ToolValidationHarness::toolbox($this->tool);

        $setObject = $toolbox->execute(new ToolCall('c1', 'settings', [
            'operation' => 'set',
            'path' => 'tui.theme',
            'scope' => 'project',
            'value' => ['nested' => true],
        ]));
        $decodedObject = Toon::decode((string) $setObject->getResult());
        $this->assertIsArray($decodedObject);
        $this->assertSame(['nested' => true], $decodedObject['value']);

        $setNull = $toolbox->execute(new ToolCall('c2', 'settings', [
            'operation' => 'set',
            'path' => 'logging.level',
            'scope' => 'user',
            'value' => null,
        ]));
        $decodedNull = Toon::decode((string) $setNull->getResult());
        $this->assertIsArray($decodedNull);
        $this->assertNull($decodedNull['value']);

        $omitted = $toolbox->execute(new ToolCall('c3', 'settings', [
            'operation' => 'set',
            'path' => 'tui.theme',
            'scope' => 'project',
        ]));
        $this->assertStringContainsString('The "value" argument is required for set', (string) $omitted->getResult());
        $unchanged = $this->invoke($this->dto(['operation' => 'read', 'path' => 'tui.theme']));
        $this->assertSame(['nested' => true], $unchanged['value']);

        $nulString = $this->dto([
            'operation' => 'set',
            'path' => 'tui.theme',
            'scope' => 'project',
            'value' => "\0OMITTED",
        ]);
        $this->assertTrue($nulString->hasValue());
        $this->assertSame("\0OMITTED", $nulString->value);
    }

    public function testEffectiveAndExplicitLayerReads(): void
    {
        $this->writeProject(['tui' => ['theme' => 'nord']]);

        $effective = $this->invoke($this->dto(['operation' => 'read', 'path' => 'tui.theme']));
        $this->assertTrue($effective['exists']);
        $this->assertSame('nord', $effective['value']);
        $this->assertSame('project', $effective['source']);

        $user = $this->invoke($this->dto(['operation' => 'read', 'path' => 'tui.theme', 'scope' => 'user']));
        $this->assertFalse($user['exists']);

        $project = $this->invoke($this->dto(['operation' => 'read', 'path' => 'tui.theme', 'scope' => 'project']));
        $this->assertTrue($project['exists']);
        $this->assertSame('nord', $project['value']);
        $this->assertSame('project', $project['source']);
    }

    public function testRemoveResumesInheritanceAndMissingIsNoOp(): void
    {
        $this->writeProject(['tui' => ['theme' => 'nord']]);
        $removed = $this->invoke($this->dto([
            'operation' => 'remove',
            'path' => 'tui.theme',
            'scope' => 'project',
        ]));
        $this->assertTrue($removed['changed']);
        $this->assertTrue($removed['restart_required']);
        $this->assertSame('defaults', $removed['source']);

        $missing = $this->invoke($this->dto([
            'operation' => 'remove',
            'path' => 'tui.theme',
            'scope' => 'project',
        ]));
        $this->assertFalse($missing['changed']);
        $this->assertArrayNotHasKey('restart_required', $missing);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidCallCases(): iterable
    {
        yield 'malformed path' => [['operation' => 'read', 'path' => 'tui.the]me'], 'must be a non-empty dotted settings path'];
        yield 'missing mutation scope' => [['operation' => 'set', 'path' => 'tui.theme', 'value' => 'x'], 'require explicit scope'];
        yield 'effective mutation scope' => [['operation' => 'set', 'path' => 'tui.theme', 'scope' => 'effective', 'value' => 'x'], 'user or project'];
        yield 'missing value' => [['operation' => 'set', 'path' => 'tui.theme', 'scope' => 'project'], 'The "value" argument is required for set'];
        yield 'invalid read scope' => [['operation' => 'read', 'path' => 'tui.theme', 'scope' => 'nope'], 'The "scope" argument for read must be one of'];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('invalidCallCases')]
    public function testValidationRejectsOperationSpecificInvalidFields(array $arguments, string $messageFragment): void
    {
        $result = ToolValidationHarness::toolbox($this->tool)->execute(new ToolCall('call-settings', 'settings', $arguments));
        $message = (string) $result->getResult();
        $this->assertStringContainsString($messageFragment, $message);
        if ('missing mutation scope' === $this->dataName() || 'effective mutation scope' === $this->dataName()) {
            $this->assertStringNotContainsString('scope" argument for read', $message);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dto(array $payload): SettingsArgumentsDTO
    {
        $extractor = new PropertyInfoExtractor([], [new PhpDocExtractor(), new ReflectionExtractor()]);
        $serializer = new Serializer([
            new ObjectNormalizer(null, new CamelCaseToSnakeCaseNameConverter(), null, $extractor),
            new ArrayDenormalizer(),
        ]);

        /** @var SettingsArgumentsDTO $dto */
        $dto = $serializer->denormalize($payload, SettingsArgumentsDTO::class);

        return $dto;
    }

    private function invoke(SettingsArgumentsDTO $arguments): array
    {
        $decoded = Toon::decode(($this->tool)($arguments));
        $this->assertIsArray($decoded);

        /* @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeProject(array $data): void
    {
        file_put_contents($this->projectDir.'/.hatfield/settings.yaml', Yaml::dump($data, 4, 4));
    }
}
