<?php

declare(strict_types=1);

namespace Mallgroup\RoadRunner\Http;

use Nette;
use Nette\Http\Helpers;
use Nette\Utils\DateTime;

class Response implements IResponse
{
	/** @var array<string, array<string|null>> */
	private array $headers = [];
	private int $code = self::S200_OK;
	private ?string $reason = null;
	private bool $sent = false;

	public string $cookieDomain = '';
	public string $cookiePath = '/';
	public bool $cookieSecure = false;

	public function __construct()
	{
		if (is_int($code = http_response_code())) {
			$this->code = $code;
		}
	}

	public function cleanup(): void
	{
		$this->headers = [];
		$this->code = self::S200_OK;
		$this->sent = false;
		$this->reason = null;
	}

	public function setCode(int $code, string $reason = null): static
	{
		if ($code < 100 || $code > 599) {
			throw new Nette\InvalidArgumentException("Bad HTTP response '$code'.");
		}

		$this->code = $code;
		$this->reason = $reason;
		return $this;
	}

	public function getCode(): int
	{
		return $this->code;
	}

	public function getReason(): ?string
	{
		return $this->reason;
	}

	public function setHeader(string $name, ?string $value = null): static
	{
		$this->headers[$name] = [$value];
		return $this;
	}

	public function addHeader(string $name, string $value): static
	{
		$this->headers[$name][] = $value;
		return $this;
	}

	public function setContentType(string $type, string $charset = null): static
	{
		$this->setHeader('Content-Type', $type . ($charset ? '; charset=' . $charset : ''));
		return $this;
	}

	public function redirect(string $url, int $code = self::S302_FOUND): void
	{
		$this->setCode($code);
		$this->setHeader('Location', $url);
		if (preg_match('#^https?:|^\s*+[a-z0-9+.-]*+[^:]#i', $url)) {
			$escapedUrl = htmlspecialchars($url, ENT_IGNORE | ENT_QUOTES, 'UTF-8');
			echo "<h1>Redirect</h1>\n\n<p><a href=\"$escapedUrl\">Please click here to continue</a>.</p>";
		}
	}

	public function setExpiration(?string $expire): static
	{
		$this->setHeader('Pragma', null);
		if (!$expire) { // no cache
			$this->setHeader('Cache-Control', 's-maxage=0, max-age=0, must-revalidate');
			$this->setHeader('Expires', 'Mon, 23 Jan 1978 10:00:00 GMT');
			return $this;
		}

		$time = DateTime::from($expire);
		$this->setHeader('Cache-Control', 'max-age=' . ((int) $time->format('U') - time()));
		$this->setHeader('Expires', Helpers::formatDate($time));
		return $this;
	}

	public function isSent(): bool
	{
		return $this->sent;
	}

	public function setSent(bool $sent): static
	{
		$this->sent = true;
		return $this;
	}

	public function getHeader(string $header): ?string
	{
		return $this->headers[$header][0] ?? null;
	}

	/** @return array<string, array<string|null>> */
	public function getHeaders(): array
	{
		return $this->headers;
	}

	/**
	 * @throws \Exception
	 */
	public function setCookie(
		string $name,
		string $value,
		$expire,
		?string $path = null,
		?string $domain = null,
		?bool $secure = null,
		?bool $httpOnly = null,
		?string $sameSite = null,
	): static {
		
		$headerValue = sprintf(
			'%s=%s; path=%s; SameSite=%s',
			$name,
			urlencode($value),
			$path ?? ($domain ? '/' : $this->cookiePath),
			$sameSite ?? self::SAME_SITE_LAX,
		);
		
		if ($expire) {
			$headerValue .= '; Expires='.(DateTime::from($expire)->format('D, d M Y H:i:s T'));
		}

		$cookieDomain = $domain ?? $this->cookieDomain;
		if ($cookieDomain && !$path) {
			$headerValue .= '; domain='.$cookieDomain;
		}

		if ($secure ?? $this->cookieSecure) {
			$headerValue .= '; secure';
		}

		if ($httpOnly || $httpOnly === null) {
			$headerValue .= '; HttpOnly';
		}

		$this->addHeader('Set-Cookie', $headerValue);
		return $this;
	}

	/**
	 * @throws \Exception
	 */
	public function deleteCookie(string $name, string $path = null, string $domain = null, bool $secure = null): static
	{
		$this->setCookie($name, '', 0, $path, $domain, $secure);
		return $this;
	}

	public function deleteHeader(string $name): static
	{
		unset($this->headers[$name]);
		return $this;
	}
}
