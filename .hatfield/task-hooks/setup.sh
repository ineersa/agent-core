#!/usr/bin/env bash
set -euo pipefail
: "${HATFIELD_PROJECT_ROOT:?}" "${HATFIELD_TASK_WORKTREE:?}" "${HATFIELD_TASK_DATA_DIR:?}"
mkdir -p -- "$HATFIELD_TASK_DATA_DIR"
for tree in vendor .vera; do
    # A failed copy may leave a destination directory. Record completion only
    # after cp succeeds, outside the worktree, so retries finish partial copies.
    if [[ -d "$HATFIELD_PROJECT_ROOT/$tree" && ( ! -f "$HATFIELD_TASK_DATA_DIR/$tree.copied" || ! -d "$HATFIELD_TASK_WORKTREE/$tree" ) ]]; then
        mkdir -p -- "$HATFIELD_TASK_WORKTREE/$tree"
        cp -a -- "$HATFIELD_PROJECT_ROOT/$tree/." "$HATFIELD_TASK_WORKTREE/$tree/"
        touch -- "$HATFIELD_TASK_DATA_DIR/$tree.copied"
    fi
done
# Copied Composer path repositories may still point relative to the primary
# checkout. Repair through Composer rather than patching generated autoload files.
if [[ -e "$HATFIELD_PROJECT_ROOT/vendor/ineersa/hatfield-extension-api" && ! -d vendor/ineersa/hatfield-extension-api ]]; then
    composer install --no-interaction --no-progress
    test -d vendor/ineersa/hatfield-extension-api
fi
if [[ -f .hatfield/extensions/composer.json ]]; then
    composer install -d .hatfield/extensions --no-interaction --no-progress
fi
php "$HATFIELD_PROJECT_ROOT/.hatfield/task-hooks/idea.php" setup
