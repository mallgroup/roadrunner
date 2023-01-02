<?php

namespace Mallgroup\RoadRunner\Middlewares;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class OutputBufferMiddleware implements MiddlewareInterface
{
	public function __construct(
		private ?LoggerInterface $logger = null,
	) {
	}

	public function process(
		ServerRequestInterface $request,
		RequestHandlerInterface $handler,
	): ResponseInterface {
		try {
			ob_start();
			return $handler->handle($request);
		} finally {
			$content = ob_get_clean();
			if ($content) {
				$this->logger?->warning(
					'Unexpected output found on request, you are pushing to output instead of Response',
					[
						'length' => strlen($content),
						'content' => substr($content, 0, 300) . (strlen($content) > 300 ? '... (shorted)' : ''),
					],
				);
			}
		}
	}
}
