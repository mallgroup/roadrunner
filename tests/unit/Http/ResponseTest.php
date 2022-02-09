<?php declare(strict_types=1);

use Mallgroup\RoadRunner\Http\IResponse;
use Mallgroup\RoadRunner\Http\Response;
use Nette\InvalidArgumentException;
use Nette\Utils\DateTime;
use Tester\Assert;
use Nette\Http\Helpers;

require __DIR__ . '/../bootstrap.php';

test('defaults', function () {
	$response = new Response();
	Assert::same(IResponse::S200_OK, $response->getCode());
	Assert::equal([], $response->getHeaders());
	Assert::null($response->getReason());
});

test('set code', function () {
	http_response_code(201);

	$response = new Response();
	Assert::same(201, $response->getCode());

	$response->setCode(200, 'Ok');
	Assert::same(200, $response->getCode());
	Assert::same('Ok', $response->getReason());

	$response->setCode(201, 'Ok');
	Assert::same(201, $response->getCode());
	Assert::same('Ok', $response->getReason());

	Assert::exception(fn() => $response->setCode(99), InvalidArgumentException::class);
	Assert::exception(fn() => $response->setCode(600), InvalidArgumentException::class);

	$response->setCode(599, 'Not very ok');
	Assert::same(599, $response->getCode());
	Assert::same('Not very ok', $response->getReason());
});

test('set sent', function () {
	$response = new Response();
	Assert::false($response->isSent());

	$response->setSent(true);
	Assert::true($response->isSent());

	$response->cleanup();
	Assert::false($response->isSent());
});

test('set sent', function () {
	$response = new Response();
	Assert::false($response->isSent());

	$response->setSent(true);
	Assert::true($response->isSent());

	$response->cleanup();
	Assert::false($response->isSent());
});

test('set header', function () {
	$response = new Response();
	$response->setHeader('test', 'value');
	$response->setHeader('test', 'test value');
	Assert::same('test value', $response->getHeader('test'));
	
	$headers = $response->getHeaders();
	Assert::same('test value', $headers['test'][0]);
});

test('delete header', function () {
	$response = new Response();
	$response->setHeader('test', 'value');
	$response->deleteHeader('test');
	Assert::null($response->getHeader('test'));
});

test('add header', function () {
	$response = new Response();
	$response->addHeader('test', 'value');
	$response->addHeader('test', 'value2');
	Assert::same('value', $response->getHeader('test'));
	
	$headers = $response->getHeaders();
	Assert::same('value', $headers['test'][0]);
	Assert::same('value2', $headers['test'][1]);
});

test('cleanup', function () {
	$response = new Response();
	$response->addHeader('test', 'value');
	$response->setCode(599, 'Test');
	$response->cleanup();

	Assert::same(IResponse::S200_OK, $response->getCode());
	Assert::equal([], $response->getHeaders());
	Assert::null($response->getReason());
});

test('set expiration', function () {
	$response = new Response();
	$response->setExpiration(null);

	Assert::equal(3, count($response->getHeaders()));
	Assert::same('s-maxage=0, max-age=0, must-revalidate', $response->getHeader('Cache-Control'));
	Assert::same('Mon, 23 Jan 1978 10:00:00 GMT', $response->getHeader('Expires'));
	Assert::null($response->getHeader('Pragma'));

	$currentDateTime = new DateTime('now');
	$maxAge = ((int) $currentDateTime->format('U') - time());
	$response->setExpiration($currentDateTime->format(DateTime::ISO8601));

	Assert::equal(3, count($response->getHeaders()));
	Assert::same('max-age='.$maxAge, $response->getHeader('Cache-Control'));
	Assert::same(Helpers::formatDate($currentDateTime), $response->getHeader('Expires'));
	Assert::null($response->getHeader('Pragma'));
});


test('content-type header', function () {
	$response = new Response();
	$response->setContentType('application/json');
	Assert::same('application/json', $response->getHeader('Content-Type'));

	$response->setContentType('text/plain', 'utf8');
	Assert::same('text/plain; charset=utf8', $response->getHeader('Content-Type'));
});

test('redirect', function () {
	$response = new Response();

	ob_start();
	$response->redirect('https://www.domain.com', 301);
	$content = ob_get_clean();

	Assert::same(301, $response->getCode());
	Assert::same('https://www.domain.com', $response->getHeader('Location'));
	Assert::contains('https://www.domain.com', $content);

	ob_start();
	$response->redirect('ftp://www.domain.com/file', 302);
	$content = ob_get_clean();

	Assert::same(302, $response->getCode());
	Assert::same('ftp://www.domain.com/file', $response->getHeader('Location'));
	Assert::notContains('ftp://www.domain.com', $content);
});

test('setCookie', function () {
	$response = new Response();
	$response->setCookie('test', 'value', 0);
	Assert::same(
		'test=value; path=/; SameSite=Lax; HttpOnly',
		$response->getHeader('Set-Cookie')
	);
	
	$response->setCookie('test', 'newvalue', 0);
	Assert::notNull($response->getHeaders()['Set-Cookie'] ?? null);
	Assert::same(
		['test=value; path=/; SameSite=Lax; HttpOnly', 'test=newvalue; path=/; SameSite=Lax; HttpOnly'],
		$response->getHeaders()['Set-Cookie'],
	);
});

test('setCookie with expire', function () {
	$response = new Response();
	$time = time() + 300;

	$response->setCookie('test', 'newvalue', $time, secure: true);
	Assert::notNull($response->getHeaders()['Set-Cookie'] ?? null);
	Assert::same(
		['test=newvalue; path=/; SameSite=Lax; Expires=' . DateTime::from($time)->format('D, d M Y H:i:s T') . '; secure; HttpOnly'],
		$response->getHeaders()['Set-Cookie'],
	);
});

test('deleteCookie', function () {
	$response = new Response();
	$response->deleteCookie('test');
	Assert::same(
		['test=; path=/; SameSite=Lax; HttpOnly'],
		$response->getHeaders()['Set-Cookie'],
	);

	$response->cleanup();
	$response->deleteCookie('test', secure: true);
	Assert::same(
		['test=; path=/; SameSite=Lax; secure; HttpOnly'],
		$response->getHeaders()['Set-Cookie'],
	);
});

test('setCookie - cookiePath', function () {
	$response = new Response();
	$response->cookiePath = '/foo';
	$response->setCookie('test', 'a', 0);
	Assert::same(
		'test=a; path=/foo; SameSite=Lax; HttpOnly',
		$response->getHeader('Set-Cookie')
	);
});

test('setCookie - cookiePath + path', function () {
	$response = new Response();
	$response->cookiePath = '/foo';
	$response->setCookie('test', 'b', 0, '/bar');
	Assert::same(
		'test=b; path=/bar; SameSite=Lax; HttpOnly',
		$response->getHeader('Set-Cookie')
	);
});

test('setCookie - cookiePath + domain', function () {
	$response = new Response();
	$response->cookiePath = '/foo';
	$response->setCookie('test', 'c', 0, null, 'nette.org');
	Assert::same(
		'test=c; path=/; SameSite=Lax; domain=nette.org; HttpOnly',
		$response->getHeader('Set-Cookie')
	);
});

test('setCookie - cookieDomain', function () {
	$response = new Response();
	$response->cookieDomain = 'nette.org';
	$response->setCookie('test', 'd', 0);
	Assert::same(
		'test=d; path=/; SameSite=Lax; domain=nette.org; HttpOnly',
		$response->getHeader('Set-Cookie')
	);
});

test('setCookie - cookieDomain + path', function () {
	$response = new Response();
	$response->cookieDomain = 'nette.org';
	$response->setCookie('test', 'e', 0, '/bar');
	Assert::same(
		'test=e; path=/bar; SameSite=Lax; HttpOnly',
		$response->getHeader('Set-Cookie')
	);
});

test('setCookie - cookieDomain + domain', function () {
	$response = new Response();
	$response->cookieDomain = 'nette.org';
	$response->setCookie('test', 'f', 0, null, 'example.org');
	Assert::same(
		'test=f; path=/; SameSite=Lax; domain=example.org; HttpOnly',
		$response->getHeader('Set-Cookie')
	);
});
