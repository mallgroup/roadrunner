<?php

namespace Mallgroup\RoadRunner;

use Nette\Utils\Arrays;

/**
 * @method flush(): array
 * @method init(): array
 * @method destroy(): array
 * @method addOnFlush(callable $cb, string $name = null): string
 * @method addOnInit(callable $cb, string $name = null): string
 * @method addOnDestroy(callable $cb, string $name = null): string
 */
class Events
{
	protected const SERVICE = 'service';

	protected array $callbacks = [
		'init' => [],
		'destroy' => [],
		'flush' => [],
	];

	public function __call(string $name, array $arguments)
	{
		return match ($name) {
			'flush', 'init', 'destroy' => $this->invoke($name),
			'addOnFlush' => $this->addEvent('flush', ...$arguments),
			'addOnInit' => $this->addEvent('init', ...$arguments),
			'addOnDestroy' => $this->addEvent('destroy', ...$arguments),
			default => throw new \InvalidArgumentException("Method {$name} not found."),
		};
	}

	private function invoke(string $event): array
	{
		return Arrays::invoke($this->callbacks[$event] ?? []);
	}

	private function addEvent(string $type, callable $cb, ?string $name = null): string
	{
		$name = $this->getServiceId($name, $type);
		$this->callbacks[$type][$name] = $cb;
		return $name;
	}

	private function getServiceId(string|null $name, string $type): string
	{
		return $name ?: self::SERVICE . (1 + count($this->callbacks[$type]));
	}
}
