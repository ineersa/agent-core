<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Edit;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Psr\Log\LoggerInterface;

/** Bounded, metadata-only evidence captured before a contended edit closes its descriptor. */
final readonly class EditLockDiagnosticsService
{
    public function __construct(private LoggerInterface $logger, private StackToolExecutionContextAccessor $contexts)
    {
    }

    /** @param resource $stream */
    public function logTimeout(string $path, $stream, float $elapsed, int $attempts): void
    {
        $context = $this->contexts->current();
        $opened = fstat($stream);
        clearstatcache(true, $path);
        $current = @stat($path);
        $identity = false === $opened ? null : $this->fileIdentity($opened);
        $locks = $this->kernelLocks($identity);
        $pids = [getmypid()];
        foreach ($locks['records'] as $record) {
            if (!$record['blocked'] && $record['pid'] > 0) {
                $pids[] = $record['pid'];
            }
        }
        $processes = [];
        foreach (array_unique($pids) as $pid) {
            $processes[] = $this->processEvidence($pid, $identity);
        }
        $resolvedPath = realpath($path);
        $this->logger->error('edit.inode_lock_timeout', [
            'component' => 'edit_tool',
            'event_type' => 'edit.inode_lock_timeout',
            'run_id' => $context?->runId(),
            'session_id' => $context?->runId(),
            'tool_call_id' => $context?->toolCallId(),
            'step_id' => $context?->stepId(),
            'turn_no' => $context?->turnNo(),
            'tool_name' => 'edit',
            'waiter_pid' => getmypid(),
            'target_path' => $path,
            'resolved_path' => false === $resolvedPath ? null : $resolvedPath,
            'opened_file' => $identity,
            'current_path_file' => false === $current ? null : $this->fileIdentity($current),
            'path_identity_changed' => false === $current || false === $opened ? null : ($opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino']),
            'wait_seconds' => $elapsed,
            'limit_seconds' => 5,
            'attempts' => $attempts,
            'kernel_locks' => $locks,
            'processes' => $processes,
            // Arguments may contain raw patches or code-mode scripts: never capture them.
            'call_stack' => debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 24),
            // /proc is namespace/permission limited and racy. An inherited flock
            // can outlive its original PID; no match is NOT proof of no holder.
            'evidence_scope' => 'best_effort_current_pid_namespace; original_lock_pid_not_all_inherited_holders; no_global_fd_scan',
        ]);
    }

    /** @param array<string|int, int> $stat
     * @return array{device: int, inode: int, links: int, size: int, mode: int, uid: int, gid: int}
     */
    private function fileIdentity(array $stat): array
    {
        return ['device' => $stat['dev'], 'inode' => $stat['ino'], 'links' => $stat['nlink'], 'size' => $stat['size'], 'mode' => $stat['mode'], 'uid' => $stat['uid'], 'gid' => $stat['gid']];
    }

    /** @param array{device: int, inode: int, ...}|null $identity
     * @return array{status: string, truncated: bool, records: list<array{type: string, mode: string, pid: int, blocked: bool, device: string, inode: string}>}
     */
    private function kernelLocks(?array $identity): array
    {
        $result = ['status' => 'unavailable', 'truncated' => false, 'records' => []];
        if ('Linux' !== \PHP_OS_FAMILY || null === $identity) {
            return $result;
        }
        $text = @file_get_contents('/proc/locks', false, null, 0, 1_048_576);
        if (false === $text) {
            return $result;
        }
        $result['status'] = 'read';
        $result['truncated'] = \strlen($text) >= 1_048_576;
        // Linux dev_t encoding, as used by major()/minor(); /proc/locks prints hex.
        $dev = $identity['device'];
        $major = (($dev >> 8) & 0xFFF) | (($dev >> 32) & 0xFFFFF000);
        $minor = ($dev & 0xFF) | (($dev >> 12) & 0xFFFFFF00);
        foreach (explode("\n", $text) as $line) {
            if (1 !== preg_match('/^\d+:\s+(?:(->)\s+)?(\S+)\s+\S+\s+(\S+)\s+(-?\d+)\s+([0-9a-f]+):([0-9a-f]+):(\d+)\s/i', $line, $m)
                || hexdec($m[5]) !== $major || hexdec($m[6]) !== $minor || $m[7] !== (string) $identity['inode']) {
                continue;
            }
            if (\count($result['records']) >= 16) {
                $result['truncated'] = true;
                break;
            }
            $result['records'][] = ['type' => $m[2], 'mode' => $m[3], 'pid' => (int) $m[4], 'blocked' => '->' === $m[1], 'device' => $m[5].':'.$m[6], 'inode' => $m[7]];
        }

        return $result;
    }

    /** @param array{device: int, inode: int, ...}|null $identity
     * @return array<string, mixed>
     */
    private function processEvidence(int $pid, ?array $identity): array
    {
        $result = ['pid' => $pid, 'status' => 'unavailable', 'matching_fds' => [], 'fds_truncated' => false];
        if ('Linux' !== \PHP_OS_FAMILY || null === $identity) {
            return $result;
        }
        $base = '/proc/'.$pid;
        $stat = @file_get_contents($base.'/stat', false, null, 0, 8192);
        $end = false === $stat ? false : strrpos($stat, ')');
        if (false !== $end) {
            $fields = explode(' ', substr($stat, $end + 2));
            $result['status'] = 'read';
            $result['state'] = $fields[0] ?? null;
            $result['parent_pid'] = isset($fields[1]) ? (int) $fields[1] : null;
            $result['start_ticks'] = $fields[19] ?? null;
        }
        $pidNamespace = @readlink($base.'/ns/pid');
        $mountNamespace = @readlink($base.'/ns/mnt');
        $executable = @readlink($base.'/exe');
        $result['executable'] = false === $executable ? null : $executable;
        $result['pid_namespace'] = false === $pidNamespace ? null : $pidNamespace;
        $result['mount_namespace'] = false === $mountNamespace ? null : $mountNamespace;
        $dir = @opendir($base.'/fd');
        $result['fds_status'] = false === $dir ? 'unavailable' : 'read';
        if (false === $dir) {
            return $result;
        }
        try {
            $count = 0;
            while (false !== ($fd = readdir($dir))) {
                if (!ctype_digit($fd)) {
                    continue;
                }
                if (++$count > 256) {
                    $result['fds_truncated'] = true;
                    break;
                }
                $descriptorPath = $base.'/fd/'.$fd;
                clearstatcache(true, $descriptorPath);
                $file = @stat($descriptorPath);
                if (false === $file || $file['dev'] !== $identity['device'] || $file['ino'] !== $identity['inode']) {
                    continue;
                }
                // fdinfo contains kernel metadata only, not target bytes or arguments.
                $info = @file_get_contents($base.'/fdinfo/'.$fd, false, null, 0, 4096);
                $entry = ['fd' => (int) $fd, 'fdinfo_available' => false !== $info];
                if (false !== $info) {
                    foreach (['flags', 'mnt_id', 'ino'] as $key) {
                        if (1 === preg_match('/^'.$key.':\s+(\d+)/m', $info, $match)) {
                            $entry[$key] = $match[1];
                        }
                    }
                    $entry['has_lock_record'] = str_contains($info, 'lock:');
                }
                $result['matching_fds'][] = $entry;
            }
        } finally {
            closedir($dir);
        }

        return $result;
    }
}
