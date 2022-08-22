MallGroup/Roadrunner
======

[![build](https://github.com/mallgroup/roadrunner/actions/workflows/main.yml/badge.svg)](https://github.com/mallgroup/roadrunner/actions/workflows/main.yml)
[![Coverage Status](https://coveralls.io/repos/github/mallgroup/roadrunner/badge.svg?branch=main)](https://coveralls.io/github/mallgroup/roadrunner?branch=main)
[![Downloads this Month](https://img.shields.io/packagist/dm/mallgroup/roadrunner.svg)](https://packagist.org/packages/mallgroup/roadrunner)
[![Latest stable](https://img.shields.io/packagist/v/mallgroup/roadrunner.svg)](https://packagist.org/packages/mallgroup/roadrunner)
[![Coverage Status](https://coveralls.io/repos/github/mallgroup/roadrunner/badge.svg?branch=master)](https://coveralls.io/github/mallgroup/roadrunner?branch=master)
[![License](https://img.shields.io/badge/license-New%20BSD-blue.svg)](https://github.com/mallgroup/roadrunner/blob/master/license.md)

Integration of [RoadRunner](https://roadrunner.dev) into Nette Framework

Installation
------------

The best way to install Mallgroup/Roadrunner is using [Composer](http://getcomposer.org/):

```sh
$ composer require mallgroup/roadrunner
```

Then you need to create small update to your app.

### Configure app

Create new RR config, in our case it is roadrunner.neon
```neon
extensions:
    roadrunner: Mallgroup\RoadRunner\DI\Extension

roadrunner:
    showExceptions: %debugMode%
```

Then we need to update our bootstrap, as RR extension is still big WIP, it is recomended not to enable debug mode. So in current Bootstrap we create new static method for booting into RR plugin
```php
public static function bootRR(string $appDir): Configurator
{
    $configurator = new Configurator;

	$configurator->setTimeZone('Europe/Prague');
	$configurator->setTempDirectory($appDir . '/temp');

	$configurator->createRobotLoader()
		->addDirectory(__DIR__)
		->register();

	$configurator->addConfig($appDir . '/config/common.neon');
	$configurator->addConfig($appDir . '/config/services.neon');
	$configurator->addConfig($appDir . '/config/local.neon');
	$configurator->addConfig($appDir . '/config/roadrunner.neon');

	return $configurator;
}
```

And finally, we need our entrypoint that will be runned by RoadRunner, we call this script a roadrunner.php
```php
<?php
declare(strict_types=1);

use Mallgroup\RoadRunner\RoadRunner;
use App\Bootstrap;

define('ROOT_DIR', dirname(__DIR__));
require ROOT_DIR . '/vendor/autoload.php';

/** @psalm-suppress PossiblyNullReference */
Bootstrap::bootRR(ROOT_DIR)
		 ->createContainer()
		 ->getByType(RoadRunner::class)
		 ->run();
```
and shell counterpart (we use it for redirect of err messages)
```shell
#!/usr/bin/env sh
exec php /app-path/app/roadrunner.php 2>&3
```

Now we can run RoadRunner with our app, simply run it
```yaml
server:
    command: "sh /app-path/app/roadrunner.sh -d opcache.enable_cli=1"
    relay: "pipes"
```