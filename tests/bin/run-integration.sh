#!/usr/bin/env bash
#
# Run the WordPress integration suite against a given WordPress version.
#
#   WP_VERSION=7.0 tests/bin/run-integration.sh
#   WP_VERSION=trunk tests/bin/run-integration.sh
#
# Expects MariaDB and MinIO to already be running (devenv up, or enterTest).
# Core and the PHPUnit suite both come from one wordpress-develop tarball:
# src/ is core, tests/phpunit is the suite. No svn, no `npm run build`.

set -euo pipefail

ROOT="${DEVENV_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
STATE="${DEVENV_STATE:-$ROOT/.devenv/state}"
WP_VERSION="${WP_VERSION:-trunk}"

WP_DIR="$STATE/wordpress/$WP_VERSION"
export WP_TESTS_DIR="$WP_DIR/tests/phpunit"
export WP_CORE_DIR="$WP_DIR/src"

# ------------------------------------------------------------------ #
# Fetch WordPress (core + test suite) if we do not have it cached.    #
# ------------------------------------------------------------------ #
if [ ! -f "$WP_TESTS_DIR/includes/bootstrap.php" ]; then
    echo "==> Downloading WordPress $WP_VERSION (core + test suite)"
    rm -rf "$WP_DIR"
    mkdir -p "$WP_DIR"

    # Release branches are named by major.minor and track the latest patch, so a
    # "6.9" leg really means "the newest 6.9.x".
    case "$WP_VERSION" in
        trunk | nightly) ref="heads/trunk" ;;
        *) ref="heads/$WP_VERSION" ;;
    esac

    url="https://github.com/WordPress/wordpress-develop/archive/refs/$ref.tar.gz"
    if ! curl -fsSL "$url" | tar -xz -C "$WP_DIR" --strip-components=1; then
        echo "Error: could not download WordPress from $url" >&2
        exit 1
    fi
fi

if [ ! -f "$WP_TESTS_DIR/includes/bootstrap.php" ]; then
    echo "Error: $WP_TESTS_DIR/includes/bootstrap.php is missing after download." >&2
    exit 1
fi

# ------------------------------------------------------------------ #
# Writable content dir, with the plugin symlinked in under its real   #
# directory name (DocumentThumbnailer resolves DomPDF through it).    #
# ------------------------------------------------------------------ #
export WPCF_CONTENT_DIR="$STATE/wp-test/wp-content"
mkdir -p "$WPCF_CONTENT_DIR/plugins" "$WPCF_CONTENT_DIR/uploads"
ln -sfn "$ROOT" "$WPCF_CONTENT_DIR/plugins/wp-cloud-files"

export TMPDIR="$STATE/test-tmp"
mkdir -p "$TMPDIR"

export WP_TESTS_CONFIG_FILE_PATH="$ROOT/tests/bootstrap/wp-tests-config.php"

# ------------------------------------------------------------------ #
# Run once per configuration profile: the plugin reads its settings   #
# from immutable global constants, so they cannot vary in-process.    #
# ------------------------------------------------------------------ #
profiles=("${@:-default root broken-s3}")
# shellcheck disable=SC2206
profiles=(${profiles[*]})

status=0
for profile in "${profiles[@]}"; do
    echo ""
    echo "==> WordPress $WP_VERSION, integration suite, profile=$profile"
    if ! WPCF_TEST_PROFILE="$profile" \
        "$ROOT/tests/tools/vendor/bin/phpunit" \
        -c "$ROOT/tests/phpunit-integration.xml"; then
        status=1
    fi
done

exit $status
