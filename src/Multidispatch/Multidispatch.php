<?php

namespace Multidispatch;

use ArrayAccess;
use Exception;

/**
 * Multiple Dispatch system with CLOS-style method combinations.
 * Supports :primary, :before, :after, :around, with proper stacking of :around methods
 * (from least to most specific, outermost to innermost).
 */
class DispatchPolicy {
    public const FIRST_WINS = 'first-wins';
    public const LAST_WINS = 'last-wins';
}

class Multidispatch implements ArrayAccess
{
    private string $dispatchPolicy = DispatchPolicy::LAST_WINS; // better for extensibility than FIRST_WINS!

    public function setDispatchPolicy(string $policy): void {
        if (!in_array($policy, [DispatchPolicy::FIRST_WINS, DispatchPolicy::LAST_WINS])) {
            throw new Exception("Policy must be 'first-wins' or 'last-wins'");
        }
        $this->dispatchPolicy = $policy;
    }
    // Usage: $fn->setDispatchPolicy(DispatchPolicy::FIRST_WINS);

    private array $methods = [];

    public function __invoke(...$args)
    {
        [$candidates, $resolvedTypes] = $this->resolve($args);

        // Compose core: :before, :primary, :after
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

        // Compose all :around in correct CLOS order: least-specific (outermost) to most-specific (innermost)
        $aroundStack = $candidates['around'];
        $wrapped = array_reduce(
            $aroundStack,
            function ($next, $around) {
                return function (...$args) use ($around, $next) {
                    // $around($callNext, ...$args)
                    return $around($next, ...$args);
                };
            },
            $core
        );

        return $wrapped(...$args);
    }

    // ---- Registration interface ----

    public function offsetSet($offset, $value): void
    {
        // Support: $fn[[type,...], ':primary'] = fn...
        if (is_array($offset) && count($offset) >= 1 && is_string($offset[count($offset)-1]) && str_starts_with($offset[count($offset)-1], ':')) {
            $tag = array_pop($offset);
        } else {
            $tag = ':primary';
        }
        $key = $this->keyFromTypes($offset);
        if (!isset($this->methods[$key]) || !is_array($this->methods[$key])) {
            $this->methods[$key] = [];
        }

        if ($tag === ':primary') {
            // For classic dispatch, simply overwrite (last-wins by default)
            $this->methods[$key][$tag] = $value;
        } else {
            // For other tags, collect methods in an array.
            if (!isset($this->methods[$key][$tag])) {
                $this->methods[$key][$tag] = [];
            }
            // Prepend to make the latest definition the most specific (innermost)
            array_unshift($this->methods[$key][$tag], $value);
        }
    }

    public function offsetExists($offset): bool
    {
        $key = $this->keyFromTypes($offset);
        return isset($this->methods[$key]);
    }

    public function offsetGet($offset): callable
    {
        $key = $this->keyFromTypes($offset);
        return $this->methods[$key][':primary'] ?? throw new Exception("No :primary method for $key");
    }

    public function offsetUnset($offset): void
    {
        $key = $this->keyFromTypes($offset);
        unset($this->methods[$key]);
    }

    // ---- Type handling ----

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

    private function allTypesForArg($arg): array
    {
        if (is_object($arg)) {
            $types = [ltrim(get_class($arg), '\\')];
            $types = array_merge($types, class_implements($arg), class_parents($arg));
            $types[] = '*';
            $types = array_map(fn($x) => ltrim($x, '\\'), $types);
            $types = array_unique($types);
            return $types;
        }
        $type = $this->getTypeName($arg);
        return [$type, '*'];
    }

    private function keyFromTypes($types)
    {
        $flat = [];
        foreach ($types as $t) {
            if (is_array($t)) $flat = array_merge($flat, $t);
            else $flat[] = $t;
        }
        return implode(',', $flat);
    }

    // ---- CLOS-style candidate resolution ----

    /**
     * Return all candidate methods for given arguments.
     * @return [candidates, resolvedTypes]
     */
    private function resolve(array $args): array
    {
        $typeChains = array_map([$this, 'allTypesForArg'], $args);

        $combos = $this->cartesian($typeChains);

        $candidates = [
            'before' => [],
            'primary' => null,
            'after' => [],
            'around' => [],
        ];
        $matchedTypes = null;

        // For primary, choose based on dispatch policy!
        foreach ($combos as $combo) {
            $key = $this->keyFromTypes($combo);
            if (isset($this->methods[$key])) {
                $methods = $this->methods[$key];
                if (isset($methods[':before'])) {
                    $candidates['before'] = array_merge($candidates['before'], $methods[':before']);
                }
                if (isset($methods[':primary'])) {
                    if ($this->dispatchPolicy === DispatchPolicy::FIRST_WINS && !$candidates['primary']) {
                        $candidates['primary'] = $methods[':primary'];
                        $matchedTypes = $combo;
                    }
                    if ($this->dispatchPolicy === DispatchPolicy::LAST_WINS) {
                        $candidates['primary'] = $methods[':primary'];
                        $matchedTypes = $combo;
                    }
                }
                if (isset($methods[':after'])) {
                    $candidates['after'] = array_merge($candidates['after'], $methods[':after']);
                }
                if (isset($methods[':around'])) {
                    $candidates['around'] = array_merge($candidates['around'], $methods[':around']);
                }
            }
        }

        // The original's default '*' handling is implicitly covered if '*' is in $combos.
        if (!$candidates['primary']) {
            throw new Exception("No :primary method for types: " . implode(', ', array_map([$this, 'getTypeName'], $args)));
        }

        // :before methods run least-to-most specific, so we reverse the collected list.
        $candidates['before'] = array_reverse($candidates['before']);
        // :after methods run most-to-least specific (as collected).
        // :around methods: least-to-most specific
        $candidates['around'] = array_reverse($candidates['around']);

        return [$candidates, $matchedTypes];
    }

    // ---- Cartesian product helper ----

    private function cartesian($arrays)
    {
        if (count($arrays) === 0) return [[]];
        $result = [[]];
        foreach ($arrays as $property => $property_values) {
            $tmp = [];
            foreach ($result as $result_item) {
                foreach ($property_values as $property_value) {
                    $tmp[] = array_merge($result_item, [$property_value]);
                }
            }
            $result = $tmp;
        }
        return $result;
    }
}

/**
 * Factory function, PSR-4 autoloaded.
 */
function multidispatch(): Multidispatch
{
    return new Multidispatch();
}
