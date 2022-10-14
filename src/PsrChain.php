<?php

namespace Mallgroup\RoadRunner;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class PsrChain implements RequestHandlerInterface
{
	/** @var MiddlewareInterface[] */
	private array $middlewares;

	public function __construct(private ResponseInterface $response, MiddlewareInterface ...$middlewares)
	{
		$this->middlewares = $middlewares;
	}

	private function withoutMiddleware(MiddlewareInterface $middleware): RequestHandlerInterface
	{
		return new self(
			$this->response,
			...array_filter(
				$this->middlewares,
				static function ($m) use ($middleware) {
					return $middleware !== $m;
				}
			)
		);
	}

	public function handle(ServerRequestInterface $request): ResponseInterface
	{
		$middleware = $this->middlewares[0] ?? false;

		return $middleware
			? $middleware->process(
				$request,
				$this->withoutMiddleware($middleware)
			)
			: $this->response;
	}
}
