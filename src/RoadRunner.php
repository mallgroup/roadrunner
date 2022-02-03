<?php

declare(strict_types=1);

namespace Mallgroup\RoadRunner;

use Nyholm\Psr7\Response;
use Nette\DI\Container;
use Nette\Http\IResponse;
use Mallgroup\RoadRunner\Http\IRequest;
use Mallgroup\RoadRunner\Http\RequestFactory;
use Psr\Http\Message\ServerRequestInterface;
use Spiral\RoadRunner\Http\PSR7WorkerInterface;
use Throwable;
use Tracy\BlueScreen;

class RoadRunner
{
	private ?PsrApplication $application = null;

	public function __construct(
		private PSR7WorkerInterface $worker,
		private Container $container,
		private bool $showExceptions = false,
	) {
	}

	public function run(): void
	{
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
				RequestFactory::setRequest($request);
				$this->worker->respond(
					$this->getApplication()->run($request)
				);
			} catch (Throwable $e) {
				$this->worker->respond($this->processException($e));
			}
		}
	}

	private function getApplication(): PsrApplication
	{
		if (null === $this->application) {
			/** @psalm-var PsrApplication @phpstan-ignore-next-line */
			$this->application = $this->container->getByType(PsrApplication::class);
		}
		return $this->application;
	}

	private function processException(Throwable $e): Response
	{
		try {
			$headers = ['Content-Type' => 'text/json'];
			if ($this->showExceptions) {
				$blueScreen = $this->container->getByType(BlueScreen::class, false);
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
