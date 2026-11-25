<?php

namespace Keepsuit\Spyglass\Tests\Fixtures;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

/**
 * A decorator that forwards everything beyond the contract, `reportable()` included, through `__call()`.
 */
class ForwardingExceptionHandler implements ExceptionHandler
{
    public function __construct(protected ExceptionHandler $handler) {}

    public function report(Throwable $e)
    {
        $this->handler->report($e);
    }

    public function shouldReport(Throwable $e)
    {
        return $this->handler->shouldReport($e);
    }

    public function render($request, Throwable $e)
    {
        return $this->handler->render($request, $e);
    }

    public function renderForConsole($output, Throwable $e)
    {
        $this->handler->renderForConsole($output, $e);
    }

    /**
     * @param  array<mixed>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->handler->{$method}(...$parameters);
    }
}
