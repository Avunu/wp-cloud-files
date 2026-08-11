<?php

/**
 * Test double for WP-CLI.
 *
 * src/CLI.php is the highest-consequence untested code in the plugin -- migrate()
 * deletes local originals after upload -- but WP-CLI is not available inside the
 * WordPress PHPUnit suite. This declares just enough of it to drive the commands
 * and assert on what they reported.
 *
 * Deliberately does NOT define the WP_CLI *constant*: index.php gates
 * add_command() on it, and defining it would change plugin bootstrap for every
 * other integration test. CLI tests instantiate the command class directly.
 *
 * Not PSR-4 loadable (global class name), so it is required explicitly from
 * tests/bootstrap/wp.php.
 */

declare(strict_types=1);

namespace {
    if (!class_exists('WP_CLI', false)) {
        /**
         * Thrown by WP_CLI::error(), mirroring the real client's ExitException.
         */
        class WpCliHalt extends \RuntimeException
        {
        }

        class WP_CLI
        {
            /** @var array<string, list<string>> */
            public static array $messages = [
                'log' => [],
                'debug' => [],
                'warning' => [],
                'success' => [],
                'error' => [],
            ];

            /** @var list<array{name: string, callable: mixed}> */
            public static array $commands = [];

            public static function reset(): void
            {
                self::$messages = [
                    'log' => [],
                    'debug' => [],
                    'warning' => [],
                    'success' => [],
                    'error' => [],
                ];
                self::$commands = [];
            }

            public static function log(string $message): void
            {
                self::$messages['log'][] = $message;
            }

            public static function debug(string $message, string $group = ''): void
            {
                self::$messages['debug'][] = $message;
            }

            public static function warning(string $message): void
            {
                self::$messages['warning'][] = $message;
            }

            public static function success(string $message): void
            {
                self::$messages['success'][] = $message;
            }

            /** Real WP-CLI aborts here, so the double does too. */
            public static function error(string $message, bool $exit = true): void
            {
                self::$messages['error'][] = $message;

                if ($exit) {
                    throw new WpCliHalt($message);
                }
            }

            public static function confirm(string $question, array $assoc_args = []): void
            {
            }

            /** @param mixed $callable */
            public static function add_command(string $name, $callable, array $args = []): void
            {
                self::$commands[] = ['name' => $name, 'callable' => $callable];
            }

            /** @return mixed */
            public static function runcommand(string $command, array $options = [])
            {
                self::$messages['log'][] = 'runcommand: ' . $command;

                return null;
            }
        }
    }
}

namespace cli\progress {
    if (!class_exists('cli\progress\Bar', false)) {
        /**
         * Utils\make_progress_bar() returns one of these; the commands only ever
         * call tick() and finish() on it.
         */
        class Bar
        {
            public int $ticks = 0;
            public bool $finished = false;

            public function tick(int $increment = 1, string $msg = ''): void
            {
                $this->ticks += $increment;
            }

            public function finish(): bool
            {
                $this->finished = true;

                return true;
            }
        }
    }
}

namespace WP_CLI\Utils {
    if (!function_exists('WP_CLI\Utils\make_progress_bar')) {
        /**
         * @return \cli\progress\Bar
         */
        function make_progress_bar(string $message, int $count, int $interval = 100)
        {
            return new \cli\progress\Bar();
        }
    }

    if (!function_exists('WP_CLI\Utils\get_flag_value')) {
        /**
         * @param mixed $default
         * @return mixed
         */
        function get_flag_value(array $assoc_args, string $flag, $default = null)
        {
            return $assoc_args[$flag] ?? $default;
        }
    }
}
