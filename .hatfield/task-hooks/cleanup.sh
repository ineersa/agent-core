#!/usr/bin/env bash
set -euo pipefail
# No project-wide teardown for tasks which never began provisioning.
if [[ "${HATFIELD_TASK_SETUP_ATTEMPTED:-0}" == 0 || -z "${HATFIELD_TASK_WORKTREE:-}" ]]; then
    exit 0
fi
# Hook resources and IDEA exclusions are idempotent; Git removal belongs to Hatfield.
php "$HATFIELD_PROJECT_ROOT/.hatfield/task-hooks/idea.php" cleanup
