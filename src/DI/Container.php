<?php

declare(strict_types=1);

namespace Mallgroup\RoadRunner\DI;

class Container extends \Nette\DI\Container
{
	/** @var object[]  service name => instance */
	protected array $instances = [];

	public function flushService(string $name): void
	{
		unset($this->instances[$name]);
	}
}
