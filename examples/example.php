<?php

require "vendor/autoload.php";
use function Multidispatch\multidispatch;

// --- Example 1: Classic Multiple Dispatch (No CLOS Extensions) ---

echo "--- Classic Multiple Dispatch ---\n";

// Define some interfaces and classes
interface IA {}
interface IB {}
class CA implements IA, IB {}
class CB implements IA, IB {}

// Create a dispatcher instance
$fn = multidispatch();

// Register handlers for IA and IB types. Only the most specific one will fire!
$fn[['IA']] = fn($a) => "Handler for IA";
$fn[['IB']] = fn($a) => "Handler for IB";
$fn[['*']]  = fn($a) => "Default Handler (fallback)";

// Try dispatching with an object that implements both interfaces
echo $fn(new CA()) . "\n"; // Which handler runs? (Answer: IA, because it was registered first)
echo $fn(new CB()) . "\n"; // Same as above

// Also works for built-in types
$scalarDispatch = multidispatch();
$scalarDispatch[['int', 'string']] = fn($x, $y) => "Int: $x, String: $y";
echo $scalarDispatch(42, "foo") . "\n"; // Int: 42, String: foo

// You can always register a general fallback handler:
$fn[['*']] = fn($a) => "Fallback/default handler";
echo $fn([]) . "\n"; // Fallback/default handler

// --- Example 2: CLOS Extensions (:primary, :before, :after, :around) ---

echo "\n--- CLOS-Style Dispatch: :primary, :before, :after, :around ---\n";

// We'll use the same CA class for demonstration
$clos = multidispatch();

// CLOS registration keys are always [ [argtypes...], ':kind' ]
$clos[[[CA::class], ':before']] = function($a) {
    echo "Before hook runs!\n";
};
$clos[[[CA::class], ':primary']] = function($a) {
    echo "Primary runs!\n";
    return "Result from primary";
};
$clos[[[CA::class], ':after']] = function($a) {
    echo "After hook runs!\n";
};
$clos[[[CA::class], ':around']] = function($callNext, $a) {
    echo "Around (before)!\n";
    $result = $callNext($a); // Continue chain
    echo "Around (after)!\n";
    return "[Wrapped: $result]";
};

echo $clos(new CA()) . "\n";

// --- Example 3: Multiple :around Wrappers (Stacked) ---

echo "\n--- Stacked :around Methods ---\n";
$stacked = multidispatch();

$stacked[[[CA::class], ':around']] = function($callNext, $a) {
    echo "Outer around (before)\n";
    $result = $callNext($a);
    echo "Outer around (after)\n";
    return "O:$result";
};
$stacked[[[CA::class], ':around']] = function($callNext, $a) {
    echo "Inner around (before)\n";
    $result = $callNext($a);
    echo "Inner around (after)\n";
    return "I:$result";
};
$stacked[[[CA::class], ':primary']] = function($a) {
    echo "Primary action\n";
    return "main";
};

echo $stacked(new CA()) . "\n";

// --- Example 4: Custom Types and Multiple Arguments ---

echo "\n--- Multiple Arguments Dispatch ---\n";

class Animal {}
class Dog extends Animal {}
class Cat extends Animal {}

$battle = multidispatch();
$battle[[[Dog::class, Dog::class], ':primary']] = fn($a, $b) => "Dog vs Dog fight!";
$battle[[[Animal::class, Animal::class], ':primary']] = fn($a, $b) => "Generic Animal fight";

echo $battle(new Dog(), new Dog()) . "\n"; // Dog vs Dog fight!
echo $battle(new Cat(), new Dog()) . "\n"; // Generic Animal fight

// --- Example 5: Fallback Handler for Any Arguments ---

echo "\n--- Wildcard Fallback ---\n";
$fallback = multidispatch();
$fallback[[['*', '*'], ':primary']] = fn($a, $b) => "Default for any pair!";
echo $fallback("foo", 123) . "\n"; // Default for any pair!

// --- Notes ---

/*
 * - All CLOS-style hooks use array key of the form [ [argtypes...], ':kind' ].
 * - The :primary method is the main handler for the given types.
 * - :before and :after run before and after the primary (no return value used).
 * - :around wraps the entire dispatch and must take $callNext as its first argument. 
 *   You can have multiple :around methods; they're called "outside-in" (like nested Russian dolls).
 * - Classic (non-CLOS) dispatch just uses [argtypes...] as the key. This is 100% backward compatible.
 * - Wildcard '*' matches anything.
 *
 * Tip: You can register for interfaces, classes, or scalar types (like 'int', 'string', etc.).
 */

echo "\n--- End of examples ---\n";
