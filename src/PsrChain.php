<?php

namespace Mallgroup\RoadRunner;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class PsrChain implements RequestHandlerInterface
{

	private RequestHandlerInterface $next;
	private ?MiddlewareInterface $current;

	public function __construct(RequestHandlerInterface $final, MiddlewareInterface ...$middleware)
	{
		if ($middleware === []) {
			throw new \InvalidArgumentException('at least one middleware is required for the chain');
		}
		$this->current = array_shift($middleware);
		$this->next = $middleware === [] ? $final : new self($final, ...$middleware);
	}

	public function handle(ServerRequestInterface $request): ResponseInterface
	{
		return $this->current->process($request, $this->next);
	}
}
