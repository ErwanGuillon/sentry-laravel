<?php

namespace Sentry\Laravel;

use Closure;
use Sentry\Context\Context;
use Sentry\State\Scope;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\Transaction;

class TracingMiddleware
{
    /**
     * The current active transaction
     */
    protected $transaction = null;

    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     *
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (app()->bound('sentry')) {
            $path = '/' . ltrim($request->path(), '/');
            $fallbackTime = microtime(true);

            /** @var \Sentry\State\Hub $hub */
            $hub = app('sentry');

            $transaction = null;

            Integration::configureScope(static function (Scope $scope) use (&$transaction): void {
                $transaction = $scope->getTransaction();
            });

            $this->transaction = $transaction;
            $this->transaction->setOp('http.server');
            $this->transaction->setName($path);
            $this->transaction->setData([
                'url' => $path,
                'method' => strtoupper($request->method()),
            ]);
            $this->transaction->setStartTimestamp($request->server('REQUEST_TIME_FLOAT', $fallbackTime));

            if (!$this->addBootTimeSpans()) {
                // @TODO: We might want to move this together with the `RouteMatches` listener to some central place and or do this from the `EventHandler`
                app()->booted(static function () use ($request, $transaction, $fallbackTime): void {
                    $spanContextStart = new SpanContext();
                    $spanContextStart->op = 'app.bootstrap';
                    $spanContextStart->startTimestamp = defined('LARAVEL_START') ? LARAVEL_START : $request->server('REQUEST_TIME_FLOAT', $fallbackTime);
                    $spanContextStart->endTimestamp = microtime(true);
                    $transaction->startChild($spanContextStart);
                });
            }
        }

        return $next($request);
    }

    /**
     * Handle the application termination.
     *
     * @param \Illuminate\Http\Request  $request
     * @param \Illuminate\Http\Response $response
     *
     * @return void
     */
    public function terminate($request, $response)
    {
        if (app()->bound('sentry')) {
            if (null !== $this->transaction) {
                $this->transaction->finish();
            }
        }
    }

    private function addBootTimeSpans(): bool
    {
        if (!defined('LARAVEL_START') || !LARAVEL_START) {
            return false;
        }

        if (!defined('SENTRY_AUTOLOAD') || !SENTRY_AUTOLOAD) {
            return false;
        }

        if (!defined('SENTRY_BOOTSTRAP') || !SENTRY_BOOTSTRAP) {
            return false;
        }

        $spanContextStart = new SpanContext();
        $spanContextStart->op = 'autoload';
        $spanContextStart->startTimestamp = LARAVEL_START;
        $spanContextStart->endTimestamp = SENTRY_AUTOLOAD;
        $this->transaction->startChild($spanContextStart);

        $spanContextStart = new SpanContext();
        $spanContextStart->op = 'bootstrap';
        $spanContextStart->startTimestamp = SENTRY_AUTOLOAD;
        $spanContextStart->endTimestamp = SENTRY_BOOTSTRAP;
        $this->transaction->startChild($spanContextStart);

        return true;
    }
}
