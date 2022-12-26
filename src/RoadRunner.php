<?php

declare(strict_types=1);

namespace Mallgroup\RoadRunner;

use Mallgroup\RoadRunner\Http\IRequest;
use Mallgroup\RoadRunner\Http\IResponse;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Spiral\RoadRunner\Http\PSR7WorkerInterface;
use Throwable;

class RoadRunner
{
	public function __construct(
		private PSR7WorkerInterface $worker,
		private RequestHandlerInterface $handler,
		private Events $events,
		private IRequest $httpRequest,
		private IResponse $httpResponse,
		private ?LoggerInterface $logger = null,
	) {
	}

	public function run(): void
	{
		$this->events->start();

		while (true) {
			try {
				$request = $this->worker->waitRequest();
				if ($request === null) {
					break; // graceful worker termination
				}
			} catch (Throwable $e) {
				$this->logger?->critical('Unable to receive messages from RoadRunner.', ['exception' => $e]);
				throw $e;
			}

			try {
				$this->initialize($request);

				$response = $this->handler->handle($request);
				$this->worker->respond($response);
			} catch (Throwable $e) {
				$this->logger?->critical('Uncaught Application Exception', ['exception' => $e]);
				throw $e;
			} finally {
				$this->events->flush();
			}
		}

		$this->events->stop();
	}

	private function initialize(ServerRequestInterface $request): void
	{
		$this->httpResponse->cleanup();
		$this->httpRequest->updateFromPsr($request);
	}
}
