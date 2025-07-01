<?php

use PHPUnit\Framework\TestCase;
use Multidispatch\Multidispatch;
use function Multidispatch\multidispatch;

class Animal {}
class Dog extends Animal {}
class Cat extends Animal {}

final class MultidispatchTest extends TestCase
{
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
}
