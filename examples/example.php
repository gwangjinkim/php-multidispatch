<?php

require __DIR__ . '/../vendor/autoload.php';

use function Multidispatch\multidispatch;

class Animal {}
class Dog extends Animal {}
class Cat extends Animal {}

$attack = multidispatch();

$attack[[Dog::class, Dog::class]] = fn($a, $b) => 'Dog vs Dog';
$attack[[Animal::class, Animal::class]] = fn($a, $b) => 'Animal fight';
$attack[['int', 'string']] = fn($a, $b) => "$a and $b";
$attack[['*', '*']] = fn($a, $b) => "fallback";

echo $attack(new Dog(), new Dog()) . PHP_EOL;     // Dog vs Dog
echo $attack(new Cat(), new Dog()) . PHP_EOL;     // Animal fight
echo $attack(42, "hello") . PHP_EOL;              // 42 and hello
echo $attack([], null) . PHP_EOL;                 // fallback
