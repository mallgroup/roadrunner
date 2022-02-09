<?php

declare(strict_types=1);

namespace Mallgroup\RoadRunner\Http;

use Nette\Http;
use Psr\Http\Message\ServerRequestInterface;

class Request implements IRequest
{
	private ?Http\Request $request = null;

	public function __construct(
		private RequestFactory $requestFactory
	) {
	}

	public function updateFromPsr(ServerRequestInterface $request): void
	{
		$this->request = $this->requestFactory->getRequest($request);
	}

	public function getUrl(): Http\UrlScript
	{
		return $this->getRequest()->getUrl();
	}

	public function getQuery(string $key = null)
	{
		return $this->getRequest()->getQuery(...func_get_args());
	}

	public function getPost(string $key = null)
	{
		return $this->getRequest()->getPost(...func_get_args());
	}

	public function getFile(string $key): ?Http\FileUpload
	{
		return $this->getRequest()->getFile($key);
	}

	/** @return Http\FileUpload[] */
	public function getFiles(): array
	{
		return $this->getRequest()->getFiles();
	}

	public function getCookie(string $key)
	{
		return $this->getRequest()->getCookie($key);
	}

	/** @return mixed[] */
	public function getCookies(): array
	{
		return $this->getRequest()->getCookies();
	}

	public function getMethod(): string
	{
		return $this->getRequest()->getMethod();
	}

	public function isMethod(string $method): bool
	{
		return $this->getRequest()->isMethod($method);
	}

	public function getHeader(string $header): ?string
	{
		return $this->getRequest()->getHeader($header);
	}

	/** @return array<string, string[]> */
	public function getHeaders(): array
	{
		return $this->getRequest()->getHeaders();
	}

	public function isSecured(): bool
	{
		return $this->getRequest()->isSecured();
	}

	public function isAjax(): bool
	{
		return $this->getRequest()->isAjax();
	}

	public function getRemoteAddress(): ?string
	{
		return $this->getRequest()->getRemoteAddress();
	}

	public function getRemoteHost(): ?string
	{
		return $this->getRequest()->getRemoteHost();
	}

	public function getRawBody(): ?string
	{
		return $this->getRequest()->getRawBody();
	}

	public function getReferer(): ?Http\UrlImmutable
	{
		return $this->getRequest()->getReferer();
	}

	public function isSameSite(): bool
	{
		return $this->getRequest()->isSameSite();
	}

	private function getRequest(): Http\Request
	{
		if (null === $this->request) {
			throw new \RuntimeException('Request is not set.');
		}
		return $this->request;
	}
}
