<?php

declare(strict_types=1);

namespace Mallgroup\RoadRunner;

use Nette\Http\IResponse;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
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
		private ResponseFactoryInterface $responseFactory,
		private ?LoggerInterface $logger = null,
	) {
	}

	public function run(): void
	{
		$this->events->start();

		while (true) {
			try {
				$request = $this->worker->waitRequest();
				if (!$request instanceof ServerRequestInterface) {
					break; // termination request
				}
			} catch (Throwable) {
				$this->worker->respond($this->responseFactory->createResponse(IResponse::S400_BadRequest));
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

		$this->events->stop();
	}

	private function processException(Throwable $e): ResponseInterface
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

		$resp = $this->responseFactory->createResponse(IResponse::S500_InternalServerError)
			->withHeader('Content-Type', 'text/json');
		$resp->getBody()->write('{"error":"Internal server error"}');
		return $resp;
	}
}
