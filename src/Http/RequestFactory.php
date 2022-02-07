<?php

declare(strict_types=1);

namespace Mallgroup\RoadRunner\Http;

use Nette\Http\FileUpload;
use Nette\Http\Helpers;
use Nette\Http\Request;
use Nette\Http\Url;
use Nette\Http\UrlScript;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

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

	public static function fromRequest(ServerRequestInterface $request = null): Request
	{
		return (new RequestFactory())->fromPsr($request ?: self::$request);
	}

	public function fromPsr(ServerRequestInterface $request): Request
	{
		$uri = $request->getUri();
		$url = $this->createUrlFromRequest($request);
		$url->setQuery($uri->getQuery());

		$this->setAuthorization($url, $uri);

		[$remoteAddr, $remoteHost] = $this->getClient($request, $url);

		return new Request(
			new UrlScript($url, $this->getScriptPath($url)),
			(array)$request->getParsedBody(),
			$this->mapUploadedFiles($request->getUploadedFiles()),
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
	private function getClient(ServerRequestInterface $request, Url $url): array
	{
		$serverParams = $request->getServerParams();
		$remoteAddr = $serverParams['REMOTE_ADDR'] ?? ($request->getHeader('REMOTE_ADDR')[0] ?? null);
		$remoteHost = $serverParams['REMOTE_HOST'] ?? ($request->getHeader('REMOTE_HOST')[0] ?? null);

		// use real client address and host if trusted proxy is used
		$usingTrustedProxy = $remoteAddr && array_filter($this->proxies, function (string $proxy) use ($remoteAddr): bool {
				return Helpers::ipMatch($remoteAddr, $proxy);
		});

		if ($usingTrustedProxy) {
			if (empty($request->getHeader('HTTP_FORWARDED'))) {
				$this->useNonstandardProxy($url, $request, $remoteAddr, $remoteHost);
			} else {
				$this->useForwardedProxy($url, $request->getHeader('HTTP_FORWARDED'), $remoteAddr, $remoteHost);
			}
		}

		return [$remoteAddr, $remoteHost];
	}

	private function useForwardedProxy(
		Url $url,
		array $forwardParams,
		?string &$remoteAddr,
		?string &$remoteHost
	): void {
		/** @var array<int, string> $forwardParams */
		foreach ($forwardParams as $forwardParam) {
			[$key, $value] = explode('=', $forwardParam, 2) + [1 => ''];
			$proxyParams[strtolower(trim($key))][] = trim($value, " \t\"");
		}

		if (isset($proxyParams['for'])) {
			$address = $proxyParams['for'][0];
			$remoteAddr = !str_contains($address, '[')
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

	private function useNonstandardProxy(
		Url $url,
		ServerRequestInterface $request,
		?string &$remoteAddr,
		?string &$remoteHost
	): void {

		if (isset($request->getHeader('HTTP_X_FORWARDED_PROTO')[0])) {
			$url->setScheme(strcasecmp($request->getHeader('HTTP_X_FORWARDED_PROTO')[0], 'https') === 0 ? 'https' : 'http');
			$url->setPort($url->getScheme() === 'https' ? 443 : 80);
		}

		if (isset($request->getHeader('HTTP_X_FORWARDED_PORT')[0])) {
			$url->setPort((int)$request->getHeader('HTTP_X_FORWARDED_PORT')[0]);
		}

		if (!empty($request->getHeader('HTTP_X_FORWARDED_FOR'))) {
			$xForwardedForWithoutProxies = array_filter(
				$request->getHeader('HTTP_X_FORWARDED_FOR'),
				function (string $ip): bool {
					return !array_filter($this->proxies, function (string $proxy) use ($ip): bool {
						return filter_var(trim($ip), FILTER_VALIDATE_IP) !== false && Helpers::ipMatch(trim($ip), $proxy);
					});
				}
			);
			if ($xForwardedForWithoutProxies) {
				$remoteAddr = trim(end($xForwardedForWithoutProxies));
				$xForwardedForRealIpKey = key($xForwardedForWithoutProxies);
			}
		}

		if (isset($xForwardedForRealIpKey) && !empty($request->getHeader('HTTP_X_FORWARDED_HOST'))) {
			$xForwardedHost = $request->getHeader('HTTP_X_FORWARDED_HOST');
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

		return $url;
	}

	private function setAuthorization(Url $url, UriInterface $uri): void
	{
		$user = $uri->getUserInfo();
		$pass = '';

		if (str_contains($user, ':')) {
			[$user, $pass] = explode(':', $user, 2);
		}

		$url->setUser($user);
		$url->setPassword($pass);
	}

	/**
	 * @param array<string, string[]> $headers
	 * @return array<string, string>
	 */
	private function mapHeaders(array $headers): array
	{
		return array_map(static fn(array $header) => implode("\n", $header), $headers);
	}

	/**
	 * @param UploadedFileInterface[] $uploadedFiles
	 * @return FileUpload[]
	 */
	private function mapUploadedFiles(array $uploadedFiles): array
	{
		return array_map(static fn(UploadedFileInterface $file) => new FileUpload([
			'name' => $file->getClientFilename(),
			'size' => $file->getSize(),
			'error' => $file->getError(),
			'tmpName' => $file->getStream()->getMetadata('uri'),
		]), $uploadedFiles);
	}
}
