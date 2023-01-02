<?php

declare(strict_types=1);

namespace Mallgroup\RoadRunner;

use Psr\Http\Server\RequestHandlerInterface;
use Spiral\RoadRunner\Http\PSR7WorkerInterface;

class RoadRunner
{
	private bool $stop = false;

	public function __construct(
		private PSR7WorkerInterface $worker,
		private RequestHandlerInterface $handler,
		private Events $events,
	) {
	}

	public function run(): void
	{
		$this->events->start();

		try {
			while (!$this->stop) {
				$request = $this->worker->waitRequest();
				if ($request === null) {
					break; // graceful worker termination
				}

				try {
					$response = $this->handler->handle($request);
					$this->worker->respond($response);
				} finally {
					$this->events->flush();
				}
			}
		} finally {
			$this->events->stop();
		}
	}

	public function stop(): void
	{
		$this->stop = true;
	}
}
