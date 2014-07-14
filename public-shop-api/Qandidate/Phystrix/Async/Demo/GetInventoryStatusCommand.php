<?php

namespace Qandidate\Phystrix\Async\Demo;

use Clue\React\Buzz\Browser;
use Odesk\Phystrix\AbstractAsyncCommand;

class GetInventoryStatusCommand extends AbstractAsyncCommand
{
    private $client;
    private $timeoutFactory;
    private $url;

    public function __construct(Browser $client, $timeoutFactory)
    {
        $this->client         = $client;
        $this->timeoutFactory = $timeoutFactory;
        $this->url            = 'http://127.0.0.1:8002/';
    }

    protected function run()
    {
        return \React\Promise\race([
            $this->client->get($this->url)->then(function($response) {
                return json_decode($response->getBody(), true);
            }),
            $this->timeoutFactory->create(500)
        ]);
    }

    protected function getFallback()
    {
        return [
            '42' => -1,
            '1337' => -1
        ];
    }

    protected function getCacheKey()
    {
        return $this->url;
    }
}
