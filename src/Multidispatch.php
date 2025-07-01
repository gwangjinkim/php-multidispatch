<?php

namespace Multidispatch;

use ArrayAccess;
use Exception;

class Multidispatch implements ArrayAccess
{
    private array $methods = [];

    public function __invoke(...$args)
    {
        if (is_array($args[0] ?? null) && is_callable($args[1] ?? null)) {
            return $this->offsetSet($args[0], $args[1]);
        }

        $types = array_map([$this, 'getTypeName'], $args);
        $method = $this->resolve($types);
        if (!$method) {
            throw new Exception("No method for types: " . implode(', ', $types));
        }
        return $method(...$args);
    }

    public function offsetSet($offset, $value): void
    {
        $key = implode(',', $offset);
        $this->methods[$key] = $value;
    }

    public function offsetExists($offset): bool
    {
        $key = implode(',', $offset);
        return isset($this->methods[$key]);
    }

    public function offsetGet($offset): callable
    {
        $key = implode(',', $offset);
        return $this->methods[$key] ?? throw new Exception("No method for $key");
    }

    public function offsetUnset($offset): void
    {
        $key = implode(',', $offset);
        unset($this->methods[$key]);
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

    private function resolve(array $types): ?callable
    {
        $chains = array_map([$this, 'getChain'], $types);
        foreach ($this->generateCombinations($chains) as $combo) {
            $key = implode(',', $combo);
            if (isset($this->methods[$key])) {
                return $this->methods[$key];
            }
        }

        $defaultKey = implode(',', array_fill(0, count($types), '*'));
        return $this->methods[$defaultKey] ?? null;
    }

    private function getChain(string $type): array
    {
        if ($type === '*') return ['*'];
        if (class_exists($type)) {
            return array_unique([
                $type,
                ...class_parents($type),
                ...class_implements($type),
                '*'
            ]);
        }
        return [$type, '*'];
    }

    private function generateCombinations(array $chains): array
    {
        if (empty($chains)) return [[]];
        $rest = $this->generateCombinations(array_slice($chains, 1));
        $result = [];
        foreach ($chains[0] as $type) {
            foreach ($rest as $r) {
                $result[] = array_merge([$type], $r);
            }
        }
        return $result;
    }
}
