<?php

declare(strict_types=1);

namespace Mallgroup\RoadRunner;

use Mallgroup\RoadRunner\Http\Session;
use Nyholm\Psr7\Response;
use Nette\DI\Container;
use Nette\Http\IResponse;
use Mallgroup\RoadRunner\Http\IRequest;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Spiral\RoadRunner\Http\PSR7WorkerInterface;
use Throwable;
use Tracy\BlueScreen;

class RoadRunner
{
	private PsrApplication $application;
	private Session $session;
	private ?LoggerInterface $logger = null;

	public function __construct(
		private PSR7WorkerInterface $worker,
		private Container $container,
		private bool $showExceptions = false,
	) {
		try {
			$this->application = $this->container->getByType(PsrApplication::class);
			$this->session = $this->container->getByType(Session::class);
			$this->logger = $this->container->getByType(LoggerInterface::class, false);
		} catch (\Throwable) {
			$this->worker->getWorker()->error('Failed to load application');
			$this->worker->getWorker()->stop();
		}
	}

	public function run(): void
	{
		$this->session->setup();

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
				$response = new Response;
				ob_start(function () {
				});
				$response = $this->application->run($request, $response);
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
				$this->application->afterResponse($this->container);
			}
		}
	}

	private function processException(Throwable $e): Response
	{
		try {
			$headers = ['Content-Type' => 'text/json'];
			$this->logger?->error($e->getMessage(), [
				'code' => $e->getCode(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
				'trace' => $e->getTrace(),
			]);

			if ($this->showExceptions) {
				/** @var BlueScreen|null $blueScreen */
				$blueScreen = $this->container->getByType(BlueScreen::class, false);
				/** @var IRequest|null $request */
				$request = $this->container->getByType(IRequest::class, false);

				if (!$request?->isAjax() && $blueScreen) {
					$headers['Content-Type'] = 'text/html';
					ob_start();
					$blueScreen->render($e);
					$content = ob_get_clean();
				} else {
					$content = json_encode([
						'error' => $e->getMessage(),
						'code' => $e->getCode(),
						'trace' => $e->getTrace()
					]);
				}
			} else {
				$content = json_encode([
					'error' => 'Internal server error'
				]);
			}
		} catch (\Throwable $throwable) {
			$content = json_encode([
				'error' => $throwable->getMessage(),
				'trace' => $throwable->getTrace(),
				'previous' => [
					'error' => $e->getMessage(),
					'trace' => $e->getTrace(),
				]
			]);
		}

		return new Response(IResponse::S500_INTERNAL_SERVER_ERROR, $headers, $content ?: null);
	}
}
