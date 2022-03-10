<?php

namespace Mallgroup\RoadRunner\Http;

use Nette;
use Nette\Http\IResponse;
use Nette\Http\SessionSection;
use Nette\Utils\DateTime;
use Nette\Utils\Helpers;
use SessionHandlerInterface;

/**
 * @method onBeforeWrite(Session $session)
 * @method onStart(Session $session)
 */
class Session extends \Nette\Http\Session
{
	protected const DEFAULT_FILE_LIFETIME = 3 * DateTime::HOUR;
	protected const SECURITY_OPTIONS = [
		'referer_check' => '',    // must be disabled because PHP implementation is invalid
		'use_cookies' => 1,       // must be enabled to prevent Session Hijacking and Fixation
		'use_only_cookies' => 1,  // must be enabled to prevent Session Fixation
		'use_trans_sid' => 0,     // must be disabled to prevent Session Hijacking and Fixation
		'use_strict_mode' => 1,   // must be enabled to prevent Session Fixation
		'cookie_httponly' => true, // must be enabled to prevent Session Hijacking
	];

	protected bool $regenerated = false;
	protected bool $started = false;

	/** @var array<string|int|null|bool> default configuration */
	protected array $options = [
		'cookie_samesite' => IResponse::SAME_SITE_LAX,
		'cookie_lifetime' => 0,   // for a maximum of 3 hours or until the browser is closed
		'gc_maxlifetime' => self::DEFAULT_FILE_LIFETIME, // 3 hours
	];

	/** @deprecated */
	protected bool $readAndClose = false;
	protected bool $autoStart = true;
	private SessionHandlerInterface|null $handler = null;
	private bool $configured = false;

	public function __construct(
		protected Request $request,
		protected Response $response
	) {
		parent::__construct($this->request, $this->response);
		$this->options['cookie_path'] = &$this->response->cookiePath;
		$this->options['cookie_domain'] = &$this->response->cookieDomain;
		$this->options['cookie_secure'] = &$this->response->cookieSecure;
	}

	/**
	 * Setup session variables, so nette does not need to do this again
	 * @return void
	 */
	public function setup(): void
	{
		if ($this->configured) {
			return;
		}

		if (session_status() === PHP_SESSION_ACTIVE) {
			$this->configure(self::SECURITY_OPTIONS);
			$this->configured = true;
		} else {
			$this->configure(self::SECURITY_OPTIONS + $this->options);
		}
	}

	/**
	 * Configures session environment.
	 * @param array<string|int|bool|null> $config
	 */
	private function configure(array $config): void
	{
		$special = ['cache_expire' => 1, 'cache_limiter' => 1, 'save_path' => 1, 'name' => 1];
		$cookie = $origCookie = session_get_cookie_params();

		foreach ($config as $key => $value) {
			if ($value === null || ini_get("session.$key") == $value) { // intentionally ==
				continue;
			} elseif (strncmp($key, 'cookie_', 7) === 0) {
				$cookie[substr($key, 7)] = $value;
			} else {
				if (session_status() === PHP_SESSION_ACTIVE) {
					throw new Nette\InvalidStateException(
						"Unable to set 'session.$key' to value '$value' when session has been started"
						. ($this->started ? '.' : ' by session.auto_start or session_start().')
					);
				}
				if (isset($special[$key])) {
					("session_$key")($value);
				} elseif (function_exists('ini_set')) {
					ini_set("session.$key", (string)$value);
				} else {
					throw new Nette\NotSupportedException(
						"Unable to set 'session.$key' to '$value' because function ini_set() is disabled."
					);
				}
			}
		}

		if ($cookie !== $origCookie) {
			@session_set_cookie_params($cookie); // @ may trigger warning when session is active since PHP 7.2
			if (session_status() === PHP_SESSION_ACTIVE) {
				$this->sendCookie();
			}
		}

		if ($this->handler) {
			session_set_save_handler($this->handler);
		}
		$this->configured = true;
	}

	/**
	 * Sends the session cookies.
	 */
	public function sendCookie(): void
	{
		$cookie = session_get_cookie_params();
		$this->response->setCookie(
			$this->getName(),
			$this->getId(),
			$cookie['lifetime'] ? $cookie['lifetime'] + time() : 0,
			$cookie['path'],
			$cookie['domain'],
			$cookie['secure'],
			$cookie['httponly'],
			$cookie['samesite'] ?? null
		);
	}

	/**
	 * Gets the session name.
	 */
	public function getName(): string
	{
		return (string) ($this->options['name'] ?? session_name());
	}

	/**
	 * Returns the current session ID. Don't make dependencies, can be changed for each request.
	 */
	public function getId(): string
	{
		return (string) session_id();
	}

	/**
	 * Ends the current session and store session data.
	 */
	public function close(): void
	{
		if (session_status() === PHP_SESSION_ACTIVE) {
			if ($this->request->getCookie((string) session_name()) !== session_id()) {
				$this->sendCookie();
			}
			$this->clean();
			session_commit();
		}

		$this->started = false;
		$this->regenerated = false;
	}

	/**
	 * Cleans and minimizes meta structures. This method is called automatically on shutdown, do not call it directly.
	 * @internal
	 */
	public function clean(): void
	{
		if (!$this->isStarted()) {
			return;
		}

		$this->onBeforeWrite($this);

		$nf = &$_SESSION['__NF'];
		foreach ($nf['META'] ?? [] as $name => $foo) {
			if (empty($nf['META'][$name])) {
				unset($nf['META'][$name]);
			}
		}
	}

	/**
	 * Has been session started?
	 */
	public function isStarted(): bool
	{
		return $this->started && session_status() === PHP_SESSION_ACTIVE;
	}

	/**
	 * Sets the amount of time (like '20 minutes') allowed between requests before the session will be terminated,
	 * null means "for a maximum of 3 hours or until the browser is closed".
	 */
	public function setExpiration(?string $time): static
	{
		if ($time === null) {
			return $this->setOptions([
				'gc_maxlifetime' => self::DEFAULT_FILE_LIFETIME,
				'cookie_lifetime' => 0,
			]);
		} else {
			$time = (int) DateTime::from($time)->format('U') - time();
			return $this->setOptions([
				'gc_maxlifetime' => $time,
				'cookie_lifetime' => $time,
			]);
		}
	}

	/**
	 * Returns all session options.
	 * @return array<string, string|int|bool|null>
	 */
	public function getOptions(): array
	{
		return $this->options;
	}

	/**
	 * Sets session options.
	 * @param array<mixed> $options
	 * @throws Nette\NotSupportedException
	 * @throws Nette\InvalidStateException
	 */
	public function setOptions(array $options): static
	{
		$normalized = [];
		$allowed = (array) ini_get_all('session', false)
			+ ['session.read_and_close' => 0, 'session.cookie_samesite' => 1];

		foreach ($options as $key => $value) {
			if (!strncmp($key, 'session.', 8)) { // back compatibility
				$key = substr($key, 8);
			}
			$normKey = strtolower(preg_replace('#(.)(?=[A-Z])#', '$1_', $key)); // camelCase -> snake_case

			if (!isset($allowed["session.$normKey"])) {
				$hint = substr((string)Helpers::getSuggestion(array_keys($allowed), "session.$normKey"), 8);
				if ($key !== $normKey) {
					$hint = preg_replace_callback('#_(.)#', function ($m) {
						return strtoupper($m[1]);
					}, $hint); // snake_case -> camelCase
				}
				throw new Nette\InvalidStateException("Invalid session configuration option '$key'" . ($hint ? ", did you mean '$hint'?" : '.'));
			}

			$normalized[$normKey] = $value;
		}

		$this->autoStart = (bool) ($normalized['auto_start'] ?? true);
		unset($normalized['auto_start']);

		if (session_status() === PHP_SESSION_ACTIVE) {
			$this->configure($normalized);
		}
		$this->options = $normalized + $this->options;
		return $this;
	}

	/**
	 * Sets path of the directory used to save session data.
	 */
	public function setSavePath(string $path): static
	{
		return $this->setOptions([
			'save_path' => $path,
		]);
	}

	/**
	 * Sets user session handler.
	 */
	public function setHandler(\SessionHandlerInterface $handler): static
	{
		if ($this->configured) {
			throw new Nette\InvalidStateException('Unable to set handler when session has been configured.');
		}
		$this->handler = $handler;
		return $this;
	}

	/**
	 * Sets the session cookie parameters.
	 */
	public function setCookieParameters(
		string $path,
		string $domain = null,
		bool $secure = null,
		string $sameSite = null
	): static {
		return $this->setOptions([
			'cookie_path' => $path,
			'cookie_domain' => $domain,
			'cookie_secure' => $secure,
			'cookie_samesite' => $sameSite,
		]);
	}

	/**
	 * Sets the session name to a specified one.
	 */
	public function setName(string $name): static
	{
		if (!preg_match('#[^0-9.][^.]*$#DA', $name)) {
			throw new Nette\InvalidArgumentException('Session name cannot contain dot.');
		}

		session_name($name);
		return $this->setOptions([
			'name' => $name,
		]);
	}

	/**
	 * Returns specified session section.
	 * @throws Nette\InvalidArgumentException
	 */
	public function getSection(string $section, string $class = SessionSection::class): SessionSection
	{
		return new $class($this, $section);
	}

	/**
	 * Checks if a session section exist and is not empty.
	 */
	public function hasSection(string $section): bool
	{
		if ($this->exists() && !$this->started) {
			$this->autoStart(false);
		}

		return !empty($_SESSION['__NF']['DATA'][$section]);
	}

	/**
	 * Does session exist for the current request?
	 */
	public function exists(): bool
	{
		return session_status() === PHP_SESSION_ACTIVE || $this->request->getCookie($this->getName());
	}

	/** @internal */
	public function autoStart(bool $forWrite): void
	{
		if ($this->started || (!$forWrite && !$this->exists())) {
			return;
		} elseif (!$this->autoStart) {
			trigger_error('Cannot auto-start session because autostarting is disabled', E_USER_WARNING);
			return;
		}

		$this->doStart(!$forWrite);
	}

	private function doStart(bool $mustExists = false): void
	{
		if (session_status() === PHP_SESSION_ACTIVE && !$this->started) { // adapt an existing session
			$this->initialize();
			return;
		}

		if (!$this->started) { // session is started for first time
			$id = $this->request->getCookie((string) session_name());
			/** @var string $id */
			$id = is_string($id) && preg_match('#^[0-9a-zA-Z,-]{22,256}$#Di', $id)
				? $id
				: session_create_id();
			session_id($id); // causes resend of a cookie to make sure it has the right parameters
		}

		try {
			// session_start returns false on failure only sometimes (even in PHP >= 7.1)
			Nette\Utils\Callback::invokeSafe(
				'session_start',
				[['read_and_close' => 0]],
				function (string $message) use (&$e): void {
					$e = new Nette\InvalidStateException($message);
				}
			);
		} catch (\Throwable $e) {
		}

		if ($e) {
			@session_write_close(); // this is needed
			throw $e;
		}

		if ($mustExists && $this->request->getCookie((string) session_name()) !== session_id()) {
			// PHP regenerated the ID which means that the session did not exist and cookie was invalid
			$this->destroy();
			return;
		}

		$this->initialize();
		$this->onStart($this);
	}

	private function initialize(): void
	{
		$this->started = true;

		/* structure:
			__NF: Data, Meta, Time
				DATA: section->variable = data
				META: section->variable = Timestamp
		*/
		$nf = &$_SESSION['__NF'];

		if (!is_array($nf)) {
			$nf = [];
		}

		// regenerate empty session
		if (empty($nf['Time'])) {
			$nf['Time'] = time();
			if ($this->request->getCookie((string) session_name()) === session_id()) {
				// ensures that the session was created with use_strict_mode (ie by Nette)
				$this->regenerateId();
			}
		}

		// expire section variables
		$now = time();
		foreach ($nf['META'] ?? [] as $section => $metadata) {
			foreach ($metadata ?? [] as $variable => $value) {
				if (!empty($value['T']) && $now > $value['T']) {
					if ($variable === '') { // expire whole section
						unset($nf['META'][$section], $nf['DATA'][$section]);
						continue 2;
					}
					unset($nf['META'][$section][$variable], $nf['DATA'][$section][$variable]);
				}
			}
		}
	}

	/**
	 * Regenerates the session ID.
	 * @throws Nette\InvalidStateException
	 */
	public function regenerateId(): void
	{
		if ($this->regenerated) {
			return;
		}
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_regenerate_id(true);
		} else {
			session_id(session_create_id() ?: null);
		}
		$this->regenerated = true;
	}

	/**
	 * Destroys all data registered to a session.
	 * @throws \Exception
	 */
	public function destroy(): void
	{
		if (session_status() !== PHP_SESSION_ACTIVE) {
			throw new Nette\InvalidStateException('Session is not started.');
		}

		session_destroy();
		$_SESSION = null;
		$this->started = false;
		$this->regenerated = false;
		$params = session_get_cookie_params();
		$this->response->deleteCookie((string) session_name(), $params['path'], $params['domain'], $params['secure']);
	}

	/**
	 * Iteration over all sections.
	 */
	public function getIterator(): \ArrayIterator
	{
		if ($this->exists() && !$this->started) {
			$this->autoStart(false);
		}

		return new \ArrayIterator(array_keys($_SESSION['__NF']['DATA'] ?? []));
	}

	/**
	 * Starts and initializes session data.
	 * @throws Nette\InvalidStateException
	 */
	public function start(): void
	{
		$this->doStart();
	}
}
