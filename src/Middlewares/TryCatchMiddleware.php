<?php

namespace Mallgroup\RoadRunner\Middlewares;

use JsonException;
use Nette\Http\IResponse;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tracy\BlueScreen;
use Tracy\Helpers;

class TryCatchMiddleware implements MiddlewareInterface
{
	public function __construct(
		private bool $debugMode,
		private ResponseFactoryInterface $responseFactory,
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

			$content = Helpers::capture(function () use ($e) {
				$this->blueScreen->render($e);
			});
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
		$resp = $this->responseFactory->createResponse(IResponse::S500_InternalServerError);
		$resp->getBody()->write($content);

		foreach ($headers as $header => $value) {
			$resp = $resp->withHeader($header, $value);
		}

		return $resp;
	}
}
