<?php

declare(strict_types=1);

namespace Mallgroup\RoadRunner;

use Psr\Http\Server\RequestHandlerInterface;
use Spiral\RoadRunner\Http\PSR7WorkerInterface;

class RoadRunner
{
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
			while (true) {
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
}
