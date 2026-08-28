<?php

declare(strict_types=1);

namespace MtUniCredit\Tests\Support;

/**
 * Characterization of OpenCart 4.1.0.3 Action route parsing.
 * This is not a production compatibility layer.
 */
final class OpenCart4103ActionParser
{
    /**
     * Mirrors system/engine/action.php in OpenCart 4.1.0.3:
     * method split is the last `.` only. `|` is not a method separator.
     *
     * @return array{controller: string, method: string}
     */
    public static function parse(string $route): array
    {
        $pos = strrpos($route, '.');
        if ($pos !== false) {
            return [
                'controller' => substr($route, 0, $pos),
                'method' => substr($route, $pos + 1),
            ];
        }

        return [
            'controller' => $route,
            'method' => 'index',
        ];
    }
}
