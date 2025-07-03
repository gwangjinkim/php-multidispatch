<?php

use PHPUnit\Framework\TestCase;
use function Multidispatch\multidispatch;

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
     * Classic style: one-winner, no CLOS extensions.
     */
    public function testInterfaceOrderSimple()
    {
        $fn = multidispatch();
        $fn[['IA']] = fn($a) => 'X';
        $fn[['IB']] = fn($a) => 'Y';

        // With both interfaces implemented, the first registered wins by default
        $this->assertEquals('X', $fn(new CA())); // Because 'IA' registered first
        $this->assertEquals('X', $fn(new CB())); // 'IA' registered first
    }

    /**
     * CLOS-style method combination: :primary, :before, :after, :around
     */
    public function testPrimaryOnly()
    {
        $fn = multidispatch();
        $fn[[CA::class], ':primary'] = fn($a) => "CA primary";
        $this->assertEquals("CA primary", $fn(new CA()));
    }

    public function testBeforeAfterHooks()
    {
        $order = [];
        $fn = multidispatch();
        $fn[[CA::class], ':before'] = function($a) use (&$order) { $order[] = "before"; };
        $fn[[CA::class], ':primary'] = function($a) use (&$order) { $order[] = "primary"; };
        $fn[[CA::class], ':after']  = function($a) use (&$order) { $order[] = "after"; };

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

        $fn[[CA::class], ':around'] = function($callNext, $a) use (&$events) {
            $events[] = "around-before";
            $result = $callNext($a); // continue normal chain
            $events[] = "around-after";
            return $result . " (wrapped)";
        };
        $fn[[CA::class], ':before']  = function($a) use (&$events) { $events[] = "before"; };
        $fn[[CA::class], ':primary'] = function($a) use (&$events) { $events[] = "primary"; return "core"; };
        $fn[[CA::class], ':after']   = function($a) use (&$events) { $events[] = "after"; };

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
    public function testAroundStacking()
    {
        $trace = [];
        $fn = multidispatch();

        // Outer :around
        $fn[[CA::class], ':around'] = function($callNext, $a) use (&$trace) {
            $trace[] = "outer around - before";
            $result = $callNext($a); // Calls next :around or primary chain
            $trace[] = "outer around - after";
            return "[" . $result . "]";
        };

        // Inner :around (registered after, so it wraps inner)
        $fn[[CA::class], ':around'] = function($callNext, $a) use (&$trace) {
            $trace[] = "inner around - before";
            $result = $callNext($a);
            $trace[] = "inner around - after";
            return strtoupper($result);
        };

        $fn[[CA::class], ':primary'] = function($a) use (&$trace) {
            $trace[] = "primary";
            return "done";
        };

        $result = $fn(new CA());
        $this->assertEquals(
            [
                "outer around - before",
                "inner around - before",
                "primary",
                "inner around - after",
                "outer around - after"
            ],
            $trace
        );
        $this->assertEquals("[DONE]", $result);
    }

    /**
     * Mixing all types for a real-world, deeply composable example.
     */
    public function testFullCombination()
    {
        $callStack = [];
        $fn = multidispatch();

        // Outer :around
        $fn[[CA::class], ':around'] = function($callNext, $a) use (&$callStack) {
            $callStack[] = "outer-around-before";
            $result = $callNext($a);
            $callStack[] = "outer-around-after";
            return "O:$result";
        };

        // :before
        $fn[[CA::class], ':before'] = function($a) use (&$callStack) {
            $callStack[] = "before";
        };

        // :primary
        $fn[[CA::class], ':primary'] = function($a) use (&$callStack) {
            $callStack[] = "primary";
            return "P";
        };

        // :after
        $fn[[CA::class], ':after'] = function($a) use (&$callStack) {
            $callStack[] = "after";
        };

        // Inner :around
        $fn[[CA::class], ':around'] = function($callNext, $a) use (&$callStack) {
            $callStack[] = "inner-around-before";
            $result = $callNext($a);
            $callStack[] = "inner-around-after";
            return "I:$result";
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
        $this->assertEquals("O:I:P", $result);
    }

    /**
     * Check error on missing $callNext in :around (should throw/trigger error if implemented in dispatcher)
     */
    public function testAroundMissingCallNext()
    {
        $fn = multidispatch();
        $this->expectException(\ArgumentCountError::class);
        $fn[[CA::class], ':around'] = function($a) { return "should fail"; }; // $callNext missing
        $fn(new CA());
    }
}
