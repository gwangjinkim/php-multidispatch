<?php

use PHPUnit\Framework\TestCase;
use function Multidispatch\multidispatch;
use Multidispatch\DispatchPolicy;

/**
 * Basic interfaces and classes for dispatch tests.
 */
interface IA {}
interface IB {}
class CA implements IA, IB {}
class CB implements IA, IB {}
class Animal {}
class Dog extends Animal {}
class Cat extends Animal {}

final class MultidispatchTest extends TestCase
{
    /**
     * Classic multiple dispatch: one winner, no method combination.
     */
    public function testClassDispatch()
    {
        $fn = multidispatch();
        $fn->setDispatchPolicy(\Multidispatch\DispatchPolicy::FIRST_WINS); // Add this line!
        $fn[[Dog::class, Dog::class]] = fn($a, $b) => 'Dog vs Dog';
        $fn[[Animal::class, Animal::class]] = fn($a, $b) => 'Animal fight';

        $this->assertEquals('Dog vs Dog', $fn(new Dog(), new Dog()));
        $this->assertEquals('Animal fight', $fn(new Cat(), new Dog()));
    }
    public function testScalarDispatch()
    {
        $fn = multidispatch();
        $fn[['int', 'string']] = fn($a, $b) => "$a and $b";

        $this->assertEquals('1 and test', $fn(1, 'test'));
    }

    public function testDefault()
    {
        $fn = multidispatch();
        $fn[['*', '*']] = fn($a, $b) => "default";

        $this->assertEquals('default', $fn([], new stdClass()));
    }

    /**
     * Classic style: registration order now controls which handler is picked if multiple interfaces match.
     * (First-wins policy by default.)
     */
    public function testInterfaceOrderSimple()
    {
        $fn = multidispatch();
        $fn[['IA']] = fn($a) => 'X';
        $fn[['IB']] = fn($a) => 'Y';

        // Default policy is last-wins! (Change with setDispatchPolicy as needed)
        $this->assertEquals('Y', $fn(new CA())); // IB wins, registered last
        $this->assertEquals('Y', $fn(new CB()));

        // Now switch to first-wins
        $fn->setDispatchPolicy(DispatchPolicy::FIRST_WINS);
        $this->assertEquals('X', $fn(new CA())); // IA wins, registered first
        $this->assertEquals('X', $fn(new CB()));
    }

    /**
     * CLOS-style method combination: :primary, :before, :after, :around
     */
    public function testPrimaryOnly()
    {  
        $fn = multidispatch();
        $fn[[[CA::class], ':primary']] = fn($a) => "CA primary";
        $this->assertEquals("CA primary", $fn(new CA()));
    }

    public function testBeforeAfterHooks()
    {
        $order = [];
        $fn = multidispatch();
        $fn[[[CA::class], ':before']] = function($a) use (&$order) { $order[] = "before"; };
        $fn[[[CA::class], ':primary']] = function($a) use (&$order) { $order[] = "primary"; };
        $fn[[[CA::class], ':after']]  = function($a) use (&$order) { $order[] = "after"; };

        $fn(new CA());
        $this->assertEquals(['before', 'primary', 'after'], $order);
    }

    /**
     * :around can wrap the entire dispatch. $callNext must be the first argument!
     */
    public function testAroundBasic()
    {
        $events = [];
        $fn = multidispatch();

        $fn[[[CA::class], ':around']] = function($callNext, $a) use (&$events) {
            $events[] = "around-before";
            $result = $callNext($a); // continue normal chain
            $events[] = "around-after";
            return $result . " (wrapped)";
        };
        $fn[[[CA::class], ':before']]  = function($a) use (&$events) { $events[] = "before"; };
        $fn[[[CA::class], ':primary']] = function($a) use (&$events) { $events[] = "primary"; return "core"; };
        $fn[[[CA::class], ':after']]   = function($a) use (&$events) { $events[] = "after"; };

        $result = $fn(new CA());

        $this->assertEquals(
            ['around-before', 'before', 'primary', 'after', 'around-after'],
            $events
        );
        $this->assertEquals("core (wrapped)", $result);
    }

    /**
     * Stacked :around methods show wrapping order. Each $callNext advances to the next layer.
     */
    public function testNestedAround()
    {
        $callStack = [];
        $fn = multidispatch();

        // Inner :around (registered *second*, so it's more specific)
        $fn[[[CA::class], ':around']] = function($callNext, $a) use (&$callStack) {
            $callStack[] = "inner-around-before";
            $result = $callNext($a);
            $callStack[] = "inner-around-after";
            return "I:$result";
        };
        // Outer :around (registered *first*, so it's less specific)
        $fn[[[CA::class], ':around']] = function($callNext, $a) use (&$callStack) {
            $callStack[] = "outer-around-before";
            $result = $callNext($a);
            $callStack[] = "outer-around-after";
            return "O:$result";
        };

        // :before
        $fn[[[CA::class], ':before']] = function($a) use (&$callStack) {
            $callStack[] = "before";
        };
        // :primary
        $fn[[[CA::class], ':primary']] = function($a) use (&$callStack) {
            $callStack[] = "primary";
            return "main";
        };
        // :after
        $fn[[[CA::class], ':after']] = function($a) use (&$callStack) {
            $callStack[] = "after";
        };

        $result = $fn(new CA());

        $this->assertEquals(
            [
                "outer-around-before",
                "inner-around-before",
                "before",
                "primary",
                "after",
                "inner-around-after",
                "outer-around-after"
            ],
            $callStack
        );
        $this->assertEquals("O:I:main", $result);
    }

    /**
     * Test wildcard fallback works for any arguments.
     */
    public function testWildcardFallback()
    {
        $fn = multidispatch();
        $fn[[['*', '*'], ':primary']] = fn($a, $b) => "Default for any pair!";
        $this->assertEquals("Default for any pair!", $fn("foo", 123));
        $this->assertEquals("Default for any pair!", $fn(null, []));
    }

    /**
     * Test changing dispatch policy on the fly.
     */
    public function testSwitchDispatchPolicy()
    {
        $fn = multidispatch();
        $fn[['IA']] = fn($a) => 'A';
        $fn[['IB']] = fn($a) => 'B';

        // Last-wins (default)
        $this->assertEquals('B', $fn(new CA()));

        // Switch to first-wins
        $fn->setDispatchPolicy(DispatchPolicy::FIRST_WINS);
        $this->assertEquals('A', $fn(new CA()));
    }
}
