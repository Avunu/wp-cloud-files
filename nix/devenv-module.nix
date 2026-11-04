# Shared devenv environment, parameterised by whether it is running in CI.
#
# CI drops the web server (nothing serves HTTP there, and caddy's root points at
# a composer-installed path that CI never populates) but keeps MariaDB and the
# rclone-backed S3 test server, so the integration suite runs against exactly the
# same services locally and in GitHub Actions.
{ forCI ? false }:

{
  pkgs,
  lib,
  config,
  ...
}:

let
  testBucket = "wp-cloud-files-test";

  # Throwaway credentials for a loopback-only test server.
  s3Key = "wpcftestkey";
  s3Secret = "wpcftestsecret";
  s3Region = "us-east-1";

  s3Port = config.processes.s3.ports.api.value;
  s3PublicPort = config.processes.s3-public.ports.http.value;
in
{
  packages = with pkgs; [
    wp-cli
    # ImageMagick's pdf/ps delegates shell out to the bare command `gs`, resolved
    # from $PATH at runtime, so Ghostscript on PATH is all that is needed for PDF
    # rasterization -- no imagemagick rebuild with ghostscriptSupport.
    ghostscript
    jq
    curl
    gnutar
    gzip
  ];

  # Use `version`, not `package`: devenv only applies `extensions` and the
  # auto-generated mysqli/pdo_mysql socket ini through configurePackage, which is
  # the *default value* of `package`. Setting `package` directly discards both.
  languages.php = {
    enable = true;
    version = "8.3";
    extensions = [
      "mysqli"
      "pdo_mysql"
      "gd"
      "intl"
      "zip"
      "openssl"
      "imagick"
    ];
    ini = ''
      memory_limit = 512M
    '';
    fpm.pools = lib.mkIf (!forCI) {
      php.settings = {
        "pm" = "dynamic";
        "pm.max_children" = 5;
        "pm.start_servers" = 2;
        "pm.min_spare_servers" = 1;
        "pm.max_spare_servers" = 3;
      };
    };
  };

  # Node is only ever used to type-check the browser JS (there is no build step),
  # but pinning it here means `check:types` runs against the same toolchain
  # locally and in CI rather than whatever node happens to be on $PATH.
  languages.javascript = {
    enable = true;
    npm.enable = true;
    # The language server is an editor aid; the CI shell does not need it, and its
    # pnpm-deps fixed-output hash in nixpkgs no longer reproduces from source.
    lsp.enable = !forCI;
  };

  services.mysql = {
    enable = true;
    package = pkgs.mariadb;
    initialDatabases = [
      { name = "wordpress_dev"; }
      # The WordPress test suite drops and recreates every table on each run, so
      # it must never share a database with the dev site.
      { name = "wordpress_test"; }
    ];
  };

  # Under process-compose, a process whose `ready` config has no `timeout` is
  # handed to process-compose's own policy (supervisionMode = "external"),
  # and devenv then reports it Ready the instant it is spawned without ever
  # running the exec probe -- so devenv:mysql:configure (which creates
  # initialDatabases) races mariadbd's actual startup instead of waiting on
  # it. Setting `timeout` keeps devenv supervising mysql itself, so the probe
  # actually runs and the databases get created after mariadbd is really up.
  processes.mysql.ready.timeout = 60;

  # The S3 test server is rclone, not MinIO: upstream abandoned MinIO and nixpkgs
  # has removed it (and marked its client insecure first). Two processes share one
  # data directory, because rclone cannot do both jobs in a single listener:
  #
  #   s3         `rclone serve s3` WITH --auth-key, so SigV4 is genuinely verified
  #              (the presigned-upload tests mean nothing against a server that
  #              accepts any signature). With auth on it refuses anonymous reads.
  #   s3-public  `rclone serve http`, read-only, over the same directory. This is
  #              the anonymous public URL UrlRewriterEndToEndTest fetches. The
  #              plugin already treats S3_PUBLIC_URL as separate from S3_ENDPOINT
  #              (in production it is a CDN), so nothing about the tests changes.
  #              --dir-cache-time 0 is load-bearing: objects are written by the
  #              *other* rclone process, so the default 5 minute directory cache
  #              would 404 every object uploaded after the first listing.
  #
  # `rclone serve s3` exposes top-level directories as buckets, so the bucket is a
  # plain mkdir before the server starts. There is no client, no provisioning step
  # and no readiness race.
  processes.s3 = {
    ports.api.allocate = 9000;
    exec = ''
      mkdir -p "$DEVENV_STATE/s3/data/${testBucket}"
      exec ${pkgs.rclone}/bin/rclone serve s3 "$DEVENV_STATE/s3/data" \
        --addr 127.0.0.1:${toString s3Port} \
        --auth-key ${s3Key},${s3Secret} \
        --no-cleanup
    '';
    # A signed listing of the bucket proves the listener is up, auth works and the
    # bucket exists. (An unsigned GET is answered 400, so a plain http probe would
    # mis-report.) Setting `timeout` keeps devenv supervising the probe -- see the
    # note on processes.mysql.ready.timeout above.
    ready = {
      exec = lib.concatStringsSep " " [
        "${pkgs.curl}/bin/curl -sf -o /dev/null"
        "--aws-sigv4 aws:amz:${s3Region}:s3"
        "--user ${s3Key}:${s3Secret}"
        "http://127.0.0.1:${toString s3Port}/${testBucket}"
      ];
      initial_delay = 1;
      period = 1;
      timeout = 120;
    };
  };

  processes.s3-public = {
    ports.http.allocate = 9010;
    exec = ''
      mkdir -p "$DEVENV_STATE/s3/data/${testBucket}"
      exec ${pkgs.rclone}/bin/rclone serve http "$DEVENV_STATE/s3/data" \
        --addr 127.0.0.1:${toString s3PublicPort} \
        --read-only \
        --dir-cache-time 0
    '';
    ready = {
      http.get = {
        host = "127.0.0.1";
        port = s3PublicPort;
        path = "/${testBucket}/";
      };
      initial_delay = 1;
      period = 1;
      timeout = 120;
    };
  };

  services.caddy = lib.mkIf (!forCI) {
    enable = true;
    config = ''
      http://localhost:8002 {
        root * ./vendor/johnpbloch/wordpress-core
        php_fastcgi unix/${config.languages.php.fpm.pools.php.socket}
        file_server
      }
    '';
  };

  # The PHPUnit bootstrap reads all of these from the environment and never from
  # a devenv path. That is what keeps the fallback to GitHub Actions `services:`
  # containers a workflow-file edit rather than a rewrite.
  env = {
    WP_CLI = "${pkgs.wp-cli}/bin/wp";

    WP_TESTS_DB_NAME = "wordpress_test";
    WP_TESTS_DB_USER = "root";
    WP_TESTS_DB_PASS = "";
    WP_TESTS_DB_HOST = "localhost";

    WPCF_TEST_BUCKET = testBucket;
    S3_TEST_ENDPOINT = "http://127.0.0.1:${toString s3Port}";
    S3_TEST_PUBLIC_URL = "http://127.0.0.1:${toString s3PublicPort}/${testBucket}";
    S3_TEST_KEY = s3Key;
    S3_TEST_SECRET = s3Secret;
    S3_TEST_REGION = s3Region;
  };

  scripts = {
    setup.exec = ''
        echo "🚀 Setting up WordPress development environment..."

      # Install composer deps — places WordPress core at vendor/johnpbloch/wordpress-core
      composer install --no-interaction

      # Configure WordPress
      if [ ! -f vendor/johnpbloch/wordpress-core/wp-config.php ]; then
        echo "📝 Creating wp-config.php..."
        wp config create \
          --path=vendor/johnpbloch/wordpress-core \
          --dbname=wordpress_dev \
          --dbuser=root \
          --dbhost=localhost \
          --extra-php <<PHP
          define( 'WP_HOME', 'http://localhost:8002' );
          define( 'WP_SITEURL', 'http://localhost:8002' );
          PHP
      fi

      # Symlink plugin into WordPress
      mkdir -p vendor/johnpbloch/wordpress-core/wp-content/plugins
      echo "🔗 Symlinking WP Cloud Files plugin..."
      ln -sfn "$(pwd)" vendor/johnpbloch/wordpress-core/wp-content/plugins/wp-cloud-files

      echo "✅ Setup complete! Run 'devenv up' to start services."
    '';

    setup-tests.exec = ''
      set -euo pipefail
      cd "$DEVENV_ROOT"

      echo "📦 Installing plugin dependencies..."
      composer install --no-interaction

      echo "📦 Installing test toolchain..."
      composer install --no-interaction --working-dir=tests/tools

      # DocumentThumbnailer resolves DomPDF relative to WP_PLUGIN_DIR and a
      # directory literally named wp-cloud-files, so the unit/thumbnail suites
      # need that layout even though they never load WordPress.
      mkdir -p tests/.plugin-dir
      ln -sfn "$DEVENV_ROOT" tests/.plugin-dir/wp-cloud-files

      mkdir -p "$DEVENV_STATE/test-tmp"
      echo "✅ Test harness ready."
    '';

    test-unit.exec = ''
      set -euo pipefail
      cd "$DEVENV_ROOT"
      export TMPDIR="$DEVENV_STATE/test-tmp"
      mkdir -p "$TMPDIR"

      for profile in default root maxsize; do
        echo "==> unit suite, profile=$profile"
        WPCF_TEST_PROFILE="$profile" \
          tests/tools/vendor/bin/phpunit -c tests/phpunit-unit.xml --testsuite=unit
      done
    '';

    test-thumbs.exec = ''
      set -euo pipefail
      cd "$DEVENV_ROOT"
      export TMPDIR="$DEVENV_STATE/test-tmp"
      mkdir -p "$TMPDIR"
      tests/tools/vendor/bin/phpunit -c tests/phpunit-unit.xml --testsuite=thumbnails
    '';

    test-wp.exec = ''
      set -euo pipefail
      exec "$DEVENV_ROOT/tests/bin/run-integration.sh" "$@"
    '';

    upgrade-deps.exec = ''
      echo "🔄 Upgrading composer dependencies..."
      composer update --no-interaction
      echo "🔄 Upgrading test toolchain..."
      composer update --no-interaction --working-dir=tests/tools
      echo "✅ Dependencies upgraded."
      echo "🔄 Upgrading npm dependencies..."
      npm run upgrade
      echo "✅ npm dependencies upgraded."
      echo "🔄 Upgrading nix dependencies..."
      nix flake update
      echo "✅ nix dependencies upgraded."
    '';
  };

  # In flakes mode `devenv test` is just `exec ${config.test}` -- unlike the real
  # CLI it does NOT start processes first. So start them here, and make sure they
  # are torn down even when the suite fails.
  enterTest = lib.mkIf forCI ''
    cd "$DEVENV_ROOT"

    PC_TUI_ENABLED=false ${config.procfileScript} &
    devenvUpPid=$!
    trap 'kill -TERM "$devenvUpPid" 2>/dev/null || true; wait "$devenvUpPid" 2>/dev/null || true' EXIT

    wait_for_processes 180

    # A repo script, not inline Nix, so the suite list can change without a
    # devenv rebuild. WPCF_SUITES narrows it when iterating.
    #
    # Deliberately not `exec`: that would replace this shell and discard the EXIT
    # trap above, leaving process-compose (and MariaDB and the S3 servers) running after
    # the suite finishes.
    testStatus=0
    ./tests/bin/run-all.sh || testStatus=$?
    exit "$testStatus"
  '';
}
