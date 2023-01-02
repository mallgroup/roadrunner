<?php

namespace Mallgroup\RoadRunner\Middlewares;

use Mallgroup\RoadRunner\Http\IRequest;
use Mallgroup\RoadRunner\Http\IResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class NetteInitializeMiddleware implements MiddlewareInterface
{
	public function __construct(
		private IRequest $httpRequest,
		private IResponse $httpResponse,
	) {
	}

	public function process(
		ServerRequestInterface $request,
		RequestHandlerInterface $handler,
	): ResponseInterface {
		$this->httpResponse->cleanup();
		$this->httpRequest->updateFromPsr($request);

		return $handler->handle($request);
	}
}
