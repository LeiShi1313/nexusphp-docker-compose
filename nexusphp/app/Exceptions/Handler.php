<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Validation\UnauthorizedException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        $this->renderable(function (AuthenticationException $e) {
            return response()->json(fail($e->getMessage(), $e->guards()), 401);
        });

        $this->renderable(function (UnauthorizedException $e) {
            return response()->json(fail($e->getMessage(), request()->all()), 403);
        });

        $this->renderable(function (ValidationException $exception) {
            $errors = $exception->errors();
            $msg = Arr::first(Arr::first($errors));
            return response()->json(fail($msg, $errors));
        });

        $this->renderable(function (NotFoundHttpException $e) {
            if ($e->getPrevious() && $e->getPrevious() instanceof ModelNotFoundException) {
                return response()->json(fail('No query result.', request()->all()), 404);
            }
        });
    }

    /**
     * Prepare a JSON response for the given exception.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $e
     * @return \Illuminate\Http\JsonResponse
     */
    protected function prepareJsonResponse($request, Throwable $e)
    {
        $data = $request->all();
        $httpStatusCode = $this->getHttpStatusCode($e);
        if ($httpStatusCode == 200) {
            $msg = $e->getMessage() ?: get_class($e);
        } else {
            $msg = 'Server Error';
        }
        if (config('app.debug')) {
            $msg = $e->getMessage();
            $data['trace'] = $e->getTraceAsString();
        }
        return new JsonResponse(
            fail($msg, $data),
            $httpStatusCode,
            $this->isHttpException($e) ? $e->getHeaders() : [],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
    }

    protected function getHttpStatusCode(Throwable $e)
    {
        if ($e instanceof \InvalidArgumentException || $e instanceof NexusException) {
            return 200;
        }
        if ($this->isHttpException($e)) {
            return $e->getStatusCode();
        }
        return 500;

    }


}
