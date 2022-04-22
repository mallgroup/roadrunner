<?php

namespace Mallgroup\RoadRunner\Middlewares;

use Mallgroup\RoadRunner\Http\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class SessionMiddleware implements MiddlewareInterface
{
	public function __construct(Session $session)
	{
		$session->setup();
	}

	public function process(
		ServerRequestInterface $request,
		RequestHandlerInterface $handler
	): ResponseInterface {
		return $handler->handle($request);
	}
}