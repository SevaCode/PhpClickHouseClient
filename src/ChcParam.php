<?php

declare(strict_types=1);

namespace SevaCode\ClickHouseClient;

/**
 * A typed query parameter whose name and type render a ClickHouse placeholder.
 *
 * Only the name and type are included in SQL; the value is sent separately as
 * the `param_<name>` HTTP parameter.
 */
final class ChcParam implements \Stringable
{
    private static int $counter = 0;

    public readonly mixed $value;
    public readonly ChcType $type;
    public readonly string $name;

    public function __construct(mixed $value, ChcType|string $type, ?string $name = null)
    {
        $this->value = $value;
        $this->type = is_string($type) ? ChcType::of($type) : $type;
        $this->name = $name ?? 'chc_' . ++self::$counter;

        if (!ChcParamEncoder::isValidParameterName($this->name)) {
            throw new \InvalidArgumentException('Invalid query parameter name: ' . var_export($this->name, true));
        }
    }

    public static function of(mixed $value, ChcType|string $type, ?string $name = null): self
    {
        return new self($value, $type, $name);
    }

    public function __toString(): string
    {
        return '{' . $this->name . ':' . $this->type . '}';
    }
}
