<?php

$loader = require_once __DIR__ . '/../vendor/autoload.php';
$loader->add('Qandidate\\Phystrix', __DIR__ . '/');
$loader->add('Odesk\\Phystrix', __DIR__ . '/');

require_once __DIR__ . '/helpers.php';

use Clue\React\Buzz\Browser;
use Clue\React\Buzz\Message\Response;
use Qandidate\Phystrix\Async\Demo\TimeoutFactory;
use Qandidate\Phystrix\Async\Demo\Application;

$loop = React\EventLoop\Factory::create();

$client      = new Browser($loop);
$application = new Application($client, new TimeoutFactory($loop));

$cataloguePromise       = $application->getCatalogue();
$inventoryStatusPromise = $application->getInventoryStatus();

React\Promise\all([$cataloguePromise, $inventoryStatusPromise])
    ->then(function($responses) use ($loop) {
        list($catalogue, $inventoryStatus) = $responses;

        $combined = zipWithIndex($catalogue, $inventoryStatus);

        echo json_encode($combined) . "\n";

        $loop->stop();
    }, function($exception) use ($loop) {
        echo "omg, we failed and couldn't recover\n";

        echo $exception->getMessage() . "\n";

        $loop->stop();
    });

$loop->run();

// after the request has finished, we write to the command log
writeRequestLog(__DIR__ . '/../logs/commands.log', $application->getRequestLog());
