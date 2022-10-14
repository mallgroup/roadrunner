<?php

namespace Mallgroup\RoadRunner\Middlewares;

use JsonException;
use Nette\Http\IResponse;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tracy\BlueScreen;

class TryCatchMiddleware implements MiddlewareInterface
{
	public function __construct(
		private bool $debugMode,
		private ?BlueScreen $blueScreen = null,
	) {
	}

	public function process(
		ServerRequestInterface $request,
		RequestHandlerInterface $handler,
	): ResponseInterface {
		try {
			return $handler->handle($request);
		} catch (\Throwable $e) {
			if ($this->debugMode) {
				return $this->processExceptionError($e, $request);
			}
			return $this->internalServerError();
		}
	}

	private function internalServerError(): ResponseInterface
	{
		return $this->generateResponse(
			['Content-Type' => 'text/json'],
			'{"error":"Internal server error"}',
		);
	}

	private function processExceptionError(\Throwable $e, ServerRequestInterface $request): ResponseInterface
	{
		$headers = ['Content-Type' => 'text/html'];
		if ($request->getHeaderLine('X-Requested-With') !== 'XMLHttpRequest' && $this->blueScreen) {
			$headers['Content-Type'] = 'text/html';
			ob_start();
			$this->blueScreen->render($e);
			$content = ob_get_clean();
		} else {
			try {
				$content = json_encode([
					'error' => $e->getMessage(),
					'code' => $e->getCode(),
					'trace' => $e->getTrace()
				], JSON_THROW_ON_ERROR);
			} catch (JsonException) {
				$content = $e::class . ':' . $e->getMessage() . "\n" . $e->getTraceAsString();
			}
		}

		return $this->generateResponse($headers, $content);
	}

	private function generateResponse(array $headers, string $content): ResponseInterface
	{
		return new Response(
			IResponse::S500_INTERNAL_SERVER_ERROR,
			$headers,
			$content,
		);
	}
}
