<?php

declare(strict_types=1);

namespace Mallgroup\RoadRunner\Http;

use Nette\Http\IResponse as NetteResponse;

interface IResponse extends NetteResponse
{
	public function cleanup(): void;
	public function getReason(): ?string;
}
