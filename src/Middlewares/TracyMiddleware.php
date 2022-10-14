<?php

namespace Mallgroup\RoadRunner\Middlewares;

use JsonException;
use Mallgroup\RoadRunner\Utils\Dumper;
use Nette\Http\IResponse;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tracy\BlueScreen;
use function ob_get_clean;
use function ob_start;

class TracyMiddleware implements MiddlewareInterface
{
	public function __construct(
		private bool $debugMode
	) {
	}

	public function process(
		ServerRequestInterface $request,
		RequestHandlerInterface $handler,
	): ResponseInterface {
		if (!$this->debugMode) {
			return $handler->handle($request);
		}

		Dumper::setHtmlMode(true);
		$content = $handler->handle($request);
		ob_start();
		Dumper::renderAssets();
		$content->getBody()->write((string) ob_get_clean());

		return $content;
	}
}
