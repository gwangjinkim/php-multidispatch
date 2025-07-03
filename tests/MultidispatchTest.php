<?php

use PHPUnit\Framework\TestCase;
use function Multidispatch\multidispatch;

/**
 * Simple interfaces and classes for testing.
 */
interface IA {}
interface IB {}
class Animal {}
class Dog extends Animal {}
class Cat extends Animal {}
class CA implements IA, IB {}
class CB implements IA, IB {}

final class MultidispatchTest extends TestCase
{
    // === CLASSIC MULTIPLE DISPATCH TESTS ===

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

    public function testInterfaceDispatch()
    {
        $fn = multidispatch();
        $fn[['IA']] = fn($a) => "IA";
        $fn[['IB']] = fn($a) => "IB";
        $fn[['*']]  = fn($a) => "Fallback";
        $this->assertEquals("IA", $fn(new CA()));
        $this->assertEquals("IA", $fn(new CB()));
        $this->assertEquals("Fallback", $fn([]));
    }

    // === CLOS-STYLE METHOD COMBINATION TESTS ===

    public function testBeforeAfterPrimary()
    {
        $fn = multidispatch();
        $log = [];

        $fn->before([Dog::class], function($a) use (&$log) {
            $log[] = "before";
        });
        $fn->primary([Dog::class], function($a) use (&$log) {
            $log[] = "primary";
            return "dog";
        });
        $fn->after([Dog::class], function($a) use (&$log) {
            $log[] = "after";
        });

        $result = $fn(new Dog());
        $this->assertEquals("dog", $result);
        $this->assertEquals(['before', 'primary', 'after'], $log);
    }

    public function testAroundCallsPrimaryAndOthers()
    {
        $fn = multidispatch();
        $order = [];

        $fn->before([Dog::class], function($a) use (&$order) {
            $order[] = "before";
        });
        $fn->primary([Dog::class], function($a) use (&$order) {
            $order[] = "primary";
            return "woof";
        });
        $fn->after([Dog::class], function($a) use (&$order) {
            $order[] = "after";
        });
        $fn->around([Dog::class], function($a, $call_next) use (&$order) {
            $order[] = "around-start";
            $result = $call_next($a); // Calls before/primary/after chain
            $order[] = "around-end";
            return strtoupper($result);
        });

        $result = $fn(new Dog());
        $this->assertEquals("WOOF", $result);
        $this->assertEquals(['around-start', 'before', 'primary', 'after', 'around-end'], $order);
    }

    public function testAroundCanSkipPrimary()
    {
        $fn = multidispatch();

        $fn->primary([Dog::class], function($a) {
            return "primary";
        });
        $fn->around([Dog::class], function($a, $call_next) {
            return "overridden";
        });

        $result = $fn(new Dog());
        $this->assertEquals("overridden", $result); // :around does NOT call $call_next, so :primary is skipped
    }

    public function testCallNextMethodMultipleLayers()
    {
        $fn = multidispatch();
        $log = [];

        $fn->around([Dog::class], function($a, $call_next) use (&$log) {
            $log[] = "around1";
            $r = $call_next($a);
            $log[] = "around1-exit";
            return $r;
        });
        $fn->around([Animal::class], function($a, $call_next) use (&$log) {
            $log[] = "around2";
            $r = $call_next($a);
            $log[] = "around2-exit";
            return $r;
        });
        $fn->primary([Dog::class], function($a) use (&$log) {
            $log[] = "primary";
            return "done";
        });

        $result = $fn(new Dog());
        $this->assertEquals("done", $result);
        $this->assertEquals(['around1', 'around2', 'primary', 'around2-exit', 'around1-exit'], $log);
    }

    public function testFallbackForUnmatchedTypes()
    {
        $fn = multidispatch();
        $fn->primary(['*'], function($a) {
            return "fallback";
        });

        $this->assertEquals("fallback", $fn([]));
        $this->assertEquals("fallback", $fn(new stdClass()));
    }
}
