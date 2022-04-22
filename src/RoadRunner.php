<?php

declare(strict_types=1);

namespace Mallgroup\RoadRunner;

use Mallgroup\RoadRunner\Http\Session;
use Mallgroup\RoadRunner\Middlewares\NetteApplicationMiddleware;
use Nette\DI\Container;
use Nette\Http\IResponse;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Spiral\RoadRunner\Http\PSR7WorkerInterface;
use Throwable;

class RoadRunner
{
	public function __construct(
		private PSR7WorkerInterface $worker,
		private PsrChain $chain,
		private Events $events,
		private ?LoggerInterface $logger = null,
	) {
	}

	public function run(): void
	{
		$this->events->init();

		while (true) {
			try {
				$request = $this->worker->waitRequest();
				if (!$request instanceof ServerRequestInterface) {
					break; // termination request
				}
			} catch (Throwable) {
				$this->worker->respond(new Response(400));
				continue;
			}

			try {
				ob_start();
				$response = $this->chain->handle($request);
				$content = ob_get_clean();
				if ($content) {
					$this->logger->warning(
						'Unexpected output found on request, you are pushing to output instead of Response',
						[
							'length' => strlen($content),
							'content' => substr($content, 0, 300) . (strlen($content) > 300 ? '... (shorted)' : ''),
						],
					);
				}

				$this->worker->respond($response);
			} catch (Throwable $e) {
				$this->worker->respond($this->processException($e));
			} finally {
				$this->events->flush();
			}
		}

		$this->events->destroy();
	}

	private function processException(Throwable $e): Response
	{
		try {
			$this->logger?->error($e->getMessage(), [
				'code' => $e->getCode(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
				'trace' => $e->getTrace(),
			]);
		} catch (\Throwable) {
		}

		return new Response(
			IResponse::S500_INTERNAL_SERVER_ERROR,
			['Content-Type' => 'text/json'],
			json_encode([
				'error' => 'Internal server error'
			])
		);
	}
}
