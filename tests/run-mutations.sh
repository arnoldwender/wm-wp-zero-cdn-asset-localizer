#!/usr/bin/env bash
#
# tests/run-mutations.sh — checks that the standalone suites defend each correction listed in tests/mutations.php.
#
# For every entry: copy the repository (tracked and untracked files, no .git) to a temp dir, undo one correction with
# literal replacements, run `tests/test-suite.php` and `tests/test-uninstall.php`, and require at least one of them to
# report a failed assertion. A search string that does not occur exactly once is reported as "not applied" and counts
# as a failure, so the list cannot rot silently when the code moves; so does a run that never reaches a results line.
# A control run on the unmutated copy goes first.
#
# Usage: bash tests/run-mutations.sh        (from anywhere)
# Exit:  0 when the control is green and every mutation applied and turned a suite red.

set -uo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
WORK=$(mktemp -d "${TMPDIR:-/tmp}/wm-zero-cdn-mutations.XXXXXX")
trap 'rm -rf "$WORK"' EXIT

SUITES=(tests/test-suite.php tests/test-uninstall.php)
# "TEST RESULTS: n PASSED | m FAILED" (test-suite) and "RESULT: n passed, m failed" (test-uninstall).
RESULTS_ANY='(TEST RESULTS: [0-9]+ PASSED \| [0-9]+ FAILED|RESULT: [0-9]+ passed, [0-9]+ failed)'
RESULTS_RED='(TEST RESULTS: [0-9]+ PASSED \| [1-9][0-9]* FAILED|RESULT: [0-9]+ passed, [1-9][0-9]* failed)'

# True when a line of $2 matches the extended regex $1. A here-string, not `printf | grep -q`: with pipefail, grep -q
# exits at the first match, printf gets SIGPIPE on output larger than the pipe buffer and the pipeline fails although
# the line is there (measured on wm-cyber-events-core, 2026-09-15).
has_line() {
  grep -Eq "$1" <<<"$2"
}

selfcheck_out="  TEST RESULTS: 1 PASSED | 1 FAILED"$'\n'"$(head -c 1048576 /dev/zero | tr '\0' 'x')"
if ! has_line "$RESULTS_RED" "$selfcheck_out"; then
  echo "RUNNER SELF-CHECK FAILED  a results line followed by 1 MB of output was not found"
  exit 1
fi
unset selfcheck_out

fresh_copy() {
  rm -rf "$WORK/repo" && mkdir -p "$WORK/repo"
  # tar, not rsync: rsync is absent from ordinary base images (measured: ubuntu:24.04 ships tar and
  # no rsync) and the fleet's CI runs in a container. The error is no longer sent to /dev/null,
  # because the one sentence that says what happened was the one being discarded.
  local expected copied
  expected=$( (cd "$ROOT" && git ls-files -z --cached --others --exclude-standard) | tr -dc '\0' | wc -c | tr -d ' ' )
  if ! (cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | tar -c -f - --null -T -) \
       | (cd "$WORK/repo" && tar -x -f -); then
    echo "COPY FAILED  could not copy the working tree into $WORK/repo (is tar present?)" >&2
    return 1
  fi
  # The exit status of a pipeline is not evidence that anything was copied: the extracting tar exits
  # 0 when it receives nothing. Counting is the check that holds whatever the repository's layout is
  # — an earlier version of this guard required tests/mutations.php and refused the one repository
  # that keeps its mutations inside this script. Symlinks count: git lists them and tar copies them.
  copied=$(find "$WORK/repo" \( -type f -o -type l \) | wc -l | tr -d ' ')
  if [ "$copied" -lt "$expected" ]; then
    echo "COPY SHORT   copied $copied of $expected file(s) into $WORK/repo" >&2
    return 1
  fi
}

# Runs both suites on the copy; prints their output, sets $verdict to RED, GREEN or NO_RESULT.
run_suites() {
  local out="" one="" reached=0
  verdict=GREEN
  for suite in "${SUITES[@]}"; do
    one=$(cd "$WORK/repo" && php "$suite" 2>&1)
    out+="$one"$'\n'
    if has_line "$RESULTS_ANY" "$one"; then
      reached=1
    fi
    if has_line "$RESULTS_RED" "$one"; then
      verdict=RED
    fi
  done
  if has_line '(PHP )?Parse error' "$out"; then
    verdict=PARSE_ERROR
  elif [ "$reached" -eq 0 ]; then
    verdict=NO_RESULT
  fi
  suite_out="$out"
}

count=$(php -r 'echo count(require $argv[1]);' "$ROOT/tests/mutations.php")

fresh_copy || exit 1
run_suites
if [ "$verdict" != GREEN ]; then
  echo "CONTROL FAILED  the unmutated copy is not green ($verdict); mutations are meaningless"
  printf '%s\n' "$suite_out" | grep -E '\[FAIL\]' | head -5
  exit 1
fi
echo "CONTROL      unmutated copy green"

failed=0
for ((i = 0; i < count; i++)); do
  fresh_copy || { failed=1; continue; }
  desc=$(php -r '
    $m = (require $argv[1])[(int) $argv[2]];
    echo $m[0];
    foreach ($m[1] as [$file, $search, $replace]) {
      $path = $argv[3] . "/" . $file;
      $src  = (string) file_get_contents($path);
      if (substr_count($src, $search) !== 1) {
        fwrite(STDERR, $file . ": search occurs " . substr_count($src, $search) . " times");
        exit(3);
      }
      file_put_contents($path, str_replace($search, $replace, $src));
    }
  ' "$ROOT/tests/mutations.php" "$i" "$WORK/repo" 2>"$WORK/apply.err")
  if [ $? -ne 0 ]; then
    echo "NOT APPLIED  $desc ($(cat "$WORK/apply.err"))"
    failed=1
    continue
  fi

  run_suites
  case "$verdict" in
    RED) echo "RED          $desc" ;;
    GREEN) echo "SURVIVED     $desc"; failed=1 ;;
    PARSE_ERROR) echo "NO RESULT    $desc: the mutation made a file unparsable"; failed=1 ;;
    *) echo "NO RESULT    $desc: no suite reached its results line"; failed=1 ;;
  esac
done

exit "$failed"
