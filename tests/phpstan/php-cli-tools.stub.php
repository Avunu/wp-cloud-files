<?php

/**
 * Minimal stubs for wp-cli/php-cli-tools.
 *
 * WP_CLI\Utils\make_progress_bar() returns cli\progress\Bar, but php-stubs/wp-cli-stubs
 * only covers the WP_CLI namespace -- the cli\ namespace lives in a separate
 * upstream package. src/CLI.php calls tick() and finish() on the result, so
 * without these declarations every progress-bar call is an unknown-class error.
 *
 * Declarations only; never loaded at runtime.
 */

declare(strict_types=1);

namespace cli\progress;

class Bar
{
    public function __construct(string $msg, int $total, int $interval = 100) {}

    public function tick(int $increment = 1, string $msg = ''): void {}

    public function finish(): bool {}

    public function display(bool $finish = false): void {}
}
