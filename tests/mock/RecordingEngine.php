<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\mock;

use DealNews\Inngest\Execution\Engine;
use DealNews\Inngest\Execution\RunRequest;
use DealNews\Inngest\Execution\StepOp;
use DealNews\Inngest\Functions\FunctionOptions;
use DealNews\Inngest\Functions\InngestFunction;

/**
 * Engine that records step tool calls instead of running a function
 *
 * Steps with code run it right away (unless `$memoized`); other steps
 * return `$result`. Used to check the ops step tools build (like the JS
 * `getStepTools()` helper, which returns the op instead of a result).
 *
 * Usage:
 *
 * ```php
 * $engine = RecordingEngine::create();
 * (new StepTools($engine))->sleep('nap', '1h');
 * $engine->calls[0]['op']->name; // "1h"
 * ```
 *
 * @package DealNews\Inngest\Tests\mock
 */
class RecordingEngine extends Engine {

    /**
     * Recorded calls: the op, the step's code, and its arguments
     *
     * @var list<array{op: StepOp, fn: ?\Closure, args: list<mixed>}>
     */
    public array $calls = [];

    /**
     * What steps without code return
     *
     * @var mixed
     */
    public mixed $result = null;

    /**
     * Return `$result` for steps with code too, as if Inngest had stored it
     *
     * @var bool
     */
    public bool $memoized = false;

    /**
     * What isExecutingStep() returns
     *
     * @var bool
     */
    public bool $executing = false;

    /**
     * Creates an engine for a do-nothing function
     *
     * @return self
     */
    public static function create(): self {
        return new self(
            new InngestFunction('app', new FunctionOptions(id: 'fn'), 'time'),
            false,
            RunRequest::parse('{"event":{}}', null),
            null,
            new RecordingLogger()
        );
    }

    /**
     * Records the call and returns a result
     *
     * @param StepOp        $op   Op
     * @param \Closure|null $fn   Step code
     * @param list<mixed>   $args Arguments
     *
     * @return mixed
     */
    public function step(
        StepOp $op,
        ?\Closure $fn = null,
        array $args = []
    ): mixed {
        $this->calls[] = ['op' => $op, 'fn' => $fn, 'args' => $args];

        return $fn === null || $this->memoized ?
            $this->result :
            $fn(...$args);
    }

    /**
     * Returns `$executing`
     *
     * @return bool
     */
    public function isExecutingStep(): bool {
        return $this->executing;
    }

    /**
     * Returns the most recent op
     *
     * @return StepOp
     */
    public function lastOp(): StepOp {
        return $this->calls[count($this->calls) - 1]['op'];
    }
}
