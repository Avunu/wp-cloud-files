#!/usr/bin/env bash
#
# Everything the CI shell's enterTest runs, in one place.
#
# Kept as a repo file rather than inline Nix so it can be edited without a
# devenv rebuild. Expects MariaDB and MinIO to already be up.
#
#   WPCF_SUITES="unit integration" tests/bin/run-all.sh

set -euo pipefail

ROOT="${DEVENV_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
cd "$ROOT"

STATE="${DEVENV_STATE:-$ROOT/.devenv/state}"
export TMPDIR="$STATE/test-tmp"
mkdir -p "$TMPDIR"

SUITES="${WPCF_SUITES:-unit thumbnails integration}"
PHPUNIT="$ROOT/tests/tools/vendor/bin/phpunit"

status=0
run() {
    echo ""
    echo "──────────────────────────────────────────────────────────────"
    echo "  $*"
    echo "──────────────────────────────────────────────────────────────"
    if ! "$@"; then
        status=1
    fi
}

# ------------------------------------------------------------------ #
# Dependencies                                                        #
# ------------------------------------------------------------------ #
echo "==> Installing dependencies"
composer install --no-interaction --quiet
composer install --no-interaction --quiet --working-dir=tests/tools

# DocumentThumbnailer resolves DomPDF through WP_PLUGIN_DIR and a directory
# literally named wp-cloud-files.
mkdir -p tests/.plugin-dir
ln -sfn "$ROOT" tests/.plugin-dir/wp-cloud-files

for suite in $SUITES; do
    case "$suite" in
        unit)
            for profile in default root maxsize; do
                run env WPCF_TEST_PROFILE="$profile" \
                    php "$PHPUNIT" -c tests/phpunit-unit.xml --testsuite=unit
            done
            ;;

        thumbnails)
            # Not compgen: it is a programmable-completion builtin and is not
            # reliably available in the non-interactive shell devenv runs this in,
            # where it silently reported no match and skipped the whole suite.
            if ls tests/Thumbnails/*Test.php > /dev/null 2>&1; then
                run php "$PHPUNIT" -c tests/phpunit-unit.xml --testsuite=thumbnails
            else
                echo "(no thumbnail tests yet, skipping)"
            fi
            ;;

        integration)
            run "$ROOT/tests/bin/run-integration.sh"
            ;;

        *)
            echo "Unknown suite: $suite" >&2
            status=1
            ;;
    esac
done

echo ""
if [ "$status" -eq 0 ]; then
    echo "✅ All requested suites passed."
else
    echo "❌ At least one suite failed."
fi
exit $status
