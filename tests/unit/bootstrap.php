<?php

require __DIR__ . '/../../vendor/autoload.php';

Tester\Environment::setup();

function test(string $description, Closure $fn): void
{
	echo $description, "\n";
	$fn();
}
