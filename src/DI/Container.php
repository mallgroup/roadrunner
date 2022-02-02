<?php

declare(strict_types=1);

namespace Mallgroup\RoadRunner\DI;

class Container extends \Nette\DI\Container {
	protected array $instances = [];

	public function flushService(string $name): void {
		unset($this->instances[$name]);
	}
}
