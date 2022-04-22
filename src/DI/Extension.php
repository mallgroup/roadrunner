<?php

declare(strict_types=1);

namespace Mallgroup\RoadRunner\DI;

use Mallgroup\RoadRunner\Events;
use Mallgroup\RoadRunner\Http\Request;
use Mallgroup\RoadRunner\Http\RequestFactory;
use Mallgroup\RoadRunner\Http\Response;
use Mallgroup\RoadRunner\Middlewares\NetteApplicationMiddleware;
use Mallgroup\RoadRunner\PsrChain;
use Mallgroup\RoadRunner\RoadRunner;
use Nette;
use Nette\Http\Session;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use Nyholm\Psr7\Factory\Psr17Factory;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\WorkerInterface;
use Tracy;

/**
 * @property-read \stdClass $config
 */
class Extension extends Nette\DI\CompilerExtension
{
	const RR_FLUSH = 'RR_FLUSH';

	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'errorPresenter' => Expect::string('Nette:Error')->dynamic(),
			'catchExceptions' => Expect::bool(false)->dynamic(),
			'middlewares' => Expect::list(),
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
		$builder->addDefinition($this->prefix('request'))->setFactory(Request::class);

		# Create PSR response class
		$builder->removeDefinition('http.response');
		$builder->addDefinition($this->prefix('response'))->setFactory(Response::class);

		# Add roadrunner PSR requirements
		$builder->addDefinition($this->prefix('worker'))
				->setFactory('Spiral\RoadRunner\Worker::create')
				->setType(WorkerInterface::class)
				->setAutowired(false);
		$builder->addDefinition($this->prefix('psr17factory'))
				->setFactory(Psr17Factory::class)
				->setAutowired(false);
		$builder->addDefinition($this->prefix('psrWorker'))->setFactory(PSR7Worker::class, [
			'@' . $this->prefix('worker'),
			'@' . $this->prefix('psr17factory'),
			'@' . $this->prefix('psr17factory'),
			'@' . $this->prefix('psr17factory'),
		])->setAutowired(false);

		# Events
		$builder->addDefinition($this->prefix('events'))
				->setFactory(Events::class);

		# RoadRunner <=> PsrApplication interface
		$builder->addDefinition($this->prefix('roadrunner'))->setFactory(RoadRunner::class, [
			'@' . $this->prefix('psrWorker'),
			'@' . $this->prefix('chain'),
			'@' . $this->prefix('events'),
		]);

		# Add PSRApplication
		$builder->addDefinition($this->prefix('application'))
				->setFactory(NetteApplicationMiddleware::class)
				->addSetup('$catchExceptions', [$config->catchExceptions])
				->addSetup('$errorPresenter', [$config->errorPresenter]);

		# Session should be ours, to support RR
		/** @var Nette\DI\Definitions\ServiceDefinition $sessionDefinition */
		$sessionDefinition = $builder->getDefinitionByType(Session::class);
		$sessionDefinition->setFactory(\Mallgroup\RoadRunner\Http\Session::class)
			->setType(\Mallgroup\RoadRunner\Http\Session::class);

		# Middlewares
		$builder->addDefinition($this->prefix('chain'))
			->setFactory(PsrChain::class, [
				new Nette\PhpGenerator\Literal('new \Nyholm\Psr7\Response'),
				...$config->middlewares,
				'@' . $this->prefix('application'),
			]);

		# Setup tags
		foreach (['nette.templateFactory', 'user', 'nette.userStorage'] as $service) {
			$serviceDefinition = $builder->getDefinition($service)->addTag(self::RR_FLUSH);
		}
	}

	public function beforeCompile()
	{
		$builder = $this->getContainerBuilder();

		# Setup blueScreen if possible
		if ($builder->getByType(Tracy\BlueScreen::class)) {
			/** @var Nette\DI\Definitions\ServiceDefinition $serviceDefinition */
			$serviceDefinition = $builder->getDefinition($this->prefix('application'));
			$serviceDefinition->addSetup([self::class, 'initializeBlueScreenPanel']);
		}

		# Bind events by tags
		$this->prepareEvents($builder);
	}

	/** @internal */
	public static function initializeBlueScreenPanel(
		Tracy\BlueScreen $blueScreen,
		Nette\Http\IRequest $httpRequest,
		Nette\Http\IResponse $httpResponse,
		NetteApplicationMiddleware $application,
	): void {
		$blueScreen->addPanel(function (?\Throwable $e) use ($application, $blueScreen, $httpResponse, $httpRequest): ?array {
			/** @psalm-suppress InternalMethod */
			$dumper = $blueScreen->getDumper();
			return $e ? null : [
				'tab' => 'Psr Application',
				'panel' => '<h3>Requests</h3>' . $dumper($application->getRequests())
					. '<h3>Presenter</h3>' . $dumper($application->getPresenter())
					. '<h3>Http/Request</h3>' . $dumper($httpRequest)
					. '<h3>Http/Response</h3>' . $dumper($httpResponse),
			];
		});
	}

	private function prepareEvents(Nette\DI\ContainerBuilder $builder)
	{
		/** @var Nette\DI\Definitions\ServiceDefinition $events */
		$events = $builder->getDefinition($this->prefix('events'));
		$events->addSetup('addOnFlush', [
				new Nette\PhpGenerator\Literal(
					'function () { array_map(fn ($s) => $this->removeService($s), ?); }',
					[
						array_keys($builder->findByTag(self::RR_FLUSH))
					]
				)
			]);
	}

	/** Nette\Http\Helpers::initCookie(self::$defaultHttpRequest, new Nette\Http\Response);  */
}
