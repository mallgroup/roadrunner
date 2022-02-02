<?php

declare(strict_types=1);

namespace Mallgroup\RoadRunner\Http;

use Nette\Http\Helpers;
use Nette\Http\Request;
use Nette\Http\Url;
use Nette\Http\UrlScript;
use Psr\Http\Message\ServerRequestInterface;

/**
 * TODO: Polish this a bit more, this is just ugly code to make this works
 */
class RequestFactory
{
	/** @var string[] */
	private array $proxies = [];
	private static ServerRequestInterface $request;

	/** @param string[] $proxies */
	public function setProxy(array $proxies): void
	{
		$this->proxies = $proxies;
	}

	public static function setRequest(ServerRequestInterface $request): void
	{
		self::$request = $request;
	}

	public static function fromGlobals(): Request
	{
		return (new RequestFactory())->fromPsr(self::$request);
	}

	public static function fromRequest(ServerRequestInterface $request): Request
	{
		return (new RequestFactory())->fromPsr($request);
	}

	public function fromPsr(ServerRequestInterface $request): Request
	{
		$uri = $request->getUri();
		$url = $this->createUrlFromRequest($request);
		$url->setQuery($uri->getQuery());

		[$remoteAddr, $remoteHost] = $this->getClient($url);

		return new Request(
			new UrlScript($url, $this->getScriptPath($url)),
			(array)$request->getParsedBody(),
			$request->getUploadedFiles(),
			$request->getCookieParams(),
			$this->mapHeaders($request->getHeaders()),
			$request->getMethod(),
			$remoteAddr,
			$remoteHost,
			fn(): string => (string)$request->getBody()
		);
	}

	private function getScriptPath(Url $url): string
	{
		$path = $url->getPath();
		$lpath = strtolower($path);
		$script = strtolower($_SERVER['SCRIPT_NAME'] ?? '');
		if ($lpath !== $script) {
			$max = min(strlen($lpath), strlen($script));
			for ($i = 0; $i < $max && $lpath[$i] === $script[$i]; $i++) ;
			$path = $i
				? substr($path, 0, strrpos($path, '/', $i - strlen($path) - 1) + 1)
				: '/';
		}
		return $path;
	}

	/** @return string[] */
	private function getClient(Url $url): array
	{
		$remoteAddr = !empty($_SERVER['REMOTE_ADDR'])
			? trim($_SERVER['REMOTE_ADDR'], '[]') // workaround for PHP 7.3.0
			: null;
		$remoteHost = !empty($_SERVER['REMOTE_HOST'])
			? $_SERVER['REMOTE_HOST']
			: null;

		// use real client address and host if trusted proxy is used
		$usingTrustedProxy = $remoteAddr && array_filter($this->proxies, function (string $proxy) use ($remoteAddr): bool {
				return Helpers::ipMatch($remoteAddr, $proxy);
		});
		if ($usingTrustedProxy) {
			empty($_SERVER['HTTP_FORWARDED'])
				? $this->useNonstandardProxy($url, $remoteAddr, $remoteHost)
				: $this->useForwardedProxy($url, $remoteAddr, $remoteHost);
		}

		return [$remoteAddr, $remoteHost];
	}

	private function useForwardedProxy(Url $url, ?string &$remoteAddr, ?string &$remoteHost): void
	{
		/** @var array<int, string> $forwardParams */
		$forwardParams = preg_split('/[,;]/', $_SERVER['HTTP_FORWARDED']);
		foreach ($forwardParams as $forwardParam) {
			[$key, $value] = explode('=', $forwardParam, 2) + [1 => ''];
			$proxyParams[strtolower(trim($key))][] = trim($value, " \t\"");
		}

		if (isset($proxyParams['for'])) {
			$address = $proxyParams['for'][0];
			$remoteAddr = strpos($address, '[') === false
				? explode(':', $address)[0]  // IPv4
				: substr($address, 1, strpos($address, ']') - 1); // IPv6
		}

		if (isset($proxyParams['host']) && count($proxyParams['host']) === 1) {
			$host = $proxyParams['host'][0];
			$startingDelimiterPosition = strpos($host, '[');
			if ($startingDelimiterPosition === false) { //IPv4
				$remoteHostArr = explode(':', $host);
				$remoteHost = $remoteHostArr[0];
				$url->setHost($remoteHost);
			} else { //IPv6
				$endingDelimiterPosition = (int) strpos($host, ']');
				$remoteHost = substr($host, strpos($host, '[') + 1, $endingDelimiterPosition - 1);
				$url->setHost($remoteHost);
				$remoteHostArr = explode(':', substr($host, $endingDelimiterPosition));
			}
			if (isset($remoteHostArr[1])) {
				$url->setPort((int)$remoteHostArr[1]);
			}
		}

		$scheme = (isset($proxyParams['proto']) && count($proxyParams['proto']) === 1)
			? $proxyParams['proto'][0]
			: 'http';
		$url->setScheme(strcasecmp($scheme, 'https') === 0 ? 'https' : 'http');
	}

	private function useNonstandardProxy(Url $url, ?string &$remoteAddr, ?string &$remoteHost): void
	{
		if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
			$url->setScheme(strcasecmp($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') === 0 ? 'https' : 'http');
			$url->setPort($url->getScheme() === 'https' ? 443 : 80);
		}

		if (!empty($_SERVER['HTTP_X_FORWARDED_PORT'])) {
			$url->setPort((int)$_SERVER['HTTP_X_FORWARDED_PORT']);
		}

		if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$xForwardedForWithoutProxies = array_filter(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']), function (string $ip): bool {
				return !array_filter($this->proxies, function (string $proxy) use ($ip): bool {
					return filter_var(trim($ip), FILTER_VALIDATE_IP) !== false && Helpers::ipMatch(trim($ip), $proxy);
				});
			});
			if ($xForwardedForWithoutProxies) {
				$remoteAddr = trim(end($xForwardedForWithoutProxies));
				$xForwardedForRealIpKey = key($xForwardedForWithoutProxies);
			}
		}

		if (isset($xForwardedForRealIpKey) && !empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
			$xForwardedHost = explode(',', $_SERVER['HTTP_X_FORWARDED_HOST']);
			if (isset($xForwardedHost[$xForwardedForRealIpKey])) {
				$remoteHost = trim($xForwardedHost[$xForwardedForRealIpKey]);
				$url->setHost($remoteHost);
			}
		}
	}

	private function createUrlFromRequest(ServerRequestInterface $request): Url
	{
		$url = new Url;
		$uri = $request->getUri();

		$url->setScheme($uri->getScheme());
		$url->setHost($uri->getHost());
		$url->setPort($uri->getPort());
		$url->setPath($uri->getPath());
		$url->setQuery($uri->getQuery());

		// Authorization
		$params = $request->getServerParams();
		if (isset($params['HTTP_AUTHORIZATION']) && preg_match('~^Basic\s(.*?)$~', $params['HTTP_AUTHORIZATION'], $matches)) {
			[$user, $pass] = explode(':', base64_decode($matches[1]));
			$url->setUser($user);
			$url->setPassword($pass);
		}

		return $url;
	}

	/**
	 * @param array<string, string[]> $headers
	 * @return array<string, string>
	 */
	private function mapHeaders(array $headers): array
	{
		return array_map(static fn(array $header) => implode("\n", $header), $headers);
	}
}
