<?php

declare(strict_types=1);

namespace Mallgroup\RoadRunner\DI;

use Mallgroup\RoadRunner\PsrChain;
use Nette;
use Nette\Http\Session;
use Tracy;
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

		# Session should be ours, to support RR
		$builder->getDefinitionByType(Session::class)
			->setFactory(\Mallgroup\RoadRunner\Http\Session::class)
			->setType(\Mallgroup\RoadRunner\Http\Session::class);
	}

	public function beforeCompile()
	{
		$builder = $this->getContainerBuilder();

		# Setup blueScreen if possible
		if ($builder->getByType(Tracy\BlueScreen::class)) {
			$builder->getDefinition($this->prefix('application'))
			        ->addSetup([self::class, 'initializeBlueScreenPanel']);
		}
	}

	/** @internal */
	public static function initializeBlueScreenPanel(
		Tracy\BlueScreen $blueScreen,
		Nette\Http\IRequest $httpRequest,
		Nette\Http\IResponse $httpResponse,
		PsrApplication $application,
	): void {
		$blueScreen->addPanel(function (?\Throwable $e) use ($application, $blueScreen, $httpResponse, $httpRequest): ?array {
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

	/** Nette\Http\Helpers::initCookie(self::$defaultHttpRequest, new Nette\Http\Response);  */
}
