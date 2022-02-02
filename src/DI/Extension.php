<?php

declare(strict_types=1);

namespace Mallgroup\RoadRunner\DI;

use Nette;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use Nyholm\Psr7\Factory\Psr17Factory;
use Mallgroup\RoadRunner\Http\IRequest;
use Mallgroup\RoadRunner\Http\IResponse;
use Mallgroup\RoadRunner\Http\RequestFactory;
use Mallgroup\RoadRunner\Http\Request;
use Mallgroup\RoadRunner\Http\Response;
use Mallgroup\RoadRunner\PsrApplication;
use Mallgroup\RoadRunner\RoadRunner;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\WorkerInterface;

/**
 * @property-read \stdClass $config
 */
class Extension extends Nette\DI\CompilerExtension
{
	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'showExceptions' => Expect::bool(false),
			'errorPresenter' => Expect::string('Nette:Error')->dynamic(),
			'catchExceptions' => Expect::bool(false)->dynamic(),
		]);
	}

	public function loadConfiguration()
	{
		$builder = $this->getContainerBuilder();
		$config = $this->config;

		$builder->removeDefinition('http.requestFactory');
		$builder->addDefinition($this->prefix('requestFactory'))->setFactory(RequestFactory::class);
		$builder->addAlias('http.requestFactory', $this->prefix('requestFactory'));

		# Create PSR request class
		$builder->removeDefinition('http.request');
		$builder->addDefinition($this->prefix('request'))->setFactory(Request::class)->setType(IRequest::class);

		# Create PSR response class
		$builder->removeDefinition('http.response');
		$builder->addDefinition($this->prefix('response'))->setFactory(Response::class)->setType(IResponse::class);

		# Add roadrunner PSR requirements
		$builder->addDefinition($this->prefix('worker'))->setFactory('Spiral\RoadRunner\Worker::create')->setType(WorkerInterface::class)->setAutowired(false);
		$builder->addDefinition($this->prefix('psr17factory'))->setFactory(Psr17Factory::class)->setAutowired(false);
		$builder->addDefinition($this->prefix('psrWorker'))->setFactory(PSR7Worker::class, [
			'@' . $this->prefix('worker'),
			'@' . $this->prefix('psr17factory'),
			'@' . $this->prefix('psr17factory'),
			'@' . $this->prefix('psr17factory'),
		])->setAutowired(false);

		# RoadRunner <=> PsrApplication interface
		$builder->addDefinition($this->prefix('roadrunner'))->setFactory(RoadRunner::class, [
			'@' . $this->prefix('psrWorker'),
			'@container',
			$config->showExceptions,
		]);

		# Add PSRApplication
		$builder->addDefinition($this->prefix('application'))
				->setFactory(PsrApplication::class)
				->addSetup('$catchExceptions', [$config->catchExceptions])
				->addSetup('$errorPresenter', [$config->errorPresenter]);

		# Setup container
		/** @var Nette\DI\Definitions\ServiceDefinition $definition */
		$definition = $builder->getDefinition('container');
		$definition->setType(Container::class);
	}
}
