<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\SafeGuard;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\ApprovalSessionTracker;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Classifier\SafeGuardClassifier;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardPolicy;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardConfig;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardPolicyWriter;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardToolCallHook;
use Ineersa\Hatfield\ExtensionApi\Approval\ApprovalAnswerContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallDecisionKindEnum;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SafeGuardToolCallHook — end-to-end classification through
 * the real SafeGuardClassifier.
 */
final class SafeGuardToolCallHookTest extends TestCase
{
    private SafeGuardToolCallHook $hook;
    private string $cwd;
    private ApprovalSessionTracker $tracker;

    /** @var string|false backup of process env HATFIELD_APPROVAL_CHANNEL (false = unset) */
    private string|false $approvalChannelEnvBackup = false;

    protected function setUp(): void
    {
        $this->backupAndClearApprovalChannelEnv();

        $config = new SafeGuardConfig(autoDenyInNoninteractive: false);
        $classifier = SafeGuardClassifier::fromConfig($config);
        $policy = new SafeGuardPolicy();
        $this->cwd = getcwd() ?: '.';
        $this->tracker = new ApprovalSessionTracker();
        $this->hook = new SafeGuardToolCallHook(
            classifier: $classifier,
            policy: $policy,
            approvalTracker: $this->tracker,
            policyWriter: null,
            cwd: $this->cwd,
            autoDenyInNoninteractive: false,
        );
    }

    protected function tearDown(): void
    {
        $this->restoreApprovalChannelEnv();

        parent::tearDown();
    }

    // ── Bash tool ──

    public function testBashSafeCommandIsAllowed(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_1',
            toolName: 'bash',
            arguments: ['command' => 'ls -la'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto->kind);
    }

    public function testBashSudoIsBlocked(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_2',
            toolName: 'bash',
            arguments: ['command' => 'sudo apt update'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertSame('hard_block', $dto->details['category']);
        $this->assertTrue($dto->details['intercepted']);
        $this->assertTrue($dto->details['denied']);
    }

    public function testBashDestructiveRequiresApproval(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_3',
            toolName: 'bash',
            arguments: ['command' => 'rm -rf /tmp/build'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $this->assertSame('destructive', $dto->details['category']);
        $this->assertArrayHasKey('question_id', $dto->details);
        $this->assertArrayHasKey('schema', $dto->details);
        $this->assertArrayHasKey('prompt', $dto->details);
    }

    public function testBashDangerousGitRequiresApproval(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_4',
            toolName: 'bash',
            arguments: ['command' => 'git push --force origin main'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $this->assertSame('dangerous_git', $dto->details['category']);
    }

    public function testBashEnvExposureRequiresApproval(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_5',
            toolName: 'bash',
            arguments: ['command' => 'env'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $this->assertSame('sensitive_info', $dto->details['category']);
    }

    // ── Write tool ──

    public function testWriteInsideCwdIsAllowed(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_6',
            toolName: 'write',
            arguments: ['path' => 'src/test.php', 'content' => '<?php'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto->kind);
    }

    public function testWriteOutsideCwdRequiresApproval(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_7',
            toolName: 'write',
            arguments: ['path' => '/etc/hosts', 'content' => '127.0.0.1 localhost'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $this->assertSame('write_outside_cwd', $dto->details['category']);
    }

    // ── Edit tool ──

    public function testEditInsideCwdIsAllowed(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_8',
            toolName: 'edit',
            arguments: ['path' => 'README.md', 'oldText' => 'foo', 'newText' => 'bar'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto->kind);
    }

    public function testEditOutsideCwdRequiresApproval(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_9',
            toolName: 'edit',
            arguments: ['path' => '/etc/hostname', 'oldText' => 'old', 'newText' => 'new'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $this->assertSame('write_outside_cwd', $dto->details['category']);
    }

    // ── Read tool ──

    public function testReadSafeFileIsAllowed(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_10',
            toolName: 'read',
            arguments: ['path' => 'src/main.php'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto->kind);
    }

    public function testReadProtectedDotEnvRequiresApproval(): void
    {
        $config = new SafeGuardConfig(
            protectedReadPatterns: ['.env.local'],
            autoDenyInNoninteractive: false,
        );
        $classifier = SafeGuardClassifier::fromConfig($config);
        $policy = SafeGuardPolicy::fromConfig($config);
        $hook = new SafeGuardToolCallHook(
            classifier: $classifier,
            policy: $policy,
            approvalTracker: new ApprovalSessionTracker(),
            policyWriter: null,
            cwd: $this->cwd,
            autoDenyInNoninteractive: false,
        );

        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_11',
            toolName: 'read',
            arguments: ['path' => '.env.local'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $this->assertSame('protected_read', $dto->details['category']);
    }

    public function testReadSshKeyRequiresApproval(): void
    {
        $config = new SafeGuardConfig(
            protectedReadPatterns: ['.ssh/id_'],
            autoDenyInNoninteractive: false,
        );
        $classifier = SafeGuardClassifier::fromConfig($config);
        $policy = SafeGuardPolicy::fromConfig($config);
        $hook = new SafeGuardToolCallHook(
            classifier: $classifier,
            policy: $policy,
            approvalTracker: new ApprovalSessionTracker(),
            policyWriter: null,
            cwd: $this->cwd,
            autoDenyInNoninteractive: false,
        );

        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_12',
            toolName: 'read',
            arguments: ['path' => '/home/user/.ssh/id_rsa'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $this->assertSame('protected_read', $dto->details['category']);
    }

    // ── Unknown tools ──

    public function testUnknownToolIsAllowed(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_13',
            toolName: 'view_image',
            arguments: ['path' => '/secret/file.png'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto->kind);
    }

    // ── Allowlist support ──

    public function testAllowlistBypassesDestructiveBlock(): void
    {
        $config = new SafeGuardConfig(
            allowCommandPatterns: ['rm -rf /tmp/build'],
            autoDenyInNoninteractive: false,
        );
        $classifier = SafeGuardClassifier::fromConfig($config);
        $policy = SafeGuardPolicy::fromConfig($config);
        $hook = new SafeGuardToolCallHook(
            classifier: $classifier,
            policy: $policy,
            approvalTracker: new ApprovalSessionTracker(),
            policyWriter: null,
            cwd: $this->cwd,
            autoDenyInNoninteractive: false,
        );

        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_14',
            toolName: 'bash',
            arguments: ['command' => 'rm -rf /tmp/build/cache'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto->kind);
    }

    // ── Decision details ──

    public function testRequireApprovalDecisionIncludesAllMetadata(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_15',
            toolName: 'bash',
            arguments: ['command' => 'rm -rf /'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $this->assertArrayHasKey('category', $dto->details);
        $this->assertArrayHasKey('prompt', $dto->details);
        $this->assertArrayHasKey('schema', $dto->details);
        $this->assertArrayHasKey('question_id', $dto->details);
    }

    // ── Approval session tracking ──

    public function testApprovedOperationIsAllowedOnRetry(): void
    {
        $command = 'rm -rf /tmp/build';

        // First call: should return RequireApproval (not approved yet)
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_16',
            toolName: 'bash',
            arguments: ['command' => $command],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $this->assertSame('destructive', $dto->details['category']);

        // Simulate approval by the human
        $questionId = $dto->details['question_id'];
        $operationKey = $dto->details['operation_key'];
        $this->assertNotNull($operationKey);

        $this->tracker->approveByQuestionId($questionId);
        $this->assertTrue($this->tracker->isApproved($operationKey));

        // Second call (retry): should be allowed
        $dto2 = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_17',
            toolName: 'bash',
            arguments: ['command' => $command],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto2->kind);
        $this->assertFalse($this->tracker->isApproved($operationKey), 'Approval should be consumed on use');
    }

    public function testDeniedOperationIsNotApproved(): void
    {
        $command = 'rm -rf /tmp/test';

        // First call: RequireApproval
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_18',
            toolName: 'bash',
            arguments: ['command' => $command],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $operationKey = $dto->details['operation_key'];
        $this->assertNotNull($operationKey);

        // Simulate denial by the human
        $this->tracker->remove($operationKey);
        $this->assertFalse($this->tracker->isApproved($operationKey));

        // Second call (retry after denial): should still require approval
        $dto2 = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_19',
            toolName: 'bash',
            arguments: ['command' => $command],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto2->kind);
    }

    public function testHooksHandleNonInteractiveAutoDeny(): void
    {
        $config = new SafeGuardConfig(autoDenyInNoninteractive: true);
        $classifier = SafeGuardClassifier::fromConfig($config);
        $policy = SafeGuardPolicy::fromConfig($config);
        $hook = new SafeGuardToolCallHook(
            classifier: $classifier,
            policy: $policy,
            approvalTracker: new ApprovalSessionTracker(),
            policyWriter: null,
            cwd: $this->cwd,
            autoDenyInNoninteractive: true,
        );

        // Destructive command should be blocked (not require approval) in noninteractive mode
        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_20',
            toolName: 'bash',
            arguments: ['command' => 'rm -rf /tmp/build'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertTrue($dto->details['auto_denied'] ?? false);
        $this->assertSame('destructive', $dto->details['category']);
    }

    public function testHardBlockStaysBlockedInNoninteractive(): void
    {
        $config = new SafeGuardConfig(autoDenyInNoninteractive: true);
        $classifier = SafeGuardClassifier::fromConfig($config);
        $policy = SafeGuardPolicy::fromConfig($config);
        $hook = new SafeGuardToolCallHook(
            classifier: $classifier,
            policy: $policy,
            approvalTracker: new ApprovalSessionTracker(),
            policyWriter: null,
            cwd: $this->cwd,
            autoDenyInNoninteractive: true,
        );

        // sudo is HardBlock — never approvable, always Block
        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_21',
            toolName: 'bash',
            arguments: ['command' => 'sudo rm -rf /'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertSame('hard_block', $dto->details['category']);
    }

    // ── onApprovalAnswered ──

    public function testAllowOnceApprovesAndConsumesOnRetry(): void
    {
        $command = 'rm -rf /tmp/build';

        // First call: RequireApproval
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_22',
            toolName: 'bash',
            arguments: ['command' => $command],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $operationKey = $dto->details['operation_key'] ?? null;
        $this->assertNotNull($operationKey);
        $questionId = (string) ($dto->details['question_id'] ?? '');
        $this->assertNotEmpty($questionId);

        // Human answers "Allow once" through onApprovalAnswered
        $this->hook->onApprovalAnswered(new ApprovalAnswerContextDTO(
            questionId: $questionId,
            answer: '✅ Allow once',
            toolName: 'bash',
            approvalContext: [
                'operation_key' => $operationKey,
                'category' => 'destructive',
                'command' => $command,
                'tool_name' => 'bash',
            ],
        ));

        $this->assertTrue($this->tracker->isApproved($operationKey), 'Tracker should mark approved after "Allow once"');

        // Second call (retry): consumed and allowed
        $dto2 = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_23',
            toolName: 'bash',
            arguments: ['command' => $command],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto2->kind);
        $this->assertFalse($this->tracker->isApproved($operationKey), 'Approval should be consumed after retry');
    }

    public function testDenyRemovesPendingAndRejectsRetry(): void
    {
        $command = 'rm -rf /tmp/test';

        // First call: RequireApproval
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_24',
            toolName: 'bash',
            arguments: ['command' => $command],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $operationKey = $dto->details['operation_key'] ?? null;
        $this->assertNotNull($operationKey);
        $questionId = (string) ($dto->details['question_id'] ?? '');

        // Human answers "Deny" through onApprovalAnswered
        $this->hook->onApprovalAnswered(new ApprovalAnswerContextDTO(
            questionId: $questionId,
            answer: '❌ Block',
            toolName: 'bash',
            approvalContext: [
                'operation_key' => $operationKey,
                'category' => 'destructive',
                'command' => $command,
                'tool_name' => 'bash',
            ],
        ));

        $this->assertFalse($this->tracker->isApproved($operationKey), 'Tracker should not approve after denial');

        // Second call (retry after denial): still requires approval
        $dto2 = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_25',
            toolName: 'bash',
            arguments: ['command' => $command],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto2->kind);
    }

    public function testAlwaysAllowApprovesAndPersistsToPolicyFile(): void
    {
        $tmpDir = sys_get_temp_dir().'/sg_hook_test_'.uniqid();
        mkdir($tmpDir, 0o755, true);
        $settingsPath = $tmpDir.'/settings.yaml';

        try {
            $config = new SafeGuardConfig(autoDenyInNoninteractive: false);
            $classifier = SafeGuardClassifier::fromConfig($config);
            $policy = SafeGuardPolicy::fromConfig($config);
            $tracker = new ApprovalSessionTracker();
            $policyWriter = new SafeGuardPolicyWriter($settingsPath);
            $hook = new SafeGuardToolCallHook(
                classifier: $classifier,
                policy: $policy,
                approvalTracker: $tracker,
                policyWriter: $policyWriter,
                cwd: $this->cwd,
                autoDenyInNoninteractive: false,
            );

            $command = 'rm -rf /tmp/build';

            // First call: RequireApproval
            $dto = $hook->onToolCall(new ToolCallContextDTO(
                toolCallId: 'call_26',
                toolName: 'bash',
                arguments: ['command' => $command],
                orderIndex: 0,
            ));

            $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
            $operationKey = $dto->details['operation_key'] ?? null;
            $this->assertNotNull($operationKey);
            $questionId = (string) ($dto->details['question_id'] ?? '');

            // Human answers "Always allow" through onApprovalAnswered
            $hook->onApprovalAnswered(new ApprovalAnswerContextDTO(
                questionId: $questionId,
                answer: '📌 Always allow',
                toolName: 'bash',
                approvalContext: [
                    'operation_key' => $operationKey,
                    'category' => 'destructive',
                    'command' => $command,
                    'tool_name' => 'bash',
                ],
            ));

            $this->assertTrue($tracker->isApproved($operationKey), 'Tracker should approve for next retry');

            // Second call: approved (consumed)
            $dto2 = $hook->onToolCall(new ToolCallContextDTO(
                toolCallId: 'call_27',
                toolName: 'bash',
                arguments: ['command' => $command],
                orderIndex: 0,
            ));

            $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto2->kind);

            // Policy file should contain the persisted pattern
            $this->assertFileExists($settingsPath);
            $content = file_get_contents($settingsPath);
            $this->assertStringContainsString('rm -rf /tmp/build', $content);
            $this->assertStringContainsString('allow_command_patterns', $content);
            $this->assertStringNotContainsString('✅', $content, 'Emoji icon must not leak into settings.yaml');
            $this->assertStringNotContainsString('📌', $content, 'Emoji icon must not leak into settings.yaml');
            $this->assertStringNotContainsString('❌', $content, 'Emoji icon must not leak into settings.yaml');
        } finally {
            if (file_exists($settingsPath)) {
                unlink($settingsPath);
            }
            @rmdir($tmpDir);
        }
    }

    public function testOnApprovalAnsweredWithEmptyOperationKeyIsNoop(): void
    {
        $command = 'rm -rf /tmp/build';

        // First call: RequireApproval with a real pending key
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_empty_key',
            toolName: 'bash',
            arguments: ['command' => $command],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $operationKey = $dto->details['operation_key'] ?? null;
        $this->assertNotNull($operationKey);
        $questionId = (string) ($dto->details['question_id'] ?? '');
        $this->assertNotEmpty($questionId);

        // Answer with same questionId but empty operation_key
        $this->hook->onApprovalAnswered(new ApprovalAnswerContextDTO(
            questionId: $questionId,
            answer: '✅ Allow once',
            toolName: 'bash',
            approvalContext: [
                'operation_key' => '',
                'category' => 'destructive',
            ],
        ));

        // Tracker should still not be approved — empty key is rejected
        $this->assertFalse($this->tracker->isApproved($operationKey), 'Empty operation_key should not approve');

        // Retry: still requires approval (approval was never granted)
        $dto2 = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_empty_key_retry',
            toolName: 'bash',
            arguments: ['command' => $command],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto2->kind);
        $this->addToAssertionCount(1); // Reached without exception
    }

    public function testOnApprovalAnsweredWithMissingOperationKeyIsNoop(): void
    {
        $command = 'rm -rf /';

        // First call: RequireApproval with a real pending key
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_missing_key',
            toolName: 'bash',
            arguments: ['command' => $command],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $operationKey = $dto->details['operation_key'] ?? null;
        $this->assertNotNull($operationKey);
        $questionId = (string) ($dto->details['question_id'] ?? '');
        $this->assertNotEmpty($questionId);

        // Answer with same questionId but missing operation_key entirely
        $this->hook->onApprovalAnswered(new ApprovalAnswerContextDTO(
            questionId: $questionId,
            answer: '✅ Allow once',
            toolName: 'bash',
            approvalContext: [
                'category' => 'destructive',
                'command' => $command,
            ],
        ));

        // Tracker should still not be approved — missing key is rejected
        $this->assertFalse($this->tracker->isApproved($operationKey), 'Missing operation_key should not approve');

        // Retry: still requires approval (approval was never granted)
        $dto2 = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_missing_key_retry',
            toolName: 'bash',
            arguments: ['command' => $command],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto2->kind);
        $this->addToAssertionCount(1); // Reached without exception
    }

    public function testResolveApprovalAnswerAllowOnceReturnsAllow(): void
    {
        $outcome = $this->hook->resolveApprovalAnswer(new ApprovalAnswerContextDTO(
            questionId: 'sg_qid',
            answer: '✅ Allow once',
            toolName: 'write',
            approvalContext: ['category' => 'write_outside_cwd'],
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Allow, $outcome->kind);
    }

    public function testResolveApprovalAnswerAlwaysAllowReturnsAllow(): void
    {
        $outcome = $this->hook->resolveApprovalAnswer(new ApprovalAnswerContextDTO(
            questionId: 'sg_qid',
            answer: '📌 Always allow',
            toolName: 'write',
            approvalContext: ['category' => 'write_outside_cwd'],
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Allow, $outcome->kind);
    }

    public function testResolveApprovalAnswerDenyReturnsBlockWithSafeGuardReason(): void
    {
        $outcome = $this->hook->resolveApprovalAnswer(new ApprovalAnswerContextDTO(
            questionId: 'sg_qid',
            answer: '❌ Block',
            toolName: 'write',
            approvalContext: ['category' => 'write_outside_cwd'],
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Block, $outcome->kind);
        $this->assertSame('safeguard_denied', $outcome->reason);
        $this->assertStringContainsString('denied by SafeGuard', $outcome->details['message'] ?? '');
    }

    public function testResolveApprovalAnswerUnknownDenyReturnsBlockWithUnknownReason(): void
    {
        $outcome = $this->hook->resolveApprovalAnswer(new ApprovalAnswerContextDTO(
            questionId: 'sg_qid',
            answer: 'Maybe',
            toolName: 'write',
            approvalContext: ['category' => 'write_outside_cwd'],
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Block, $outcome->kind);
        $this->assertSame('safeguard_unknown_answer', $outcome->reason);
        $this->assertStringContainsString('unknown answer', $outcome->details['message'] ?? '');
    }
    // ─── Cancel and icon-label coverage ─────────────────────────────

    public function testResolveApprovalAnswerCancelReturnsBlockWithCancelledReason(): void
    {
        // ESC / user cancel on the TUI overlay sends 'cancel' as the answer.
        // SafeGuard must recognise it explicitly and block with a clean reason.
        $outcome = $this->hook->resolveApprovalAnswer(new ApprovalAnswerContextDTO(
            questionId: 'sg_qid',
            answer: 'cancel',
            toolName: 'write',
            approvalContext: ['category' => 'write_outside_cwd'],
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Block, $outcome->kind);
        $this->assertSame('safeguard_cancelled', $outcome->reason);
        $this->assertStringContainsString('cancelled by the user', $outcome->details['message'] ?? '');
    }

    public function testResolveApprovalAnswerIconLabelsMapToCorrectOutcomes(): void
    {
        // The TUI sends icon-bearing labels (the values from APPROVAL_OPTIONS).
        // resolveApprovalAnswer must reverse-map each to the correct canonical action.

        // '✅ Allow once' → allow
        $outcome = $this->hook->resolveApprovalAnswer(new ApprovalAnswerContextDTO(
            questionId: 'sg_qid',
            answer: '✅ Allow once',
            toolName: 'write',
            approvalContext: ['category' => 'write_outside_cwd'],
        ));
        $this->assertSame(ToolCallDecisionKindEnum::Allow, $outcome->kind);

        // '📌 Always allow' → allow
        $outcome = $this->hook->resolveApprovalAnswer(new ApprovalAnswerContextDTO(
            questionId: 'sg_qid',
            answer: '📌 Always allow',
            toolName: 'write',
            approvalContext: ['category' => 'write_outside_cwd'],
        ));
        $this->assertSame(ToolCallDecisionKindEnum::Allow, $outcome->kind);

        // '❌ Block' → block
        $outcome = $this->hook->resolveApprovalAnswer(new ApprovalAnswerContextDTO(
            questionId: 'sg_qid',
            answer: '❌ Block',
            toolName: 'write',
            approvalContext: ['category' => 'write_outside_cwd'],
        ));
        $this->assertSame(ToolCallDecisionKindEnum::Block, $outcome->kind);
        $this->assertSame('safeguard_denied', $outcome->reason);
    }

    public function testResolveApprovalAnswerOldLabelWithoutIconFallsThroughToUnknown(): void
    {
        // If a test or a non-icon-bearing client sends the old label without
        // the icon glyph, array_search fails → unknown → fail-closed block.
        // This proves the icon labels are the new canonical answer format.
        $outcome = $this->hook->resolveApprovalAnswer(new ApprovalAnswerContextDTO(
            questionId: 'sg_qid',
            answer: 'Allow once',
            toolName: 'write',
            approvalContext: ['category' => 'write_outside_cwd'],
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Block, $outcome->kind);
        $this->assertSame('safeguard_unknown_answer', $outcome->reason);
    }

    // ─── Approval-channel availability ────────────────────────────────

    public function testAutoDenyBlocksWhenNoApprovalChannel(): void
    {
        // Construct with autoDenyInNoninteractive=true and no HATFIELD_APPROVAL_CHANNEL env.
        // The destructive `rm -rf` should be auto-blocked instead of prompting.
        $hook = $this->createHook(autoDeny: true);

        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_auto_deny',
            toolName: 'bash',
            arguments: ['command' => 'rm -rf /tmp/test'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertTrue((bool) ($dto->details['auto_denied'] ?? false));
        $this->assertTrue((bool) ($dto->details['denied'] ?? false));
    }

    #[\PHPUnit\Framework\Attributes\BackupGlobals(true)]
    public function testAutoDenyPromptsWhenApprovalChannelIsSet(): void
    {
        // Simulate interactive TUI spawning the controller with
        // HATFIELD_APPROVAL_CHANNEL=controller.
        putenv('HATFIELD_APPROVAL_CHANNEL=controller');

        try {
            $hook = $this->createHook(autoDeny: true);

            $dto = $hook->onToolCall(new ToolCallContextDTO(
                toolCallId: 'call_with_channel',
                toolName: 'bash',
                arguments: ['command' => 'rm -rf /tmp/test'],
                orderIndex: 0,
            ));

            // Approval channel should override auto-deny → RequireApproval
            $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
            $this->assertArrayHasKey('question_id', $dto->details);
            $this->assertNotEmpty((string) ($dto->details['question_id'] ?? ''));
        } finally {
            putenv('HATFIELD_APPROVAL_CHANNEL');
        }
    }

    public function testAutoDenyFalseRequiresApprovalWithoutChannel(): void
    {
        // autoDenyInNoninteractive=false, no channel → still prompts.
        // This is the explicit testing/headless-broker mode.
        $hook = $this->createHook(autoDeny: false);

        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_no_channel_false',
            toolName: 'bash',
            arguments: ['command' => 'rm -rf /tmp/test'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
    }

    public function testAutoDenyRelaxableForNoninteractiveChildDespiteApprovalChannel(): void
    {
        putenv('HATFIELD_APPROVAL_CHANNEL=controller');
        try {
            $hook = $this->createHook(autoDeny: true);
            $dto = $hook->onToolCall(new ToolCallContextDTO(
                toolCallId: 'call_child_rm',
                toolName: 'bash',
                arguments: ['command' => 'rm -rf /tmp/child-build'],
                orderIndex: 0,
                runId: 'child-run',
                metadata: ['noninteractive_child_run' => true],
            ));

            $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
            $this->assertTrue((bool) ($dto->details['auto_denied'] ?? false));
            $this->assertSame('destructive', $dto->details['category']);
        } finally {
            putenv('HATFIELD_APPROVAL_CHANNEL');
        }
    }

    public function testInteractiveParentStillRequiresApprovalWithApprovalChannel(): void
    {
        putenv('HATFIELD_APPROVAL_CHANNEL=controller');
        try {
            $hook = $this->createHook(autoDeny: true);
            $dto = $hook->onToolCall(new ToolCallContextDTO(
                toolCallId: 'call_parent_rm',
                toolName: 'bash',
                arguments: ['command' => 'rm -rf /tmp/test-build'],
                orderIndex: 0,
                runId: 'parent-run',
                metadata: ['noninteractive_child_run' => false],
            ));

            $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        } finally {
            putenv('HATFIELD_APPROVAL_CHANNEL');
        }
    }

    public function testSettingsMutationWithChannelOffersAllowOnceAndBlockOnly(): void
    {
        putenv('HATFIELD_APPROVAL_CHANNEL=controller');
        try {
            $tracker = new ApprovalSessionTracker();
            $config = new SafeGuardConfig(autoDenyInNoninteractive: false);
            $hook = new SafeGuardToolCallHook(
                classifier: SafeGuardClassifier::fromConfig($config),
                policy: new SafeGuardPolicy(),
                approvalTracker: $tracker,
                policyWriter: null,
                cwd: $this->cwd,
                autoDenyInNoninteractive: false,
            );
            $dto = $hook->onToolCall(new ToolCallContextDTO(
                toolCallId: 'call_settings_set',
                toolName: 'settings',
                arguments: ['operation' => 'set', 'path' => 'tui.theme', 'scope' => 'project', 'value' => 'nord'],
                orderIndex: 0,
            ));

            $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
            $this->assertSame(
                ['✅ Allow once', '❌ Block'],
                $dto->details['schema']['enum'] ?? null,
            );
            $this->assertArrayNotHasKey('non_persistent', $dto->details);
            $operationKey = (string) ($dto->details['operation_key'] ?? '');
            $this->assertSame('settings:set:project:tui.theme', $operationKey);
            $questionId = (string) ($dto->details['question_id'] ?? '');

            $allowed = $hook->resolveApprovalAnswer(new ApprovalAnswerContextDTO(
                questionId: $questionId,
                answer: '✅ Allow once',
                toolName: 'settings',
                approvalContext: [
                    'operation_key' => $operationKey,
                    'category' => 'custom_dangerous',
                ],
            ));
            $this->assertSame(ToolCallDecisionKindEnum::Allow, $allowed->kind);

            $hook->onApprovalAnswered(new ApprovalAnswerContextDTO(
                questionId: $questionId,
                answer: '✅ Allow once',
                toolName: 'settings',
                approvalContext: [
                    'operation_key' => $operationKey,
                    'category' => 'custom_dangerous',
                ],
            ));
            $this->assertTrue($tracker->isApproved($operationKey));

            $blocked = $hook->resolveApprovalAnswer(new ApprovalAnswerContextDTO(
                questionId: $questionId,
                answer: '❌ Block',
                toolName: 'settings',
                approvalContext: [
                    'operation_key' => $operationKey,
                    'category' => 'custom_dangerous',
                ],
            ));
            $this->assertSame(ToolCallDecisionKindEnum::Block, $blocked->kind);
        } finally {
            putenv('HATFIELD_APPROVAL_CHANNEL');
        }
    }

    public function testSettingsMutationWithoutChannelFailsClosedEvenWhenAutoDenyFalse(): void
    {
        $hook = $this->createHook(autoDeny: false);
        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_settings_no_channel',
            toolName: 'settings',
            arguments: ['operation' => 'remove', 'path' => 'tui.theme', 'scope' => 'user'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertTrue((bool) ($dto->details['auto_denied'] ?? false));
    }

    private function backupAndClearApprovalChannelEnv(): void
    {
        $value = getenv('HATFIELD_APPROVAL_CHANNEL');
        $this->approvalChannelEnvBackup = false === $value ? false : $value;
        putenv('HATFIELD_APPROVAL_CHANNEL');
    }

    private function restoreApprovalChannelEnv(): void
    {
        if (false === $this->approvalChannelEnvBackup) {
            putenv('HATFIELD_APPROVAL_CHANNEL');

            return;
        }

        putenv('HATFIELD_APPROVAL_CHANNEL='.$this->approvalChannelEnvBackup);
    }

    /**
     * Create a fresh hook with the given auto-deny setting and no
     * policy writer so each test gets its own clean tracker.
     */
    private function createHook(bool $autoDeny): SafeGuardToolCallHook
    {
        $config = new SafeGuardConfig(autoDenyInNoninteractive: $autoDeny);
        $classifier = SafeGuardClassifier::fromConfig($config);
        $tracker = new ApprovalSessionTracker();

        return new SafeGuardToolCallHook(
            classifier: $classifier,
            policy: new SafeGuardPolicy(),
            approvalTracker: $tracker,
            policyWriter: null,
            cwd: $this->cwd,
            autoDenyInNoninteractive: $autoDeny,
        );
    }
}
