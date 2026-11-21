#!/usr/bin/env bash
#
# Run the WordPress integration suite against a given WordPress version.
#
#   WP_VERSION=7.0 tests/bin/run-integration.sh
#   WP_VERSION=trunk tests/bin/run-integration.sh
#
# Expects MariaDB and the S3 servers to already be running (devenv up, or enterTest).
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
# directory name.                                                     #
# ------------------------------------------------------------------ #
classic_content_dir="$STATE/wp-test/wp-content"
mkdir -p "$classic_content_dir/plugins" "$classic_content_dir/uploads"
ln -sfn "$ROOT" "$classic_content_dir/plugins/wp-cloud-files"

# ------------------------------------------------------------------ #
# The Bedrock fixture: content dir "app", and the plugin as Composer  #
# installs it -- its own files, no vendor/ of its own -- while the     #
# project autoloader (here, the repo's vendor/) supplies every        #
# dependency. Rebuilt on every run so it never goes stale.            #
# ------------------------------------------------------------------ #
bedrock_content_dir="$STATE/wp-test-bedrock/app"
bedrock_plugin_dir="$bedrock_content_dir/plugins/wp-cloud-files"
rm -rf "$bedrock_plugin_dir"
mkdir -p "$bedrock_plugin_dir" "$bedrock_content_dir/uploads"
cp -r "$ROOT/index.php" "$ROOT/composer.json" "$ROOT/src" "$ROOT/assets" "$bedrock_plugin_dir/"

export TMPDIR="$STATE/test-tmp"
mkdir -p "$TMPDIR"

export WP_TESTS_CONFIG_FILE_PATH="$ROOT/tests/bootstrap/wp-tests-config.php"

# ------------------------------------------------------------------ #
# Run once per configuration profile: the plugin reads its settings   #
# from immutable global constants, so they cannot vary in-process.    #
# ------------------------------------------------------------------ #
profiles=("${@:-default root broken-s3 bedrock}")
# shellcheck disable=SC2206
profiles=(${profiles[*]})

status=0
for profile in "${profiles[@]}"; do
    echo ""
    echo "==> WordPress $WP_VERSION, integration suite, profile=$profile"

    profile_env=()
    if [ "$profile" = bedrock ]; then
        # Configuration through the environment only: wp-tests-config.php
        # defines no S3_* constants for this profile.
        profile_env=(
            WPCF_CONTENT_DIR="$bedrock_content_dir"
            WPCF_PLUGIN_DIR="$bedrock_plugin_dir"
            S3_KEY="$S3_TEST_KEY"
            S3_SECRET="$S3_TEST_SECRET"
            S3_BUCKET="${WPCF_TEST_BUCKET:-wp-cloud-files-test}"
            S3_ENDPOINT="$S3_TEST_ENDPOINT"
            S3_PUBLIC_URL="$S3_TEST_PUBLIC_URL"
            S3_REGION="${S3_TEST_REGION:-us-east-1}"
            S3_PATH_STYLE=true
            S3_DIRECT_UPLOADS=true
        )
    else
        profile_env=(
            WPCF_CONTENT_DIR="$classic_content_dir"
            WPCF_PLUGIN_DIR="$ROOT"
        )
    fi

    if ! env "${profile_env[@]}" WPCF_TEST_PROFILE="$profile" \
        "$ROOT/tests/tools/vendor/bin/phpunit" \
        -c "$ROOT/tests/phpunit-integration.xml"; then
        status=1
    fi
done

exit $status
