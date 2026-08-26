<?php

declare(strict_types=1);

namespace Konventix\Platform\Permissions;

use BackedEnum;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One default role a module ships (ADR-0045): a stable key under the
 * module's own prefix, a display name, and the module's own capabilities.
 * `everything` is Platform's "Brand Admin" — expanded against the whole
 * catalogue at materialization time, because Platform may not name another
 * module's enum (ADR-0013) but it does own the catalogue.
 */
final readonly class DefaultRole
{
    /**
     * @param  list<string>  $permissions
     */
    private function __construct(
        public string $key,
        public string $name,
        public array $permissions,
        public bool $everything,
    ) {
        if (! preg_match('/^[a-z][a-z0-9-]*\.[a-z][a-z0-9-]*$/', $key)) {
            throw new InvalidArgumentException("Default role key [{$key}] must be `<module>.<slug>`.");
        }
    }

    public static function of(string $key, string $name, BackedEnum ...$permissions): self
    {
        $values = array_values(array_unique(array_map(
            fn (BackedEnum $permission): string => (string) $permission->value,
            $permissions,
        )));

        foreach ($values as $value) {
            if (Str::before($value, '.') !== Str::before($key, '.')) {
                throw new InvalidArgumentException(
                    "Default role [{$key}] may only grant its own module's capabilities; [{$value}] is not one (ADR-0013)."
                );
            }
        }

        return new self($key, $name, $values, everything: false);
    }

    public static function everything(string $key, string $name): self
    {
        return new self($key, $name, [], everything: true);
    }

    public function module(): string
    {
        return Str::before($this->key, '.');
    }
}
