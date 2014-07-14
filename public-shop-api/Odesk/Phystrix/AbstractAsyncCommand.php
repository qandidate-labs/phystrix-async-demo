<?php
/**
 * This file is a part of the Phystrix library
 *
 * Copyright 2013-2014 oDesk Corporation. All Rights Reserved.
 *
 * This file is licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
namespace Odesk\Phystrix;

use Odesk\Phystrix\Exception\BadRequestException;
use Odesk\Phystrix\Exception\FallbackNotAvailableException;
use Odesk\Phystrix\Exception\RuntimeException;
use Zend\Di\LocatorInterface;
use Zend\Config\Config;
use Exception;

/**
 * All Phystrix commands must extend this class
 */
abstract class AbstractCommand
{
    const EVENT_SUCCESS = 'SUCCESS';
    const EVENT_FAILURE = 'FAILURE';
    const EVENT_TIMEOUT = 'TIMEOUT';
    const EVENT_SHORT_CIRCUITED = 'SHORT_CIRCUITED';
    const EVENT_FALLBACK_SUCCESS = 'FALLBACK_SUCCESS';
    const EVENT_FALLBACK_FAILURE = 'FALLBACK_FAILURE';
    const EVENT_EXCEPTION_THROWN = 'EXCEPTION_THROWN';
    const EVENT_RESPONSE_FROM_CACHE = 'RESPONSE_FROM_CACHE';

    /**
     * Command Key, used for grouping Circuit Breakers
     *
     * @var string
     */
    protected $commandKey;

    /**
     * Command configuration
     *
     * @var Config
     */
    protected $config;

    /**
     * @var CircuitBreakerFactory
     */
    private $circuitBreakerFactory;

    /**
     * @var CommandMetricsFactory
     */
    private $commandMetricsFactory;

    /**
     * @var LocatorInterface
     */
    protected $serviceLocator;

    /**
     * @var RequestCache
     */
    private $requestCache;

    /**
     * @var RequestLog
     */
    private $requestLog;

    /**
     * Events logged during execution
     *
     * @var array
     */
    private $executionEvents = array();

    /**
     * Execution time in milliseconds
     *
     * @var integer
     */
    private $executionTime;

    /**
     * Exception thrown if there was one
     *
     * @var \Exception
     */
    private $executionException;

    /**
     * Timestamp in milliseconds
     *
     * @var integer
     */
    private $invocationStartTime;

    /**
     * Determines and returns command key, used for circuit breaker grouping and metrics tracking
     *
     * @return string
     */
    public function getCommandKey()
    {
        if ($this->commandKey) {
            return $this->commandKey;
        } else {
            // If the command key hasn't been defined in the class we use the current class name
            return get_class($this);
        }
    }

    /**
     * Sets instance of circuit breaker factory
     *
     * @param CircuitBreakerFactory $circuitBreakerFactory
     */
    public function setCircuitBreakerFactory(CircuitBreakerFactory $circuitBreakerFactory)
    {
        $this->circuitBreakerFactory = $circuitBreakerFactory;
    }

    /**
     * Sets instance of command metrics factory
     *
     * @param CommandMetricsFactory $commandMetricsFactory
     */
    public function setCommandMetricsFactory(CommandMetricsFactory $commandMetricsFactory)
    {
        $this->commandMetricsFactory = $commandMetricsFactory;
    }

    /**
     * Sets shared object for request caching
     *
     * @param RequestCache $requestCache
     */
    public function setRequestCache(RequestCache $requestCache)
    {
        $this->requestCache = $requestCache;
    }

    /**
     * Sets shared object for request logging
     *
     * @param RequestLog $requestLog
     */
    public function setRequestLog(RequestLog $requestLog)
    {
        $this->requestLog = $requestLog;
    }

    /**
     * Sets base command configuration from the global phystrix configuration
     *
     * @param Config $phystrixConfig
     */
    public function initializeConfig(Config $phystrixConfig)
    {
        $commandKey = $this->getCommandKey();
        $config = new Config($phystrixConfig->get('default')->toArray(), true);
        if ($phystrixConfig->__isset($commandKey)) {
            $commandConfig = $phystrixConfig->get($commandKey);
            $config->merge($commandConfig);
        }
        $this->config = $config;
    }

    /**
     * Sets configuration for the command, allows to override config in runtime
     *
     * @param Config $config
     * @param bool $merge
     */
    public function setConfig(Config $config, $merge = true)
    {
        if ($this->config && $merge) {
            $this->config->merge($config);
        } else {
            $this->config = $config;
        }
    }

    /**
     * Determines whether request caching is enabled for this command
     *
     * @return bool
     */
    private function isRequestCacheEnabled()
    {
        return $this->config->get('requestCache')->get('enabled') && $this->getCacheKey() !== null;
    }

    /**
     * Executes the command
     * Isolation and fault tolerance logic (Circuit Breaker) is built-in
     *
     * @return mixed
     * @throws BadRequestException Re-throws it when the command throws it, without metrics tracking
     */
    public function execute()
    {
        $this->prepare();
        $metrics = $this->getMetrics();
        // always adding the command to request log
        $this->recordExecutedCommand();
        // trying from cache first
        if ($this->isRequestCacheEnabled()) {
            $fromCache = $this->requestCache->get($this->getCommandKey(), $this->getCacheKey());
            if ($fromCache !== null) {
                $this->executionEvents[] = self::EVENT_RESPONSE_FROM_CACHE;
                $metrics->markResponseFromCache();
                return $fromCache;
            }
        }
        $circuitBreaker = $this->getCircuitBreaker();
        if (!$circuitBreaker->allowRequest()) {
            $this->executionEvents[] = self::EVENT_SHORT_CIRCUITED;
            $metrics->markShortCircuited();
            return $this->getFallbackOrThrowException();
        }
        $this->invocationStartTime = $this->getTimeInMilliseconds();
        try {
            $result = $this->run();
            $this->recordExecutionTime();
            $this->executionEvents[] = self::EVENT_SUCCESS;
            $metrics->markSuccess();
            $circuitBreaker->markSuccess();
        } catch (BadRequestException $exception) {
            // Treated differently and allowed to propagate without any stats tracking or fallback logic
            $this->recordExecutionTime();
            throw $exception;
        } catch (Exception $exception) {
            $this->recordExecutionTime();
            $this->executionException = $exception;
            $this->executionEvents[] = self::EVENT_FAILURE;
            $metrics->markFailure();
            $result = $this->getFallbackOrThrowException($exception);
        }

        // putting the result into cache
        if ($this->isRequestCacheEnabled()) {
            $this->requestCache->put($this->getCommandKey(), $this->getCacheKey(), $result);
        }
        return $result;
    }

    /**
     * The code to be executed
     *
     * @return mixed
     */
    abstract protected function run();

    /**
     * Custom preparation logic, preceding command execution
     */
    protected function prepare()
    {
    }

    /**
     * Sets service locator instance, for injecting custom dependencies into the command
     *
     * @param LocatorInterface $serviceLocator
     */
    public function setServiceLocator(LocatorInterface $serviceLocator)
    {
        $this->serviceLocator = $serviceLocator;
    }

    /**
     * Command Metrics for this command key
     *
     * @return CommandMetrics
     */
    private function getMetrics()
    {
        return $this->commandMetricsFactory->get($this->getCommandKey(), $this->config);
    }

    /**
     * Circuit breaker for this command key
     *
     * @return CircuitBreaker
     */
    private function getCircuitBreaker()
    {
        return $this->circuitBreakerFactory->get($this->getCommandKey(), $this->config, $this->getMetrics());
    }

    /**
     * Attempts to retrieve fallback by calling getFallback
     *
     * @param Exception $exception (Optional) If null, the request was short-circuited
     * @return mixed
     * @throws RuntimeException When fallback is disabled, not available for the command, or failed retrieving
     */
    private function getFallbackOrThrowException(Exception $originalException = null)
    {
        $metrics = $this->getMetrics();
        $message = $originalException === null ? 'Short-circuited' : $originalException->getMessage();
        try {
            if ($this->config->get('fallback')->get('enabled')) {
                try {
                    $executionResult = $this->getFallback();
                    $this->executionEvents[] = self::EVENT_FALLBACK_SUCCESS;
                    $metrics->markFallbackSuccess();
                    return $executionResult;
                } catch (FallbackNotAvailableException $fallbackException) {
                    throw new RuntimeException(
                        $message . ' and no fallback available',
                        get_class($this),
                        $originalException
                    );
                } catch (Exception $fallbackException) {
                    $this->executionEvents[] = self::EVENT_FALLBACK_FAILURE;
                    $metrics->markFallbackFailure();
                    throw new RuntimeException(
                        $message . ' and failed retrieving fallback',
                        get_class($this),
                        $originalException,
                        $fallbackException
                    );
                }
            } else {
                throw new RuntimeException(
                    $message . ' and fallback disabled',
                    get_class($this),
                    $originalException
                );
            }
        } catch (Exception $exception) {
            // count that we are throwing an exception and re-throw it
            $this->executionEvents[] = self::EVENT_EXCEPTION_THROWN;
            $metrics->markExceptionThrown();
            throw $exception;
        }
    }

    /**
     * Code for when execution fails for whatever reason
     *
     * @throws FallbackNotAvailableException When no custom fallback provided
     */
    protected function getFallback()
    {
        throw new FallbackNotAvailableException('No fallback available');
    }

    /**
     * Key to be used for request caching.
     *
     * By default this return null, which means "do not cache". To enable caching,
     * override this method and return a string key uniquely representing the state of a command instance.
     *
     * If multiple command instances are executed within current HTTP request, only the first one will be
     * executed and all others returned from cache.
     *
     * @return string|null
     */
    protected function getCacheKey()
    {
        return null;
    }

    /**
     * Returns events collected
     *
     * @return array
     */
    public function getExecutionEvents()
    {
        return $this->executionEvents;
    }

    /**
     * Records command execution time if the command was executed, not short-circuited and not returned from cache
     */
    private function recordExecutionTime()
    {
        $this->executionTime = $this->getTimeInMilliseconds() - $this->invocationStartTime;
    }

    /**
     * Returns execution time in milliseconds, null if not executed
     *
     * @return null|integer
     */
    public function getExecutionTimeInMilliseconds()
    {
        return $this->executionTime;
    }

    /**
     * Returns exception thrown while executing the command, if there was any
     *
     * @return Exception|null
     */
    public function getExecutionException()
    {
        return $this->executionException;
    }

    /**
     * Returns current time on the server in milliseconds
     *
     * @return float
     */
    private function getTimeInMilliseconds()
    {
        return floor(microtime(true) * 1000);
    }

    /**
     * Adds reference to the command to the current request log
     */
    private function recordExecutedCommand()
    {
        if ($this->config->get('requestLog')->get('enabled')) {
            $this->requestLog->addExecutedCommand($this);
        }
    }
}
