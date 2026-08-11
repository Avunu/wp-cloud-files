<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Support;

/**
 * has_filter($tag) returns a bool, and has_filter($tag, $callback) needs the
 * exact callable -- which is impossible here because Plugin::registerHooks()
 * constructs its handlers inline and never exposes them. Inspecting $wp_filter
 * directly is the only way to assert the priority a class is bound at, and the
 * priorities are load-bearing (MediaHandler must run at 999).
 */
trait HookAssertions
{
    /**
     * @return list<int> every priority the given class::method is bound at
     */
    protected function hookPriorities(string $hook, string $class, string $method): array
    {
        global $wp_filter;

        if (!isset($wp_filter[$hook])) {
            return [];
        }

        $found = [];

        foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $registered) {
                $callback = $registered['function'];

                if (!is_array($callback) || !is_object($callback[0])) {
                    continue;
                }

                if ($callback[0] instanceof $class && $callback[1] === $method) {
                    $found[] = (int) $priority;
                }
            }
        }

        return $found;
    }

    protected function assertHookedAt(int $priority, string $hook, string $class, string $method, string $why = ''): void
    {
        $priorities = $this->hookPriorities($hook, $class, $method);

        $this->assertContains(
            $priority,
            $priorities,
            $why !== ''
                ? $why
                : sprintf(
                    '%s::%s should be bound to "%s" at priority %d (found: %s)',
                    $class,
                    $method,
                    $hook,
                    $priority,
                    $priorities === [] ? 'not bound at all' : implode(', ', $priorities)
                )
        );
    }
}
