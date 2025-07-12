<?php

require "vendor/autoload.php";
use function GwangJinKim\Multidispatch\multidispatch;
use GwangJinKim\Multidispatch\DispatchPolicy;

// --- Example 1: Classic Multiple Dispatch (No CLOS Extensions) ---
//      Demonstrates simple "one-winner" dispatch, compatible with both first-wins and last-wins policies.

echo "--- Classic Multiple Dispatch ---\n";

// Define some interfaces and classes
interface IA {}
interface IB {}
class CA implements IA, IB {}
class CB implements IA, IB {}

// Create a dispatcher instance (defaults to last-wins, but you can set first-wins if you want)
$fn = multidispatch();

// Optionally switch to first-wins for this dispatcher:
// $fn->setDispatchPolicy(DispatchPolicy::FIRST_WINS);

// Register handlers for IA and IB types. Policy controls which wins when multiple are applicable!
$fn[['IA']] = fn($a) => "Handler for IA";
$fn[['IB']] = fn($a) => "Handler for IB";
$fn[['*']]  = fn($a) => "Default Handler (fallback)";

// Try dispatching with an object that implements both interfaces
echo $fn(new CA()) . "\n"; // Which handler runs? (Depends on policy; see note below)
echo $fn(new CB()) . "\n"; // Same as above

// NOTES:
// - If "first-wins", the *first* registered handler (IA) will win for both CA and CB.
// - If "last-wins" (the default), the *last* registered handler (IB) will win for both CA and CB.

// You can change the policy at any time!
$fn->setDispatchPolicy(DispatchPolicy::FIRST_WINS);
echo "[First-wins] " . $fn(new CA()) . "\n"; // Now IA will win

$fn->setDispatchPolicy(DispatchPolicy::LAST_WINS);
echo "[Last-wins] " . $fn(new CB()) . "\n"; // Now IB will win

// Also works for built-in types
$scalarDispatch = multidispatch();
$scalarDispatch[['int', 'string']] = fn($x, $y) => "Int: $x, String: $y";
echo $scalarDispatch(42, "foo") . "\n"; // Int: 42, String: foo

// You can always register a general fallback handler:
$fn[['*']] = fn($a) => "Fallback/default handler";
echo $fn([]) . "\n"; // Fallback/default handler

// --- Example 2: CLOS Extensions (:primary, :before, :after, :around) ---
//      Use CLOS-inspired hooks to combine multiple behaviors for a single dispatch.

echo "\n--- CLOS-Style Dispatch: :primary, :before, :after, :around ---\n";

// We'll use the same CA class for demonstration
$clos = multidispatch();

// Register CLOS-style hooks. The key is always [ [argtypes...], ':qualifier' ]
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
// Output order will be: Around (before), Before, Primary, After, Around (after), [Wrapped: ...]

// --- Example 3: Multiple :around Wrappers (Stacked) ---
//      Demonstrates multiple :around hooks and the "Russian doll" wrapping order.

echo "\n--- Stacked :around Methods ---\n";
$stacked = multidispatch();

// The *first registered* :around becomes the *outermost* wrapper (least specific), the next is inside it, etc.
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
// Output will show both :around wrappers and the order in which they're invoked.

// --- Example 4: Custom Types and Multiple Arguments ---
//      Multiple dispatch works for any number of arguments and for class inheritance chains.

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

// --- Notes and Tips ---

/*
 * - All CLOS-style hooks use array keys of the form [ [argtypes...], ':kind' ].
 * - The :primary method is the main handler for the given types.
 * - :before and :after run before and after the primary (no return value used).
 * - :around wraps the entire dispatch and must take $callNext as its first argument.
 *   You can have multiple :around methods; they're called "outside-in" (like nested Russian dolls).
 * - Classic (non-CLOS) dispatch just uses [argtypes...] as the key. This is 100% backward compatible.
 * - Wildcard '*' matches anything.
 * - You can register for interfaces, classes, or scalar types (like 'int', 'string', etc.).
 *
 * POLICY TIP:
 *   Use $fn->setDispatchPolicy(DispatchPolicy::FIRST_WINS) if you want the first registered handler to win.
 *   Use $fn->setDispatchPolicy(DispatchPolicy::LAST_WINS) (default) for the last registered handler.
 *   This only affects :primary/classic style, not :before/:after/:around hooks.
 */

echo "\n--- End of examples ---\n";
