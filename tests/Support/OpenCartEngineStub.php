<?php

declare(strict_types=1);

/**
 * Minimal OpenCart engine stubs for reflecting/invoking catalog event controllers in PHPUnit.
 * Does not redefine MtUniCredit library classes.
 */

namespace Opencart\System\Engine {

    if (!class_exists(Registry::class, false)) {
        class Registry
        {
            public function get(string $key): ?object
            {
                return null;
            }

            public function set(string $key, object $value): void {}

            public function has(string $key): bool
            {
                return false;
            }
        }
    }

    if (!class_exists(Controller::class, false)) {
        class Controller
        {
            protected Registry $registry;

            public function __construct(Registry $registry)
            {
                $this->registry = $registry;
            }

            public function __get(string $key): object
            {
                return new \stdClass();
            }

            public function __set(string $key, object $value): void {}
        }
    }
}
