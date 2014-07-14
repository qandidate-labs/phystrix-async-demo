<?php

function zipWithIndex($map1, $map2)
{
    $newMap = [];
    foreach ($map1 as $key => $value) {
        $newValue = [$value];
        $newValue[] = $map2[$key];

        $newMap[$key] = $newValue;
    }

    return $newMap;
}

function writeRequestLog($path, $requestLog) {
    $handle = fopen($path, 'a');

    foreach ($requestLog->getExecutedCommands() as $command) {
        $executionStats = $command->getCommandKey()
            . ' ' . $command->getExecutionTimeInMilliseconds()
            . ' ' . implode(',', $command->getExecutionEvents())
            . "\n";

        fwrite($handle, $executionStats);
    }

    fclose($handle);
}
