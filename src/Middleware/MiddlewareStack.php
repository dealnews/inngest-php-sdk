<?php

declare(strict_types=1);

namespace DealNews\Inngest\Middleware;

use DealNews\Inngest\Client;
use DealNews\Inngest\Context;
use DealNews\Inngest\Event\SendEventResult;
use DealNews\Inngest\Functions\InngestFunction;
use DealNews\Inngest\Http\Request;
use DealNews\Inngest\Http\Response;
use Psr\Log\LoggerInterface;

/**
 * The middleware instances for one request, and how their hooks run
 *
 * Port of JS `MiddlewareManager` (components/middleware/manager.ts) and
 * the chain builders in utils.ts. Keeps middleware details out of the
 * engine, client, and serve handler.
 *
 * Edge cases:
 *
 * - Hooks a middleware doesn't override are skipped, so an unused hook
 *   adds nothing to a chain.
 * - `wrapStep` has a recursion guard: steps a middleware creates inside
 *   its own `wrapStep` (before calling `next()`) skip that middleware.
 *   The guard is kept per fiber, so parallel branches don't hide each
 *   other's steps.
 *
 * @package DealNews\Inngest\Middleware
 */
class MiddlewareStack {

    /**
     * Hooks that are called with every middleware, in order
     *
     * @var list<string>
     */
    public const HOOKS = [
        'transformFunctionInput',
        'onMemoizationEnd',
        'onRunStart',
        'wrapFunctionHandler',
        'onRunComplete',
        'onRunError',
        'transformStepInput',
        'wrapStep',
        'onStepStart',
        'wrapStepHandler',
        'onStepComplete',
        'onStepError',
        'transformSendEvent',
        'wrapSendEvent',
        'wrapRequest',
    ];

    /**
     * Middleware that override each hook, keyed by hook then position
     *
     * @var array<string, array<int, Middleware>>
     */
    protected array $hooks = [];

    /**
     * Whether `onMemoizationEnd` has run in this request
     *
     * @var bool
     */
    protected bool $memoization_ended = false;

    /**
     * Positions of the middleware whose `wrapStep` is active, per fiber
     * (the stack itself stands in when not in a fiber)
     *
     * @var \WeakMap<object, array<int, true>>
     */
    protected \WeakMap $active_wrap_step;

    /**
     * Creates the stack
     *
     * @param list<Middleware> $middleware Instances, in order
     * @param LoggerInterface  $logger     Logger for hook errors
     */
    public function __construct(
        protected array $middleware,
        protected LoggerInterface $logger
    ) {
        $this->active_wrap_step = new \WeakMap();

        foreach (self::HOOKS as $hook) {
            $this->hooks[$hook] = [];
            foreach ($middleware as $position => $instance) {
                $method = new \ReflectionMethod($instance, $hook);
                if ($method->getDeclaringClass()->name !== Middleware::class) {
                    $this->hooks[$hook][$position] = $instance;
                }
            }
        }
    }

    /**
     * Creates fresh instances from middleware class names and factories
     *
     * @param Client                $client  Client passed to each instance
     * @param list<string|\Closure> $entries Class names, or factories
     *                                       taking the Client
     *
     * @throws \UnexpectedValueException When a factory doesn't return a
     *                                   Middleware (10003)
     *
     * @return self
     */
    public static function create(Client $client, array $entries): self {
        $instances = [];
        foreach ($entries as $entry) {
            $instances[] = static::instantiate($entry, $client);
        }

        return new self($instances, $client->getLogger());
    }

    /**
     * Checks a list of middleware class names and factories
     *
     * @param array<mixed> $entries Class names or factories
     * @param int          $code    Exception code
     *
     * @throws \InvalidArgumentException When an item is neither a
     *                                   Middleware class name nor a
     *                                   Closure
     *
     * @return list<class-string<Middleware>|\Closure>
     */
    public static function validate(array $entries, int $code): array {
        $result = [];

        foreach ($entries as $entry) {
            if (
                !$entry instanceof \Closure &&
                (
                    !is_string($entry) ||
                    !is_subclass_of($entry, Middleware::class)
                )
            ) {
                throw new \InvalidArgumentException(
                    'Middleware must be ' . Middleware::class .
                    ' class names or Closures that return one',
                    $code
                );
            }

            $result[] = $entry;
        }

        return $result;
    }

    /**
     * Calls each middleware's static `onRegister()`
     *
     * A factory is called once to find its class.
     *
     * @param list<class-string<Middleware>|\Closure> $entries  Class names
     *                                                          or factories
     * @param Client                                  $client   Client
     * @param InngestFunction|null                    $function Function,
     *                                                          for function
     *                                                          middleware
     *
     * @throws \UnexpectedValueException When a factory doesn't return a
     *                                   Middleware (10003)
     *
     * @return void
     */
    public static function register(
        array $entries,
        Client $client,
        ?InngestFunction $function
    ): void {
        foreach ($entries as $entry) {
            $class = is_string($entry) ?
                $entry :
                static::instantiate($entry, $client)::class;
            $class::onRegister($client, $function);
        }
    }

    /**
     * Creates one middleware instance
     *
     * @param string|\Closure $entry  Class name or factory
     * @param Client          $client Client
     *
     * @throws \UnexpectedValueException When a factory doesn't return a
     *                                   Middleware (10003)
     *
     * @return Middleware
     */
    protected static function instantiate(
        string|\Closure $entry,
        Client $client
    ): Middleware {
        $result = is_string($entry) ? new $entry($client) : $entry($client);

        if (!$result instanceof Middleware) {
            throw new \UnexpectedValueException(
                'A middleware factory must return a ' . Middleware::class,
                10003
            );
        }

        return $result;
    }

    /**
     * Returns the instances, in order
     *
     * @return list<Middleware>
     */
    public function getMiddleware(): array {
        return $this->middleware;
    }

    /**
     * Whether any middleware overrides a hook
     *
     * @param string $hook Hook (method) name
     *
     * @return bool
     */
    public function has(string $hook): bool {
        return ($this->hooks[$hook] ?? []) !== [];
    }

    /**
     * Runs `transformFunctionInput`, in order
     *
     * @param FunctionInput $input Input
     *
     * @return FunctionInput
     */
    public function transformFunctionInput(
        FunctionInput $input
    ): FunctionInput {
        foreach ($this->hooks['transformFunctionInput'] as $middleware) {
            $input = $middleware->transformFunctionInput($input);
        }

        return $input;
    }

    /**
     * Runs `transformStepInput`, in order
     *
     * @param StepInput $input Input
     *
     * @return StepInput
     */
    public function transformStepInput(StepInput $input): StepInput {
        foreach ($this->hooks['transformStepInput'] as $middleware) {
            $input = $middleware->transformStepInput($input);
        }

        return $input;
    }

    /**
     * Runs `transformSendEvent`, in order
     *
     * @param SendEventInput $input Input
     *
     * @return SendEventInput
     */
    public function transformSendEvent(
        SendEventInput $input
    ): SendEventInput {
        foreach ($this->hooks['transformSendEvent'] as $middleware) {
            $input = $middleware->transformSendEvent($input);
        }

        return $input;
    }

    /**
     * Runs `onMemoizationEnd`, once per request
     *
     * @param RunArgs $args Arguments
     *
     * @return void
     */
    public function onMemoizationEnd(RunArgs $args): void {
        if (!$this->memoization_ended) {
            $this->memoization_ended = true;
            $this->notify('onMemoizationEnd', $args);
        }
    }

    /**
     * Runs `onRunStart`
     *
     * @param RunArgs $args Arguments
     *
     * @return void
     */
    public function onRunStart(RunArgs $args): void {
        $this->notify('onRunStart', $args);
    }

    /**
     * Runs `onRunComplete`
     *
     * @param RunCompleteArgs $args Arguments
     *
     * @return void
     */
    public function onRunComplete(RunCompleteArgs $args): void {
        $this->notify('onRunComplete', $args);
    }

    /**
     * Runs `onRunError`
     *
     * @param RunErrorArgs $args Arguments
     *
     * @return void
     */
    public function onRunError(RunErrorArgs $args): void {
        $this->notify('onRunError', $args);
    }

    /**
     * Runs `onStepStart`
     *
     * @param StepArgs $args Arguments
     *
     * @return void
     */
    public function onStepStart(StepArgs $args): void {
        $this->notify('onStepStart', $args);
    }

    /**
     * Runs `onStepComplete`
     *
     * @param StepCompleteArgs $args Arguments
     *
     * @return void
     */
    public function onStepComplete(StepCompleteArgs $args): void {
        $this->notify('onStepComplete', $args);
    }

    /**
     * Runs `onStepError`
     *
     * @param StepErrorArgs $args Arguments
     *
     * @return void
     */
    public function onStepError(StepErrorArgs $args): void {
        $this->notify('onStepError', $args);
    }

    /**
     * Wraps the handler in `wrapFunctionHandler` (first middleware
     * outermost)
     *
     * @param Context         $ctx      Handler context
     * @param InngestFunction $function Function
     * @param \Closure        $handler  Runs the handler
     *
     * @return \Closure Runs the chain
     */
    public function wrapFunctionHandler(
        Context $ctx,
        InngestFunction $function,
        \Closure $handler
    ): \Closure {
        $chain = $handler;
        foreach (
            array_reverse($this->hooks['wrapFunctionHandler']) as $middleware
        ) {
            $next  = $chain;
            $chain = static fn () => $middleware->wrapFunctionHandler(
                new WrapFunctionHandlerArgs($ctx, $function, $next)
            );
        }

        return $chain;
    }

    /**
     * Runs a step's result through `wrapStep` (first middleware
     * outermost), in the current fiber
     *
     * @param Context         $ctx       Handler context
     * @param InngestFunction $function  Function
     * @param StepInfo        $step_info Step
     * @param \Closure        $center    Returns the step's result (or
     *                                   suspends until it has one)
     *
     * @return mixed The step's result, as the handler sees it
     */
    public function wrapStep(
        Context $ctx,
        InngestFunction $function,
        StepInfo $step_info,
        \Closure $center
    ): mixed {
        $chain = $center;
        $hooks = array_reverse($this->hooks['wrapStep'], true);
        foreach ($hooks as $position => $middleware) {
            $next  = $chain;
            $chain = fn () => $this->guardedWrapStep(
                $position,
                $middleware,
                new WrapStepArgs(
                    $ctx,
                    $function,
                    $step_info,
                    fn () => $this->guardedNext($position, $next)
                ),
                $next
            );
        }

        return $chain();
    }

    /**
     * Gives a new fiber the `wrapStep` guard of the fiber that made it
     *
     * @param \Fiber<mixed, mixed, mixed, mixed>|null $parent Parent fiber
     * @param \Fiber<mixed, mixed, mixed, mixed>      $child  New fiber
     *
     * @return void
     */
    public function inheritGuard(?\Fiber $parent, \Fiber $child): void {
        $active = $this->active_wrap_step[$parent ?? $this] ?? [];
        if ($active !== []) {
            $this->active_wrap_step[$child] = $active;
        }
    }

    /**
     * Runs a step's code through `wrapStepHandler` (first middleware
     * outermost)
     *
     * @param Context         $ctx       Handler context
     * @param InngestFunction $function  Function
     * @param StepInfo        $step_info Step
     * @param \Closure        $handler   Runs the step's code
     *
     * @return mixed The step's output
     */
    public function wrapStepHandler(
        Context $ctx,
        InngestFunction $function,
        StepInfo $step_info,
        \Closure $handler
    ): mixed {
        $chain = $handler;
        foreach (
            array_reverse($this->hooks['wrapStepHandler']) as $middleware
        ) {
            $next  = $chain;
            $chain = static fn () => $middleware->wrapStepHandler(
                new WrapStepArgs($ctx, $function, $step_info, $next)
            );
        }

        return $chain();
    }

    /**
     * Sends events through `wrapSendEvent` (first middleware outermost)
     *
     * @param list<\DealNews\Inngest\Event\Event> $events   Events
     * @param InngestFunction|null                $function Sending function
     * @param \Closure                            $handler  Sends them
     *
     * @return SendEventResult
     */
    public function wrapSendEvent(
        array $events,
        ?InngestFunction $function,
        \Closure $handler
    ): SendEventResult {
        $chain = $handler;
        foreach (array_reverse($this->hooks['wrapSendEvent']) as $middleware) {
            $next  = $chain;
            $chain = static fn () => $middleware->wrapSendEvent(
                new WrapSendEventArgs($events, $function, $next)
            );
        }

        return $chain();
    }

    /**
     * Handles a request through `wrapRequest` (first middleware outermost)
     *
     * @param Request              $request  Incoming request
     * @param InngestFunction|null $function Function named by `fnId`
     * @param string               $run_id   Run ID from the body
     * @param \Closure             $handler  Handles it
     *
     * @return Response
     */
    public function wrapRequest(
        Request $request,
        ?InngestFunction $function,
        string $run_id,
        \Closure $handler
    ): Response {
        $chain = $handler;
        foreach (array_reverse($this->hooks['wrapRequest']) as $middleware) {
            $next  = $chain;
            $chain = static fn () => $middleware->wrapRequest(
                new WrapRequestArgs($request, $function, $run_id, $next)
            );
        }

        return $chain();
    }

    /**
     * Calls one middleware's `wrapStep`, unless it's already active in
     * this fiber (it created this step itself)
     *
     * @param int          $position   Middleware position
     * @param Middleware   $middleware Middleware
     * @param WrapStepArgs $args       Arguments
     * @param \Closure     $next       Rest of the chain
     *
     * @return mixed
     */
    protected function guardedWrapStep(
        int $position,
        Middleware $middleware,
        WrapStepArgs $args,
        \Closure $next
    ): mixed {
        if ($this->isActive($position)) {
            $result = $next();
        } else {
            $this->setActive($position, true);
            try {
                $result = $middleware->wrapStep($args);
            } finally {
                $this->setActive($position, false);
            }
        }

        return $result;
    }

    /**
     * Runs the rest of a `wrapStep` chain with the middleware inactive,
     * so steps created further in still go through it
     *
     * @param int      $position Middleware position
     * @param \Closure $next     Rest of the chain
     *
     * @return mixed
     */
    protected function guardedNext(int $position, \Closure $next): mixed {
        $this->setActive($position, false);
        try {
            $result = $next();
        } finally {
            $this->setActive($position, true);
        }

        return $result;
    }

    /**
     * Whether a middleware's `wrapStep` is active in the current fiber
     *
     * @param int $position Middleware position
     *
     * @return bool
     */
    protected function isActive(int $position): bool {
        $key = \Fiber::getCurrent() ?? $this;

        return isset($this->active_wrap_step[$key][$position]);
    }

    /**
     * Marks a middleware's `wrapStep` active or not in the current fiber
     *
     * The fiber is looked up each time rather than captured, so closures
     * never keep a fiber alive.
     *
     * @param int  $position Middleware position
     * @param bool $active   Active
     *
     * @return void
     */
    protected function setActive(int $position, bool $active): void {
        $key    = \Fiber::getCurrent()          ?? $this;
        $set    = $this->active_wrap_step[$key] ?? [];
        if ($active) {
            $set[$position] = true;
        } else {
            unset($set[$position]);
        }
        $this->active_wrap_step[$key] = $set;
    }

    /**
     * Calls an informational hook on every middleware; errors are logged
     *
     * @param string  $hook Hook name
     * @param RunArgs $args Arguments
     *
     * @return void
     */
    protected function notify(string $hook, RunArgs $args): void {
        foreach ($this->hooks[$hook] as $middleware) {
            try {
                $middleware->$hook($args);
            } catch (\Throwable $e) {
                $this->logger->error('middleware error', [
                    'exception' => $e,
                    'hook'      => $hook,
                    'mw'        => $middleware->getId(),
                ]);
            }
        }
    }
}
