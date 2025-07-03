<?php

namespace Multidispatch;

use ArrayAccess;
use Exception;

/**
 * Multiple dispatch with CLOS-style method combination.
 *
 * Usage: See README or example.php for detailed explanation.
 */
class Multidispatch implements ArrayAccess
{
    // Map: $methods['signature'] = ['kind' => callable, ...]
    private array $methods = [];

    /**
     * Register a handler for a type signature and kind (:primary, :before, :after, :around).
     */
    public function offsetSet($offset, $value): void
    {
        // Support both [type1, type2, ...] and [type1, type2, ...], ':kind'
        if (is_array($offset) && isset($offset[0]) && is_string($offset[0]) && isset($offset[1]) && $offset[1][0] === ':') {
            $sig = $offset[0];
            $kind = $offset[1];
        } elseif (is_array($offset) && count($offset) > 0) {
            $sig = $offset;
            $kind = ':primary';
        } else {
            throw new Exception("Invalid signature for multidispatch registration.");
        }
        $key = $this->keyFromTypes($sig);
        $this->methods[$key][$kind] = $value;
    }

    public function offsetExists($offset): bool
    {
        $key = $this->keyFromTypes($offset);
        return isset($this->methods[$key]);
    }

    public function offsetGet($offset): mixed
    {
        $key = $this->keyFromTypes($offset);
        return $this->methods[$key] ?? throw new Exception("No method for $key");
    }

    public function offsetUnset($offset): void
    {
        $key = $this->keyFromTypes($offset);
        unset($this->methods[$key]);
    }

    /**
     * Dispatch: main entry.
     */
    public function __invoke(...$args)
    {
        // Compose and resolve all applicable method combinations
        [$candidates, $resolvedTypes] = $this->resolve($args);

        // Compose stack of :around, run chain (innermost runs all :before, :primary, :after)
        $core = function (...$a) use ($candidates) {
            foreach ($candidates['before'] as $before) { $before(...$a); }
            $result = null;
            if (isset($candidates['primary'])) {
                $result = $candidates['primary'](...$a);
            } else {
                throw new Exception("No :primary method for types: " . implode(', ', array_map([$this, 'getTypeName'], $a)));
            }
            foreach ($candidates['after'] as $after) { $after(...$a); }
            return $result;
        };

        // Compose :around stack, from most to least specific (innermost is primary/after/before chain)
        $aroundStack = $candidates['around'];
        $wrapped = array_reduce(
            array_reverse($aroundStack),
            function ($next, $around) {
                return function (...$args) use ($around, $next) {
                    // Call $around($callNext, ...$args)
                    return $around($next, ...$args);
                };
            },
            $core
        );

        return $wrapped(...$args);
    }

    // === Internals ===

    /**
     * Return all candidate methods for given arguments.
     * @return [candidates, resolvedTypes]
     */
    private function resolve(array $args): array
    {
        // Build all type chains for each argument
        $typeChains = array_map([$this, 'allTypesForArg'], $args);

        // Compose all possible combinations (most specific first)
        $combos = $this->cartesian($typeChains);

        $found = false;
        $candidates = [
            'before' => [],
            'primary' => null,
            'after' => [],
            'around' => [],
        ];
        $matchedTypes = null;

        // Try each combo for :around, :before, :primary, :after
        foreach ($combos as $combo) {
            $key = $this->keyFromTypes($combo);
            if (isset($this->methods[$key])) {
                $methods = $this->methods[$key];
                if (isset($methods[':around'])) $candidates['around'][] = $methods[':around'];
                if (isset($methods[':before'])) $candidates['before'][] = $methods[':before'];
                if (isset($methods[':primary']) && !$candidates['primary']) $candidates['primary'] = $methods[':primary'];
                if (isset($methods[':after'])) $candidates['after'][] = $methods[':after'];
                if (!$found) {
                    $matchedTypes = $combo;
                    $found = true;
                }
            }
        }

        // If nothing found, try default ('*' for all args)
        if (!$found) {
            $defaultKey = $this->keyFromTypes(array_fill(0, count($args), '*'));
            if (isset($this->methods[$defaultKey])) {
                $methods = $this->methods[$defaultKey];
                if (isset($methods[':around'])) $candidates['around'][] = $methods[':around'];
                if (isset($methods[':before'])) $candidates['before'][] = $methods[':before'];
                if (isset($methods[':primary'])) $candidates['primary'] = $methods[':primary'];
                if (isset($methods[':after'])) $candidates['after'][] = $methods[':after'];
                $matchedTypes = array_fill(0, count($args), '*');
                $found = true;
            }
        }

        if (!$found) {
            throw new Exception("No method for types: " . implode(', ', array_map([$this, 'getTypeName'], $args)));
        }

        // Reverse :before (least to most specific), :after (most to least specific)
        $candidates['before'] = array_reverse($candidates['before']);
        // :after run most-specific first, as per CLOS
        // $candidates['after'] = array_reverse($candidates['after']);
        // Actually, most-specific :after runs first, then less specific
        // (already in that order)

        return [$candidates, $matchedTypes];
    }

    /**
     * Get all types for argument: class, parents, interfaces, built-in type, '*'
     */
    private function allTypesForArg($arg): array
    {
        $types = [];

        if (is_object($arg)) {
            $class = ltrim(get_class($arg), '\\');
            $types[] = $class;

            // All parent classes
            foreach (class_parents($arg) as $parent) {
                $types[] = ltrim($parent, '\\');
            }
            // All interfaces (in order declared)
            foreach (class_implements($arg) as $iface) {
                $types[] = ltrim($iface, '\\');
            }
        } else {
            $type = gettype($arg);
            switch ($type) {
                case 'integer': $types[] = 'int'; break;
                case 'double':  $types[] = 'float'; break;
                case 'boolean': $types[] = 'bool'; break;
                case 'string':
                case 'array':
                case 'resource':
                case 'NULL':
                    $types[] = $type; break;
                default: $types[] = '*'; break;
            }
        }
        $types[] = '*';
        return $types;
    }

    /**
     * Cartesian product for type combos, most specific first.
     */
    private function cartesian($arrays)
    {
        // Recursively build all combos (most specific first)
        $result = [[]];
        foreach ($arrays as $property) {
            $tmp = [];
            foreach ($result as $resultItem) {
                foreach ($property as $item) {
                    $tmp[] = array_merge($resultItem, [$item]);
                }
            }
            $result = $tmp;
        }
        return $result;
    }

    /**
     * Turn type signature into key.
     */
    private function keyFromTypes($types)
    {
        if (is_array($types)) return implode(',', $types);
        return $types;
    }

    /**
     * Returns simple type name for debugging.
     */
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
}

/**
 * Helper for functional style.
 */
function multidispatch(): Multidispatch {
    return new Multidispatch();
}
