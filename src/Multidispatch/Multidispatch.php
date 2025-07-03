<?php

namespace Multidispatch;

use ArrayAccess;
use Exception;

class Multidispatch implements ArrayAccess
{
    private array $methods = [];

    public function __invoke(...$args)
    {
        // Try CLOS first
        foreach ([':around', ':before', ':primary', ':after'] as $kind) {
            $types = array_map([$this, 'getTypeName'], $args);
            $key = $this->buildCLOSKey($types, $kind);
            if (isset($this->methods[$key])) {
                if ($kind === ':around') {
                    // Provide $callNext as the first argument
                    $callNext = $this->makeCallNext($types, 0, $args);
                    return ($this->methods[$key])($callNext, ...$args);
                }
                return ($this->methods[$key])(...$args);
            }
        }
        // Classic fallback
        $types = array_map([$this, 'getTypeName'], $args);
        $key = $this->buildKey($types);
        if (isset($this->methods[$key])) {
            return ($this->methods[$key])(...$args);
        }
        // Default fallback
        $defaultKey = $this->buildKey(array_fill(0, count($args), '*'));
        if (isset($this->methods[$defaultKey])) {
            return ($this->methods[$defaultKey])(...$args);
        }
        throw new Exception("No method for types: " . implode(', ', $types));
    }

    // Support both classic and CLOS keys for all operations
    public function offsetSet($offset, $value): void
    {
        if ($this->isCLOSKey($offset)) {
            [$types, $kind] = $offset;
            $key = $this->buildCLOSKey($types, $kind);
            $this->methods[$key] = $value;
        } else {
            $key = $this->buildKey($offset);
            $this->methods[$key] = $value;
        }
    }

    public function offsetExists($offset): bool
    {
        if ($this->isCLOSKey($offset)) {
            [$types, $kind] = $offset;
            $key = $this->buildCLOSKey($types, $kind);
            return isset($this->methods[$key]);
        } else {
            $key = $this->buildKey($offset);
            return isset($this->methods[$key]);
        }
    }

    public function offsetGet($offset): callable
    {
        if ($this->isCLOSKey($offset)) {
            [$types, $kind] = $offset;
            $key = $this->buildCLOSKey($types, $kind);
            return $this->methods[$key] ?? throw new Exception("No method for $key");
        } else {
            $key = $this->buildKey($offset);
            return $this->methods[$key] ?? throw new Exception("No method for $key");
        }
    }

    public function offsetUnset($offset): void
    {
        if ($this->isCLOSKey($offset)) {
            [$types, $kind] = $offset;
            $key = $this->buildCLOSKey($types, $kind);
            unset($this->methods[$key]);
        } else {
            $key = $this->buildKey($offset);
            unset($this->methods[$key]);
        }
    }

    private function isCLOSKey($offset): bool
    {
        return is_array($offset) && count($offset) === 2 && is_array($offset[0]) && is_string($offset[1]) && str_starts_with($offset[1], ':');
    }

    private function buildCLOSKey(array $types, string $kind): string
    {
        return $kind . ':' . implode(',', $types);
    }

    private function buildKey(array $types): string
    {
        return implode(',', $types);
    }

    private function getTypeName($arg): string
    {
        if (is_object($arg)) return ltrim(get_class($arg), '\\');
        $type = gettype($arg);
        return match ($type) {
            'integer' => 'int',
            'double'  => 'float',
            'boolean' => 'bool',
            'string', 'array', 'resource', 'NULL' => $type,
            default => '*'
        };
    }

    // CLOS call-next-method for :around
    private function makeCallNext(array $types, int $aroundIndex, array $args)
    {
        $aroundKinds = $this->getAroundKinds($types);
        $index = $aroundIndex + 1;
        $self = $this;
        return function (...$args) use ($types, $aroundKinds, $index, $self) {
            if (isset($aroundKinds[$index])) {
                $method = $self->methods[$aroundKinds[$index]];
                $callNext = $self->makeCallNext($types, $index, $args);
                return $method($callNext, ...$args);
            } else {
                // Run before -> primary -> after
                $beforeKey = $self->buildCLOSKey($types, ':before');
                $primaryKey = $self->buildCLOSKey($types, ':primary');
                $afterKey = $self->buildCLOSKey($types, ':after');
                if (isset($self->methods[$beforeKey])) $self->methods[$beforeKey](...$args);
                $result = isset($self->methods[$primaryKey]) ? $self->methods[$primaryKey](...$args) : null;
                if (isset($self->methods[$afterKey])) $self->methods[$afterKey](...$args);
                return $result;
            }
        };
    }

    private function getAroundKinds(array $types): array
    {
        $kinds = [];
        foreach ($this->methods as $key => $_) {
            if (str_starts_with($key, ':around:')) {
                $suffix = substr($key, strlen(':around:'));
                $base = implode(',', $types);
                if ($suffix === $base) $kinds[] = $key;
            }
        }
        sort($kinds); // ensure consistent order
        return $kinds;
    }
}

// Helper function (global, as before)
function multidispatch(): Multidispatch {
    return new Multidispatch();
}
