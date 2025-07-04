<?php

namespace Leantime\Core\Middleware;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Leantime\Core\Events\DispatchesEvents;
use Leantime\Core\Http\IncomingRequest;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class StartSession
{
    use DispatchesEvents;

    /**
     * The session manager.
     *
     * @var \Illuminate\Session\SessionManager
     */
    protected $manager;

    /**
     * The callback that can resolve an instance of the cache factory.
     *
     * @var callable|null
     */
    protected $cacheFactoryResolver;

    /**
     * Create a new session middleware.
     *
     * @return void
     */
    public function __construct(SessionManager $manager, ?callable $cacheFactoryResolver = null)
    {
        $this->manager = $manager;
        $this->cacheFactoryResolver = $cacheFactoryResolver;
    }

    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(IncomingRequest $request, Closure $next)
    {

        if (! $this->sessionConfigured()) {
            return $next($request);
        }

        $session = $this->getSession($request);

        self::dispatchEvent('session_initialized');

        // For API requests, use array driver unless it's coming from js
        if ($request->isApiOrCronRequest() && ! $request->ajax()) {
            config(['session.driver' => 'array']);
            $this->manager->setDefaultDriver('array');
        }

        if ($this->shouldLockSession($request)) {
            return $this->handleRequestWhileBlocking($request, $session, $next);
        }

        return $this->handleStatefulRequest($request, $session, $next);

    }

    /**
     * Handle the given request within session state.
     *
     * @param  \Illuminate\Contracts\Session\Session  $session
     * @return mixed
     */
    protected function handleRequestWhileBlocking(IncomingRequest $request, $session, Closure $next)
    {

        // Dynamic lock period for different request types
        $holdLockFor = $this->calculateLockDuration($request); // Hold lock for x seconds after acquiring

        // Maximum time to wait for acquiring the lock if already held
        $maxWaitForLock = 5; // Wait for up to y seconds to acquire the lock

        $lock = $this->cache($this->manager->blockDriver())
            ->lock('session:'.$session->getId(), $holdLockFor)
            ->betweenBlockedAttemptsSleepFor(50);

        try {

            $lock->block($maxWaitForLock);

            return $this->handleStatefulRequest($request, $session, $next);

        } catch (LockTimeoutException $e) {

            Log::warning("Session lock timeout for session {$session->getId()}: {$e->getMessage()}");

            // Implement exponential backoff retry
            return $this->retryWithBackoff($request, $session, $next);

        } finally {
            $lock?->release();
        }
    }

    /**
     * Calculate appropriate lock duration based on request type. This is v0. We'll need to make this smarter
     */
    protected function calculateLockDuration(IncomingRequest $request): int
    {
        if ($request->isMethod('GET')) {
            return 1; // Shorter duration for GET requests
        }

        if ($request->ajax()) {
            return 2; // Medium duration for AJAX requests
        }

        return 3; // Default duration for other requests
    }

    /**
     * Implement exponential backoff retry strategy
     */
    protected function retryWithBackoff(IncomingRequest $request, $session, Closure $next, $attempts = 3)
    {
        for ($i = 0; $i < $attempts; $i++) {
            try {
                $waitTime = min(100 * pow(2, $i), 1000); // Exponential backoff with max 1 second
                $jitter = random_int(-100, 100); // Add jitter to prevent thundering herd
                usleep(($waitTime + $jitter) * 1000); // Convert to microseconds

                return $this->handleStatefulRequest($request, $session, $next);
            } catch (\Exception $e) {
                Log::warning("Retry attempt {$i} failed for session {$session->getId()}: {$e->getMessage()}");

                continue;
            }
        }

        // If all retries fail, proceed without lock
        Log::error("All retry attempts failed for session {$session->getId()}, proceeding without lock");

        return $this->handleStatefulRequest($request, $session, $next);
    }

    /**
     * Handle the given request within session state.
     *
     * @param  \Illuminate\Contracts\Session\Session  $session
     * @return mixed
     */
    protected function handleStatefulRequest(IncomingRequest $request, $session, Closure $next)
    {
        $startTime = microtime(true);
        $request->setLaravelSession(
            $this->startSession($request, $session)
        );

        self::dispatchEvent('session_started');

        $this->collectGarbage($session);

        // Going deeper down the rabbit hole and executing the rest of the middleware and stack.
        $response = $next($request);

        // Done processing the request, closing out the session
        $this->storeCurrentUrl($request, $session);

        $duration = microtime(true) - $startTime;
        if ($duration > 3.0) {
            Log::warning("Long session operation detected: {$duration}s for session {$session->getId()}");
        }

        $this->addCookieToResponse($response, $session);

        // Again, if the session has been configured we will need to close out the session
        // so that the attributes may be persisted to some storage medium. We will also
        // add the session identifier cookie to the application response headers now.
        $this->saveSession($request);

        return $response;
    }

    /**
     * Start the session for the given request.
     *
     * @param  \Illuminate\Contracts\Session\Session  $session
     * @return \Illuminate\Contracts\Session\Session
     */
    protected function startSession(IncomingRequest $request, $session)
    {
        return tap($session, function ($session) use ($request) {
            $session->setRequestOnHandler($request);

            $session->start();
        });
    }

    /**
     * Get the session implementation from the manager.
     *
     * @return \Illuminate\Contracts\Session\Session
     */
    public function getSession(IncomingRequest $request)
    {
        // Non logged in cookies will be reduced to 60min.
        // Extend Session Lifetime
        if (! $request->cookies->has('esl')) {
            app('config')->set('session.lifetime', 60);
        }

        return tap($this->manager->driver(), function ($session) use ($request) {
            $session->setId($request->cookies->get($session->getName()));
        });
    }

    /**
     * Remove the garbage from the session if necessary.
     *
     * @return void
     */
    protected function collectGarbage(Session $session)
    {
        $config = $this->manager->getSessionConfig();

        // Here we will see if this request hits the garbage collection lottery by hitting
        // the odds needed to perform garbage collection on any given request. If we do
        // hit it, we'll call this handler to let it delete all the expired sessions.
        if ($this->configHitsLottery($config)) {
            $session->getHandler()->gc($this->getSessionLifetimeInSeconds());
        }
    }

    /**
     * Determine if the configuration odds hit the lottery.
     *
     * @return bool
     */
    protected function configHitsLottery(array $config)
    {
        return random_int(1, $config['lottery'][1]) <= $config['lottery'][0];
    }

    /**
     * Store the current URL for the request if necessary.
     *
     * @param  \Illuminate\Contracts\Session\Session  $session
     * @return void
     */
    protected function storeCurrentUrl(IncomingRequest $request, $session)
    {
        if (
            $request->isMethod('GET')
            && $this->shouldLockSession($request)
        ) {
            $fullUrl = $request->fullUrl();
            $session->setPreviousUrl($request->fullUrl());
        }
    }

    /**
     * Add the session cookie to the application response.
     *
     * @return void
     */
    protected function addCookieToResponse(Response $response, Session $session)
    {
        if ($this->sessionIsPersistent($config = $this->manager->getSessionConfig())) {
            $response->headers->setCookie(new Cookie(
                $session->getName(),
                $session->getId(),
                $this->getCookieExpirationDate(),
                $config['path'],
                $config['domain'],
                $config['secure'] ?? false,
                $config['http_only'] ?? true,
                false,
                $config['same_site'] ?? null,
                $config['partitioned'] ?? false
            ));
        }
    }

    /**
     * Save the session data to storage.
     *
     * @return void
     */
    protected function saveSession(IncomingRequest $request)
    {
        if ($this->shouldLockSession($request)) {

            $this->manager->driver()->save();
        }
    }

    protected function shouldLockSession(IncomingRequest $request)
    {

        if (
            $request->isApiOrCronRequest() === false &&
            $this->sessionConfigured()
        ) {
            return true;
        }

        return false;
    }

    /**
     * Get the session lifetime in seconds.
     *
     * @return int
     */
    protected function getSessionLifetimeInSeconds()
    {
        return ($this->manager->getSessionConfig()['lifetime'] ?? null) * 60;
    }

    /**
     * Get the cookie lifetime in seconds.
     *
     * @return \DateTimeInterface|int
     */
    protected function getCookieExpirationDate()
    {
        $config = $this->manager->getSessionConfig();

        return $config['expire_on_close'] ? 0 : Date::instance(
            Carbon::now()->addRealMinutes($config['lifetime'])
        );
    }

    /**
     * Determine if a session driver has been configured.
     *
     * @return bool
     */
    protected function sessionConfigured()
    {
        return ! is_null($this->manager->getSessionConfig()['driver'] ?? null);
    }

    /**
     * Determine if the configured session driver is persistent.
     *
     * @return bool
     */
    protected function sessionIsPersistent(?array $config = null)
    {
        $config = $config ?: $this->manager->getSessionConfig();

        return ! is_null($config['driver'] ?? null);
    }

    /**
     * Resolve the given cache driver.
     *
     * @param  string  $driver
     * @return \Illuminate\Cache\Store
     */
    protected function cache($driver)
    {
        return Cache::store($driver);
    }
}
