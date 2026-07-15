<?php

declare(strict_types=1);

namespace App\Services\Signing;

use LogicException;
use WeakMap;

/**
 * Keeps sensitive values (CMS DER bytes and local filesystem paths) OUT of an
 * object's instance state so they cannot leak through var_export(), print_r(),
 * var_dump(), or serialize(). The values live in a per-class private static
 * WeakMap keyed by the object itself; they are reachable only through the class's
 * explicit accessors and are garbage-collected with the object (weak keys).
 *
 * Serialization is refused with a neutral exception (no sensitive data in the
 * message), unserialization fails closed, and cloning is prohibited because it
 * would detach the object from its sidecar entry.
 */
trait ProtectsSensitiveState
{
    /** @var WeakMap<object, array<string, mixed>>|null */
    private static ?WeakMap $sensitiveState = null;

    /**
     * @param  array<string, mixed>  $values
     */
    private function storeSensitive(array $values): void
    {
        self::$sensitiveState ??= new WeakMap;
        self::$sensitiveState[$this] = $values;
    }

    private function sensitive(string $key): mixed
    {
        $bag = self::$sensitiveState[$this] ?? null;

        if ($bag === null || ! array_key_exists($key, $bag)) {
            // Object without its sidecar (e.g. built by reflection/unserialize).
            throw new LogicException('Sensitive signing state is unavailable.');
        }

        return $bag[$key];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('Signing value objects cannot be serialized.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Signing value objects cannot be unserialized.');
    }

    public function __clone(): void
    {
        throw new LogicException('Signing value objects cannot be cloned.');
    }
}
