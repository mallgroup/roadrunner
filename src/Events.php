<?php

namespace Mallgroup\RoadRunner;

use Nette\Utils\Arrays;

/**
 * @method array flush()
 * @method array start()
 * @method array stop()
 * @method string addOnFlush(callable $cb, string|null $name = null)
 * @method string addOnStart(callable $cb, string|null $name = null)
 * @method string addOnStop(callable $cb, string|null $name = null)
 */
class Events
{
	protected const SERVICE = 'service';

	protected array $callbacks = [
		'start' => [],
		'stop' => [],
		'flush' => [],
	];

	public function __call(string $name, array $arguments)
	{
		return match ($name) {
			'flush', 'start', 'stop' => $this->invoke($name),
			'addOnFlush' => $this->addEvent('flush', ...$arguments),
			'addOnStart' => $this->addEvent('start', ...$arguments),
			'addOnStop' => $this->addEvent('stop', ...$arguments),
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
