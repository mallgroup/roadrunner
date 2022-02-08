<?php

use Mallgroup\RoadRunner\Http\IResponse;
use Mallgroup\RoadRunner\Http\Response;
use Nette\InvalidArgumentException;
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
	$response = new Response();
	$response->setCode(201, 'Ok');
	Assert::same(201, $response->getCode());
	Assert::same('Ok', $response->getReason());

	Assert::exception(fn() => $response->setCode(99), InvalidArgumentException::class);
	Assert::exception(fn() => $response->setCode(600), InvalidArgumentException::class);

	$response->setCode(599, 'Not very ok');
	Assert::same(599, $response->getCode());
	Assert::same('Not very ok', $response->getReason());
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
