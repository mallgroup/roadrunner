<?php

namespace Mallgroup\RoadRunner\Utils;

use Tracy\Helpers;
use function fwrite;

class Dumper extends \Tracy\Dumper
{
	private static bool $htmlMode = false;

	public static function setHtmlMode(bool $value = false): void {
		self::$htmlMode = $value;
	}

	public static function dump($var, array $options = []): void
	{
		if (self::$htmlMode) {
			$options[self::LOCATION] = $options[self::LOCATION] ?? true;
			echo self::toHtml($var, $options);
		} else {
			echo self::toText($var, $options);
		}
	}
}
