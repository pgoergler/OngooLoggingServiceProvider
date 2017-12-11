<?php

namespace Logging\LoggingServiceProvider;

use Pimple\Container,
    Pimple\ServiceProviderInterface;

/**
 * Description of LoggingServiceProvider
 *
 * @author paul
 */
class LoggingServiceProvider implements ServiceProviderInterface
{

    public function boot(Container $app)
    {
        if ($app->offsetExists('logger.directory'))
        {
            $app['logger.factory']->set('dir_log', $app['logger.directory']);
        } else
        {
            $app['logger.factory']->set('dir_log', './');
        }

        $app['logger.factory']->configure($app['ongoo.loggers']);

        if ($app->offsetExists('logger') && $app['logger'] !== null)
        {
            if ($app->offsetExists('error_handler') && $app['error_handler'] !== null)
            {
                set_error_handler($app['error_handler']);
                $app['logging.previous_exception_handler'] = set_exception_handler($app['logger.exception_handler']);
            }

            if ($app->offsetExists('shutdown_handler'))
            {
                register_shutdown_function($app['shutdown_handler']);
            }
        }
    }

    public function register(Container $app)
    {
        $app['logger.factory'] = function() use(&$app)
        {
            $factory = \Logging\LoggersManager::getInstance();
            if ($app->offsetExists('logger.class'))
            {
                $factory->setLoggerClass($app['logger.class']);
            }
            return $factory;
        };
        
        $app['logger'] = $app->factory(function() use(&$app){
            return $app['logger.factory']->get('root');
        });
        
        if (!$app->OffsetExists('request.logger.change'))
        {
            $app['request.logger.change'] = $app->protect(function($loggerName) use (&$app)
            {
                return function(\Symfony\Component\HttpFoundation\Request $request) use (&$app, $loggerName)
                {
                    $root = $app['logger.factory']->get($loggerName);
                    $app['logger.factory']->add($root, 'root');
                    $app['logger'] = $root;
                };
            });
        }

        $previousHandler = null;
        if ($app->offsetExists('logger.exception_handler'))
        {
            $previousHandler = $app['logger.exception_handler'];
        }
        
        $app['logger.exception_handler'] = $app->protect(function ($e, $code = null) use(&$app, &$previousHandler)
        {
            if( !is_null($previousHandler) )
            {
                try {
                    call_user_func($previousHandler, $e, $code);
                } catch (\Exception $handlerException) {
                } catch (\Throwable $handlerException) {
                }
            }
            if( $app->offsetExists('logging.previous_exception_handler') ) {
                try {
                    call_user_func($app['logging.previous_exception_handler'], $e, $code);
                } catch (\Exception $handlerException) {
                } catch (\Throwable $handlerException) {
                }
            }

            $app['logger']->error("Error catcher has catch:");
            $app['logger']->error($e);
        });

        $app->error(function ($e, $code) use(&$app)
        {
            $app['logger.exception_handler']($e, $code);
        });

        $app['logger.interpolate'] = $app->protect(function($message, $context = array()) use(&$app)
        {
            return $app['logger.factory']->interpolate($message, $context);
        });

        $app['logger.prettydump'] = $app->protect(function($variable, $context = array()) use(&$app)
        {
            return $app['logger.factory']->prettydump($variable, $context);
        });

        if (!$app->offsetExists('error_handler'))
        {
            $app['error_handler'] = $app->protect(function($errno, $errstr, $errfile, $errline, $errcontext) use(&$app)
            {
                if (!(error_reporting() & $errno))
                {
                    // This error code is not included in error_reporting
                    return;
                }


                if (!isset($errcontext['debug_backtrace']))
                {
                    $stack = debug_backtrace();
                    \array_shift($stack);
                    $context = array(
                        0 => $errcontext,
                        'debug_backtrace' => $stack
                    );
                } else
                {
                    $context = $errcontext;
                }

                $errDescription = implode('|', $app['errno_converter']($errno));

                $logLevel = 'critical';
                switch ($errno)
                {
                    case E_ERROR:
                    case E_USER_ERROR:
                        $logLevel = 'error';
                        break;
                    case E_NOTICE:
                    case E_USER_NOTICE:
                        $logLevel = 'notice';
                        break;
                    case E_WARNING:
                    case E_USER_WARNING:
                        $logLevel = 'warning';
                        break;
                    case E_DEPRECATED:
                        $logLevel = 'info';
                        break;
                    case E_STRICT:
                        $logLevel = 'alert';
                        break;
                }
                $app['logger']->$logLevel("[$errDescription($errno)] in $errfile at line $errline message: $errstr, context: {}", $context);
            });
        }

        if (!$app->offsetExists('errno_converter'))
        {
            $app['errno_converter'] = $app->protect(function($errno) use(&$app)
            {
                $error_description = array();
                $error_codes = array(
                    E_ERROR => "E_ERROR",
                    E_WARNING => "E_WARNING",
                    E_PARSE => "E_PARSE",
                    E_NOTICE => "E_NOTICE",
                    E_CORE_ERROR => "E_CORE_ERROR",
                    E_CORE_WARNING => "E_CORE_WARNING",
                    E_COMPILE_ERROR => "E_COMPILE_ERROR",
                    E_COMPILE_WARNING => "E_COMPILE_WARNING",
                    E_USER_ERROR => "E_USER_ERROR",
                    E_USER_WARNING => "E_USER_WARNING",
                    E_USER_NOTICE => "E_USER_NOTICE",
                    E_STRICT => "E_STRICT",
                    E_RECOVERABLE_ERROR => "E_RECOVERABLE_ERROR",
                    E_DEPRECATED => "E_DEPRECATED",
                    E_USER_DEPRECATED => "E_USER_DEPRECATED",
                    E_ALL => "E_ALL"
                );
                foreach ($error_codes as $number => $description)
                {
                    if (( $number & $errno ) == $number)
                    {
                        $error_description[] = $description;
                    }
                }
                return $error_description;
            });
        }

        if (!$app->offsetExists('shutdown_handler'))
        {
            $app['shutdown_handler'] = $app->protect(function() use(&$app)
            {
                $error = error_get_last();
                if ($error === null)
                {
                    return;
                }

                $errno = $error['type'];
                $errfile = $error['file'];
                $errline = $error['line'];
                $errstr = $error['message'];

                $context = array(
                    'debug_backtrace' => array(
                        array(
                            'file' => $errfile,
                            'line' => $errline,
                        )
                    )
                );

                $app['error_handler']($errno, $errstr, $errfile, $errline, $context);
            });
        }
    }

}

if (class_exists('\Symfony\Component\HttpKernel\Log\LoggerInterface'))
{

    class Logger extends \Logging\Logger implements \Symfony\Component\HttpKernel\Log\LoggerInterface
    {

        public function crit($message, array $context = array())
        {
            $context['debug_backtrace'] = debug_backtrace();
            $this->critical($message, $context);
        }

        public function emerg($message, array $context = array())
        {
            $context['debug_backtrace'] = debug_backtrace();
            $this->emergency($message, $context);
        }

        public function err($message, array $context = array())
        {
            $context['debug_backtrace'] = debug_backtrace();
            $this->error($message, $context);
        }

        public function warn($message, array $context = array())
        {
            $context['debug_backtrace'] = debug_backtrace();
            $this->warning($message, $context);
        }

    }

}
