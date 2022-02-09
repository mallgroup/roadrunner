<?php

declare(strict_types=1);

namespace Mallgroup\RoadRunner;

use Nette;
use Nette\Application\AbortException;
use Nette\Application\ApplicationException;
use Nette\Application\BadRequestException;
use Nette\Application\InvalidPresenterException;
use Nette\Application\IPresenter;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Responses;
use Nette\Application\UI;
use Nette\Routing\Router;
use Nette\Utils\Arrays;
use Nyholm\Psr7\Response as PsrResponse;
use Mallgroup\RoadRunner\Http\IRequest;
use Mallgroup\RoadRunner\Http\IResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

class PsrApplication
{
	use Nette\SmartObject;

	public int $maxLoop = 10;
	public bool $catchExceptions = true;
	public ?string $errorPresenter = null;

	/** @var array<callable(self): void>  Occurs before the application loads presenter */
	public $onStartup = [];

	/** @var array<callable(self, ?Throwable): void>  Occurs before the application shuts down */
	public $onShutdown = [];

	/** @var array<callable(self, IRequest): void>  Occurs when a new request is received */
	public $onRequest = [];

	/** @var array<callable(self, IPresenter): void>  Occurs when a presenter is created */
	public $onPresenter = [];

	/** @var array<callable(self, IResponse): void>  Occurs when a new response is ready for dispatch */
	public $onResponse = [];

	/** @var array<callable(self, IResponse): void>  Occurs after response is sent to client */
	public $onFlush = [];

	/** @var array<callable(self, Throwable): void>  Occurs when an unhandled exception occurs in the application */
	public $onError = [];

	/** @var Request[] */
	private array $requests = [];

	private ?IPresenter $presenter = null;

	public function __construct(
		private IPresenterFactory $presenterFactory,
		private Router $router,
		private IRequest $httpRequest,
		private IResponse $httpResponse,
	) {
	}

	/**
	 * @throws InvalidPresenterException
	 * @throws Throwable
	 * @throws BadRequestException
	 * @throws ApplicationException
	 */
	public function run(ServerRequestInterface $request): ResponseInterface
	{
		try {
			$this->initialize($request);
			Arrays::invoke($this->onStartup, $this);
			$response = $this->processRequest($this->createInitialRequest());
			Arrays::invoke($this->onShutdown, $this);

			return new PsrResponse(
				$this->httpResponse->getCode(),
				$this->httpResponse->getHeaders(),
				$response,
				reason: $this->httpResponse->getReason()
			);
		} catch (Throwable $e) {
			Arrays::invoke($this->onError, $this, $e);
			if ($this->catchExceptions && $this->errorPresenter) {
				try {
					$response = $this->processException($e);
					Arrays::invoke($this->onShutdown, $this, $e);

					return new PsrResponse(
						$this->httpResponse->getCode(),
						$this->httpResponse->getHeaders(),
						$response,
						reason: $this->httpResponse->getReason()
					);
				} catch (Throwable $e) {
					Arrays::invoke($this->onError, $this, $e);
				}
			}
			Arrays::invoke($this->onShutdown, $this, $e);
			throw $e;
		}
	}

	public function afterResponse(): void
	{
		Arrays::invoke($this->onFlush, $this);
	}

	public function initialize(ServerRequestInterface $request): void
	{
		$this->httpRequest->updateFromPsr($request);
		$this->httpResponse->cleanup();
		$this->requests = [];
		$this->presenter = null;
	}

	/**
	 * @throws BadRequestException
	 */
	public function createInitialRequest(): Request
	{
		$params = $this->router->match($this->httpRequest);
		$presenter = $params[UI\Presenter::PRESENTER_KEY] ?? null;

		if ($params === null) {
			throw new BadRequestException('No route for HTTP request.');
		} elseif (!is_string($presenter)) {
			throw new Nette\InvalidStateException('Missing presenter in route definition.');
		} elseif (Nette\Utils\Strings::startsWith($presenter, 'Nette:') && $presenter !== 'Nette:Micro') {
			throw new BadRequestException('Invalid request. Presenter is not achievable.');
		}

		unset($params[UI\Presenter::PRESENTER_KEY]);
		return new Request(
			$presenter,
			$this->httpRequest->getMethod(),
			$params,
			$this->httpRequest->getPost(),
			[],
			[Request::SECURED => $this->httpRequest->isSecured()]
		);
	}

	/**
	 * @throws InvalidPresenterException
	 * @throws ApplicationException
	 * @throws BadRequestException
	 */
	public function processRequest(Request $request): string
	{
		process:
		if (count($this->requests) > $this->maxLoop) {
			throw new ApplicationException('Too many loops detected in application life cycle.');
		}

		$this->requests[] = $request;
		Arrays::invoke($this->onRequest, $this, $request);

		if (!$request->isMethod($request::FORWARD)
			&& !strcasecmp($request->getPresenterName(), (string)$this->errorPresenter)
		) {
			throw new BadRequestException('Invalid request. Presenter is not achievable.');
		}

		try {
			$this->presenter = $this->presenterFactory->createPresenter($request->getPresenterName());
		} catch (InvalidPresenterException $e) {
			throw count($this->requests) > 1
				? $e
				: new BadRequestException($e->getMessage(), 0, $e);
		}
		Arrays::invoke($this->onPresenter, $this, $this->presenter);
		$response = $this->presenter->run(clone $request);

		if ($response instanceof Responses\ForwardResponse) {
			$request = $response->getRequest();
			goto process;
		}

		Arrays::invoke($this->onResponse, $this, $response);
		ob_start();
		$response->send($this->httpRequest, $this->httpResponse);
		return (string) ob_get_clean();
	}

	/**
	 * @throws InvalidPresenterException
	 * @throws BadRequestException
	 * @throws ApplicationException
	 */
	public function processException(Throwable $e): string
	{
		$this->httpResponse->setCode($e instanceof BadRequestException ? ($e->getHttpCode() ?: 404) : 500);
		$args = ['exception' => $e, 'request' => Arrays::last($this->requests) ?: null];

		if ($this->presenter instanceof UI\Presenter) {
			try {
				$this->presenter->forward(":$this->errorPresenter:", $args);
			} catch (AbortException) {
				/** @psalm-suppress InternalMethod */
				return $this->processRequest($this->presenter->getLastCreatedRequest());
			}
		} else {
			return $this->processRequest(new Request((string) $this->errorPresenter, Request::FORWARD, $args));
		}
	}

	/**
	 * Returns all processed requests.
	 * @return Request[]
	 */
	final public function getRequests(): array
	{
		return $this->requests;
	}

	/**
	 * Returns current presenter.
	 */
	final public function getPresenter(): ?IPresenter
	{
		return $this->presenter;
	}

	/**
	 * Returns router.
	 */
	public function getRouter(): Router
	{
		return $this->router;
	}

	/**
	 * Returns presenter factory.
	 */
	public function getPresenterFactory(): IPresenterFactory
	{
		return $this->presenterFactory;
	}
}
