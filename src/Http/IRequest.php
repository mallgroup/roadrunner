<?php

declare(strict_types=1);

namespace Mallgroup\RoadRunner\Http;

use Nette\Http\IRequest as NetteRequest;
use Psr\Http\Message\ServerRequestInterface;

interface IRequest extends NetteRequest
{
	public function updateFromPsr(ServerRequestInterface $request): void;
}
