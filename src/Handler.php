<?php
/**
 * @desc ExceptionHandler
 * @author Tinywan(ShaoBo Wan)
 * @email 756684177@qq.com
 * @date 2022/3/6 14:08
 */
declare(strict_types=1);

namespace Inked7\ExceptionHandler;

use FastRoute\BadRouteException;
use Throwable;
use Inked7\ExceptionHandler\Event\ChatRobotEvent;
use Inked7\ExceptionHandler\Exception\BaseException;
use Inked7\ExceptionHandler\Exception\ServerErrorHttpException;
use Tinywan\Jwt\Exception\JwtRefreshTokenExpiredException;
use Tinywan\Jwt\Exception\JwtTokenException;
use Tinywan\Jwt\Exception\JwtTokenExpiredException;
use Tinywan\Validate\Exception\ValidateException;
use Webman\Exception\ExceptionHandler;
use Webman\Http\Request;
use Webman\Http\Response;

class Handler extends ExceptionHandler
{
    /**
     * 不需要记录错误日志.
     *
     * @var string[]
     */
    public $dontReport = [];

    /**
     * HTTP Response Status Code.
     *
     * @var array
     */
    public $statusCode = 200;

    /**
     * HTTP Response Header.
     *
     * @var array
     */
    public $header = [];

    /**
     * Business Error code.
     *
     * @var int
     */
    public $errorCode = 0;

    /**
     * Business Error message.
     *
     * @var string
     */
    public $errorMessage = 'no error';

    /**
     * 响应结果数据.
     *
     * @var array
     */
    protected $responseData = [];

    /**
     * config下的配置.
     *
     * @var array
     */
    protected $config = [];

    /**
     * @param Throwable $exception
     */
    public function report(Throwable $exception)
    {
        $this->dontReport = config('plugin.inked7.exception-handler.app.exception_handler.dont_report', []);
        parent::report($exception);
    }

    /**
     * @param Request $request
     * @param Throwable $exception
     * @return Response
     */
    public function render(Request $request, Throwable $exception): Response
    {
        $this->config = array_merge($this->config, config('plugin.inked7.exception-handler.app.exception_handler', []));

        $this->addRequestInfoToResponse($request);
        $this->solveAllException($exception);
        $this->addDebugInfoToResponse($exception);
        $this->triggerNotifyEvent($exception);
        $this->triggerTraceEvent($exception);

        return $this->buildResponse();
    }

    /**
     * 请求的相关信息.
     *
     * @param Request $request
     * @return void
     */
    protected function addRequestInfoToResponse(Request $request): void
    {
        $this->responseData = array_merge($this->responseData, [
            'domain' => $request->fullUrl(),
            'method' => $request->method(),
            'request_url' => $request->method() . ' ' . $request->fullUrl(),
            'timestamp' => date('Y-m-d H:i:s'),
            'client_ip' => $request->getRealIp(),
            'request_param' => $request->all(),
        ]);
    }

    /**
     * 处理异常数据.
     *
     * @param Throwable $e
     */
    protected function solveAllException(Throwable $e)
    {
        if ($e instanceof BaseException) {
            $this->statusCode = $e->statusCode;
            $this->header = $e->header;
            $this->errorCode = $e->errorCode;
            $this->errorMessage = $e->errorMessage;
            if (isset($e->data)) {
                $this->responseData = array_merge($this->responseData, $e->data);
            }
            if (!$e instanceof ServerErrorHttpException) {
                return;
            }
        }
        $this->solveExtraException($e);
    }

    /**
     * @desc: 处理扩展的异常
     * @param Throwable $e
     * @author Inked7(ShaoBo Wan)
     */
    protected function solveExtraException(Throwable $e): void
    {
        $status = $this->config['status'];
        $this->errorMessage = $e->getMessage();
        if ($e instanceof BadRouteException) {
            $this->statusCode = $status['route'] ?? 404;
        } elseif ($e instanceof ValidateException) {
            $this->statusCode = $status['validate'];
        }  elseif ($e instanceof JwtTokenException) {
            $this->statusCode = $status['jwt_token'];
        } elseif ($e instanceof JwtTokenExpiredException) {
            $this->statusCode = $status['jwt_token_expired'];
        } elseif ($e instanceof JwtRefreshTokenExpiredException) {
            $this->statusCode = $status['jwt_refresh_token_expired'];
        }  elseif ($e instanceof \InvalidArgumentException) {
            $this->statusCode = $status['invalid_argument'] ?? 415;
            $this->errorMessage = '预期参数配置异常：' . $e->getMessage();
        } elseif ($e instanceof ServerErrorHttpException) {
            $this->statusCode = $status['server_error'] ?? 500;
            $this->errorMessage = $e->errorMessage;
        } else {
            $this->statusCode = $status['server_error'] ?? 500;
            $this->errorMessage = 'Server Unknown Error';
        }
    }

    /**
     * 调试模式：错误处理器会显示异常以及详细的函数调用栈和源代码行数来帮助调试，将返回详细的异常信息。
     * @param Throwable $e
     * @return void
     */
    protected function addDebugInfoToResponse(Throwable $e): void
    {
        if (config('app.debug', false)) {
            $this->responseData['error_message'] = $this->errorMessage;
            $this->responseData['error_trace'] = explode("\n", $e->getTraceAsString());
            $this->responseData['file'] = $e->getFile();
            $this->responseData['line'] = $e->getLine();
        }
    }

    /**
     * 触发通知事件.
     *
     * @param Throwable $e
     * @return void
     */
    protected function triggerNotifyEvent(Throwable $e): void
    {
        if (!$this->shouldntReport($e) && $this->config['event_trigger']['enable'] ?? false) {
            $responseData = $this->responseData;
            $responseData['message'] = $this->errorMessage;
            $responseData['error'] = $e->getMessage();
            $responseData['file'] = $e->getFile();
            $responseData['line'] = $e->getLine();
//            DingTalkRobotEvent::dingTalkRobot($responseData, $this->config);
            ChatRobotEvent::chatRobot($responseData, $this->config);
        }
    }

    /**
     * 触发 trace 事件.
     *
     * @param Throwable $e
     * @return void
     */
    protected function triggerTraceEvent(Throwable $e): void
    {
        if (isset(request()->tracer) && isset(request()->rootSpan)) {
            $samplingFlags = request()->rootSpan->getContext();
            $this->header['Trace-Id'] = $samplingFlags->getTraceId();
            $exceptionSpan = request()->tracer->newChild($samplingFlags);
            $exceptionSpan->setName('exception');
            $exceptionSpan->start();
            $exceptionSpan->tag('error.code', (string) $this->errorCode);
            $value = [
                'event' => 'error',
                'message' => $this->errorMessage,
                'stack' => 'Exception:' . $e->getFile() . '|' . $e->getLine(),
            ];
            $exceptionSpan->annotate(json_encode($value));
            $exceptionSpan->finish();
        }
    }

    /**
     * 构造 Response.
     *
     * @return Response
     */
    protected function buildResponse(): Response
    {
        $bodyKey = array_keys($this->config['body']);
        $responseBody = [
            $bodyKey[0] ?? 'code' => $this->errorCode,
            $bodyKey[1] ?? 'msg' => $this->errorMessage,
            $bodyKey[2] ?? 'data' => $this->responseData,
        ];

        $header = array_merge([
            'Access-Control-Allow-Credentials' => 'true',
//            'Access-Control-Allow-Origin'      => '*',
            'Access-Control-Max-Age'           => 1728000,
            'Access-Control-Allow-Methods'     => 'GET, POST, PATCH, PUT, DELETE',
            'Access-Control-Allow-Headers'     => 'Authorization, Content-Type,Accept,Origin,Keep-Alive,User-Agent,Cache-Control,Referer',
            'Content-Type'                     => 'application/json;charset=utf-8'
        ], $this->header);
        return new Response((('400' == $this->statusCode) ? 200 : $this->statusCode), $header, json_encode($responseBody));
    }
}
