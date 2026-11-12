#!/usr/bin/env bash
# Explicit backup/export, never an alternative runtime task store.
set -euo pipefail
export GH_PROMPT_DISABLED=1
if [[ $# != 3 || ! $2 =~ ^[1-9][0-9]*$ ]]; then
    printf 'Usage: bash export-task.sh [HOST/]OWNER/REPO ISSUE_NUMBER NEW_OUTPUT_DIRECTORY\n' >&2
    exit 2
fi
repository=$1
issue=$2
destination=$3
IFS=/ read -r -a identity <<< "$repository"
case ${#identity[@]} in
    2) host=github.com; owner=${identity[0]}; repo=${identity[1]} ;;
    3) host=${identity[0]}; owner=${identity[1]}; repo=${identity[2]} ;;
    *) printf 'Expected [HOST/]OWNER/REPO\n' >&2; exit 2 ;;
esac
# An existing directory is never clobbered. Failed exports remain visibly partial.
mkdir -- "$destination"
mkdir -- "$destination/artifacts"
export GH_HOST=$host
endpoint="repos/$owner/$repo/issues/$issue"
gh api --hostname "$host" --method GET "$endpoint" > "$destination/issue.json"
gh api --hostname "$host" --method GET "$endpoint/issue-field-values" > "$destination/field-values.json"
gh api --hostname "$host" --method GET --paginate --slurp "$endpoint/comments?per_page=100" > "$destination/comments.json"
for ((page=1; page<=100; page++)); do
    file="$destination/artifacts-page-$page.json"
    # Artifact listing has no Link header: explicitly request bounded pages.
    gh api --hostname "$host" --method GET "$endpoint/artifacts?per_page=100&page=$page" > "$file"
    php -r '$a=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); foreach($a as $v) { if (!isset($v["number"]) || !is_int($v["number"]) || $v["number"]<1) {throw new RuntimeException("Invalid artifact number");} echo $v["number"],PHP_EOL; }' "$file" > "$destination/artifact-numbers"
    mapfile -t numbers < "$destination/artifact-numbers"
    for number in "${numbers[@]}"; do
        gh issue artifact download "$issue" "$number" --repo "$repository" --output "$destination/artifacts/$number"
    done
    if (( ${#numbers[@]} < 100 )); then
        printf 'Export complete. Artifact metadata records each numbered file type.\n' > "$destination/COMPLETE"
        exit 0
    fi
done
printf 'Artifact page bound reached; export is incomplete.\n' >&2
exit 1
