<?php

namespace Multidispatch;

use ArrayAccess;
use Exception;

class Multidispatch implements ArrayAccess
{
    private array $methods = []; // [signature => [combType => [fn1, fn2, ...]]]
    private array $methodOrder = []; // [signature => registration order]

    public function __invoke(...$args)
    {
        $combType = ':primary';
        // Allow override for backward compatibility / classic usage
        if ($args && is_string($args[0]) && in_array($args[0], [':primary', ':before', ':after', ':around'])) {
            $combType = array_shift($args);
        }

        // Method registration: $fn[['sig'], ':comb'] = callable
        if (is_array($args[0] ?? null) && (is_callable($args[1] ?? null) || is_array($args[1] ?? null))) {
            // Registration with/without comb type
            $sig = $args[0];
            $fnOrArr = $args[1];
            $combTypeReg = $args[2] ?? ':primary';
            if (is_callable($fnOrArr)) {
                $this->registerMethod($sig, $combTypeReg, $fnOrArr);
            } elseif (is_array($fnOrArr)) {
                foreach ($fnOrArr as $k => $fn) {
                    $this->registerMethod($sig, $k, $fn);
                }
            }
            return;
        }

        // --- Dispatch: gather all candidate methods ---
        $types = array_map([$this, 'getTypeName'], $args);

        // 1. Get all type chains (C3 linearization-style order)
        $chains = array_map([$this, 'getChain'], $types);

        // 2. Find all applicable signatures, in specificity order
        $combCandidates = $this->findAllCombinations($chains);

        // 3. For CLOS, collect all methods for each comb type
        $primaries = []; $befores = []; $afters = []; $arounds = [];

        foreach ($combCandidates as $key) {
            if (!empty($this->methods[$key])) {
                foreach ($this->methods[$key] as $comb => $lst) {
                    foreach ($lst as $fn) {
                        if ($comb === ':primary') $primaries[] = $fn;
                        elseif ($comb === ':before') $befores[] = $fn;
                        elseif ($comb === ':after') $afters[] = $fn;
                        elseif ($comb === ':around') $arounds[] = $fn;
                    }
                }
            }
        }
        // Fallback to * for each arg if nothing found
        if (empty($primaries) && empty($befores) && empty($afters) && empty($arounds)) {
            $defaultKey = implode(',', array_fill(0, count($types), '*'));
            if (isset($this->methods[$defaultKey])) {
                foreach ($this->methods[$defaultKey] as $comb => $lst) {
                    foreach ($lst as $fn) {
                        if ($comb === ':primary') $primaries[] = $fn;
                        elseif ($comb === ':before') $befores[] = $fn;
                        elseif ($comb === ':after') $afters[] = $fn;
                        elseif ($comb === ':around') $arounds[] = $fn;
                    }
                }
            }
        }
        if (empty($primaries) && empty($befores) && empty($afters) && empty($arounds)) {
            throw new Exception("No method for types: " . implode(', ', $types));
        }

        // --- CLOS-style method combination ---
        // Compose primaries into one $callNext for :around methods
        $dispatchPrimaries = function (...$args) use ($primaries) {
            if (empty($primaries)) throw new Exception("No :primary method available");
            // Call most specific primary (first)
            return ($primaries[0])(...$args);
        };

        // Stack :around methods (outermost first)
        $finalCallable = array_reduce(
            array_reverse($arounds),
            function ($next, $around) {
                return function (...$args) use ($next, $around) {
                    // $around always gets $callNext as FIRST arg, then ...$args
                    return $around($next, ...$args);
                };
            },
            function (...$args) use ($dispatchPrimaries) {
                // Run :before methods (most specific first)
                foreach ($GLOBALS['__dispatch_befores'] ?? [] as $before) {
                    $before(...$args);
                }
                // Run main :primary
                $result = $dispatchPrimaries(...$args);
                // Run :after methods (least specific first)
                foreach (array_reverse($GLOBALS['__dispatch_afters'] ?? []) as $after) {
                    $after(...$args);
                }
                return $result;
            }
        );

        // Share :before/:after via global hack (thread unsafe, but PHP is single-threaded mostly)
        $GLOBALS['__dispatch_befores'] = $befores;
        $GLOBALS['__dispatch_afters'] = $afters;

        $result = $finalCallable(...$args);

        unset($GLOBALS['__dispatch_befores']);
        unset($GLOBALS['__dispatch_afters']);
        return $result;
    }

    private function registerMethod($sig, $combType, $fn)
    {
        $key = implode(',', $sig);
        if (!isset($this->methods[$key])) {
            $this->methods[$key] = [];
        }
        if (!isset($this->methods[$key][$combType])) {
            $this->methods[$key][$combType] = [];
        }
        $this->methods[$key][$combType][] = $fn;
        $this->methodOrder[$key] = ($this->methodOrder[$key] ?? 0) + 1;
    }

    public function offsetSet($offset, $value): void
    {
        // Allow: $fn[['sig'], ':comb'] = $fn;
        if (is_array($offset) && count($offset) > 0 && is_string($offset[0]) && str_starts_with($offset[0], ':')) {
            $comb = array_shift($offset);
            $this->registerMethod($offset, $comb, $value);
        } elseif (is_array($value)) {
            foreach ($value as $comb => $fn) {
                $this->registerMethod($offset, $comb, $fn);
            }
        } else {
            $this->registerMethod($offset, ':primary', $value);
        }
    }

    public function offsetExists($offset): bool
    {
        $key = implode(',', $offset);
        return isset($this->methods[$key]);
    }

    public function offsetGet($offset): callable
    {
        $key = implode(',', $offset);
        if (!isset($this->methods[$key])) throw new Exception("No method for $key");
        foreach ([':primary', ':before', ':after', ':around'] as $comb) {
            if (!empty($this->methods[$key][$comb])) return $this->methods[$key][$comb][0];
        }
        throw new Exception("No method for $key");
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

    private function findAllCombinations(array $chains): array
    {
        if (empty($chains)) return [];
        $combos = $this->generateCombinations($chains);
        return array_map(fn($c) => implode(',', $c), $combos);
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

function multidispatch(): Multidispatch {
    return new Multidispatch();
}
