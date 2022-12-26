<?php

declare(strict_types=1);

namespace Mallgroup\RoadRunner\DI;

use Mallgroup\RoadRunner\Events;
use Mallgroup\RoadRunner\Http\Request;
use Mallgroup\RoadRunner\Http\RequestFactory;
use Mallgroup\RoadRunner\Http\Response;
use Mallgroup\RoadRunner\NetteApplicationHandler;
use Mallgroup\RoadRunner\PsrChain;
use Mallgroup\RoadRunner\RoadRunner;
use Nette;
use Nette\DI\ContainerBuilder;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\WorkerInterface;
use Tracy;

/**
 * @property \stdClass $config
 * @psalm-property object{errorPresenter:string, catchExceptions:bool, middlewares:list<mixed>}&\stdClass $config
 */
class Extension extends Nette\DI\CompilerExtension
{
	public const RR_FLUSH = 'RR_FLUSH';
	private const RR_FLUSHABLE_SERVICES = ['nette.templateFactory', 'user', 'nette.userStorage'];

	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'errorPresenter' => Expect::string('Nette:Error')->dynamic(),
			'catchExceptions' => Expect::bool(false)->dynamic(),
			'middlewares' => Expect::list(),
		]);
	}

	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();

		# Setup events
		$this->createEventsDefinition($builder);

		# replace RequestFactory, Request, Response
		$this->replaceNetteHttpStuff($builder);

		# Create PSR workers
		$this->createPsrStuff($builder);

		# NetteApplication middleware, this one is added by default
		$this->createApplication($builder);

		# Roadrunner <=> Nette bridge
		$this->createRoadRunner($builder);

		# Middlewares
		$this->createMiddlewareChain($builder);

		$this->setupTagsEvents($builder);
	}

	public function beforeCompile()
	{
		$builder = $this->getContainerBuilder();

		# Setup blueScreen if possible
		if ($builder->getByType(Tracy\BlueScreen::class)) {
			/** @var ServiceDefinition $serviceDefinition */
			$serviceDefinition = $builder->getDefinition($this->prefix('application'));
			$serviceDefinition->addSetup([self::class, 'initializeBlueScreenPanel']);
		}

		# Bind events by tags
		/** @var ServiceDefinition $events */
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

	public function afterCompile(Nette\PhpGenerator\ClassType $class)
	{
		$initialize = new Nette\PhpGenerator\Closure;

		# add initialize to container
		$class->getMethod('initialize')->addBody("// roadrunner\n($initialize)();");
	}

	/** @internal */
	public static function initializeBlueScreenPanel(
		Tracy\BlueScreen $blueScreen,
		Nette\Http\IRequest $httpRequest,
		Nette\Http\IResponse $httpResponse,
		NetteApplicationHandler $application,
	): void {
		$blueScreen->addPanel(
			function (?\Throwable $e) use ($application, $blueScreen, $httpResponse, $httpRequest): ?array {
				/** @psalm-suppress InternalMethod */
				$dumper = $blueScreen->getDumper();
				return $e ? null : [
					'tab' => 'Psr Application',
					'panel' => '<h3>Requests</h3>' . $dumper($application->getRequests())
						. '<h3>Presenter</h3>' . $dumper($application->getPresenter())
						. '<h3>Http/Request</h3>' . $dumper($httpRequest)
						. '<h3>Http/Response</h3>' . $dumper($httpResponse),
				];
			}
		);
	}

	private function setupTagsEvents(ContainerBuilder $builder)
	{
		foreach (self::RR_FLUSHABLE_SERVICES as $service) {
			if ($builder->hasDefinition($service)) {
				$builder->getDefinition($service)->addTag(self::RR_FLUSH);
			}
		}
	}

	protected function createEventsDefinition(ContainerBuilder $builder): ServiceDefinition
	{
		return $this->createServiceDefinition($builder, 'events')
			->setFactory(Events::class);
	}

	protected function replaceNetteHttpStuff(ContainerBuilder $builder)
	{
		# Replace requestFactory
		$builder->removeDefinition('http.requestFactory');
		$builder->addDefinition($this->prefix('requestFactory'))->setFactory(RequestFactory::class);
		$builder->addAlias('http.requestFactory', $this->prefix('requestFactory'));

		# Create PSR request class
		$builder->removeDefinition('http.request');
		$builder->addDefinition($this->prefix('request'))->setFactory(Request::class);

		# Create PSR response class
		$builder->removeDefinition('http.response');
		$builder->addDefinition($this->prefix('response'))->setFactory(Response::class);
	}

	protected function createPsrStuff(ContainerBuilder $builder)
	{
		$this->createServiceDefinition($builder, 'worker')
			->setFactory('Spiral\RoadRunner\Worker::create')
			->setType(WorkerInterface::class)
			->setAutowired(false);

		$this->createServiceDefinition($builder, 'psrWorker')
			->setFactory(
				PSR7Worker::class,
				[
					'@' . $this->prefix('worker'),
					'@' . ServerRequestFactoryInterface::class,
					'@' . StreamFactoryInterface::class,
					'@' . UploadedFileFactoryInterface::class,
				]
			)
			->setAutowired(false);
	}

	private function createServiceDefinition(ContainerBuilder $builder, string $name): ServiceDefinition
	{
		return $builder->addDefinition($this->prefix($name));
	}

	protected function createApplication(ContainerBuilder $builder)
	{
		$this->createServiceDefinition($builder, 'application')
			->setFactory(NetteApplicationHandler::class)
			->setAutowired(NetteApplicationHandler::class)
			->addSetup('$catchExceptions', [$this->config->catchExceptions])
			->addSetup('$errorPresenter', [$this->config->errorPresenter])
			->addSetup('$onResponse[] = ?', [
				(string)(new Nette\PhpGenerator\Literal(
					'function() { Nette\Http\Helpers::initCookie($this->getService(?), $this->getService(?));};',
					[$this->prefix('response'), $this->prefix('request')]
				))
			]);
	}

	protected function createRoadRunner(ContainerBuilder $builder)
	{
		$this->createServiceDefinition($builder, 'roadrunner')
			->setFactory(
				RoadRunner::class,
				[
					'@' . $this->prefix('psrWorker'),
					'@' . $this->prefix('handler'),
					'@' . $this->prefix('events'),
				]
			);
	}

	protected function createMiddlewareChain(ContainerBuilder $builder)
	{
		if (empty($this->config->middlewares)) {
			$builder->addAlias($this->prefix('handler'), $this->prefix('application'));
		} else {
			$this->createServiceDefinition($builder, 'handler')
				->setAutowired(false)
				->setFactory(PsrChain::class, [
				'@' . $this->prefix('application'),
				...$this->config->middlewares,
			]);
		}
	}
}
