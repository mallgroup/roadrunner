<?php
declare(strict_types=1);

namespace Mallgroup\RoadRunner\Middlewares;

use Nette\Http\IResponse;
use Nette\Http\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

class SessionMiddleware implements MiddlewareInterface
{
	public function __construct(private Session $session, private IResponse $response)
	{
		$this->session->setOptions($this->session->getOptions() + ['cache_limiter' => '']);
	}

	/**
	 * Process a server request and return a response.
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		if (session_status() === PHP_SESSION_ACTIVE || session_id() || !empty($_SESSION)) {
			throw new RuntimeException('Potential session leak.');
		}

		$this->session->start();

		$this->sendCookie();

		try {
			return $handler->handle($request);
		} finally {
			$this->session->close();

			// convince Nette Session to read the cookies
			session_id('');
			// clear the previous session
			$_SESSION = [];
		}
	}

	/**
	 * Sends the session cookies.
	 */
	private function sendCookie(): void
	{
		$cookie = session_get_cookie_params();
		/**
		 * @phpstan-ignore-next-line
		 * @psalm-suppress TooManyArguments
		 */
		$this->response->setCookie(
			session_name(),
			session_id(),
			$cookie['lifetime'] ? $cookie['lifetime'] + time() : 0,
			$cookie['path'],
			$cookie['domain'],
			$cookie['secure'],
			$cookie['httponly'],
			$cookie['samesite'] ?? null,
		);
	}
}
