<?php

namespace Qandidate\Phystrix\Async\Demo;

use Clue\React\Buzz\Browser;
use Odesk\Phystrix\AbstractCommand;
use Odesk\Phystrix\ApcStateStorage;
use Odesk\Phystrix\CircuitBreakerFactory;
use Odesk\Phystrix\CommandMetricsFactory;
use Odesk\Phystrix\CommandFactory;
use Zend\Config\Config;

// custom
use Odesk\Phystrix\AbstractAsyncCommand;

class Application
{
    private $client;
    private $phystrix;
    private $timeoutFactory;
    private $requestLog;

    public function __construct(Browser $client, TimeoutFactory $timeoutFactory)
    {
        $this->client         = $client;
        $this->timeoutFactory = $timeoutFactory;
        $this->requestLog     = new \Odesk\Phystrix\RequestLog();
        $this->phystrix       = $this->setUpPhystrix();
    }

    public function getCatalogue()
    {
        $command = $this->phystrix->getCommand('Qandidate\\Phystrix\\Async\\Demo\\GetCatalogueCommand', $this->client, $this->timeoutFactory);

        return $command->execute();
    }

    public function getInventoryStatus()
    {
        $command = $this->phystrix->getCommand('Qandidate\\Phystrix\\Async\\Demo\\GetInventoryStatusCommand', $this->client, $this->timeoutFactory);

        return $command->execute();
    }

    public function getRequestLog()
    {
        return $this->requestLog;
    }

    private function setUpPhystrix()
    {
        $stateStorage = new ApcStateStorage();
        $circuitBreakerFactory = new CircuitBreakerFactory($stateStorage);
        $commandMetricsFactory = new CommandMetricsFactory($stateStorage);


        return new CommandFactory(
            new Config($this->getConfig()),
            new \Zend\Di\ServiceLocator(),
            $circuitBreakerFactory,
            $commandMetricsFactory,
            new \Odesk\Phystrix\RequestCache(),
            $this->requestLog
        );
    }

    private function getConfig()
    {
    // NOTE: copied form readme, not modified except of command name
        return array(
            'default' => array( // Default command configuration
                'fallback' => array(
                    // Whether fallback logic of the phystrix command is enabled
                    'enabled' => true,
                ),
                'circuitBreaker' => array(
                    // Whether circuit breaker is enabled, if not Phystrix will always allow a request
                    'enabled' => true,
                    // How many failed request it might be before we open the circuit (disallow consecutive requests)
                    'errorThresholdPercentage' => 10,
                    // If true, the circuit breaker will always be open regardless the metrics
                    'forceOpen' => false,
                    // If true, the circuit breaker will always be closed, allowing all requests, regardless the metrics
                    'forceClosed' => false,
                    // How many requests we need minimally before we can start making decisions about service stability
                    'requestVolumeThreshold' => 10,
                    // For how long to wait before attempting to access a failing service
                    'sleepWindowInMilliseconds' => 2000,
                ),
                'metrics' => array(
                    // This is for caching metrics so they are not recalculated more often than needed
                    'healthSnapshotIntervalInMilliseconds' => 100,
                    // The period of time within which we the stats are collected
                    'rollingStatisticalWindowInMilliseconds' => 5000,
                    // The more buckets the more precise and actual the stats and slower the calculation.
                    'rollingStatisticalWindowBuckets' => 10,
                ),
                'requestCache' => array(
                    // Request cache, if enabled and a command has getCacheKey implemented
                    // caches results within current http request
                    'enabled' => true,
                ),
                'requestLog' => array(
                    // Request log collects all commands executed within current http request
                    'enabled' => true,
                ),
            ),
        );
    }
}
