<?php
require 'vendor/autoload.php';
use Middleware\AgentApmPhp\MwTracker;

$tracker = new MwTracker('DemoProject', 'PrintService');
$tracker->preTrack();
$tracker->registerHook('DemoClass', 'runCode', [
    'code.column' => '12',
    'net.host.name' => 'localhost',
    'db.name' => 'users',
    'custom.attr1' => 'value1',
]);
$tracker->registerHook('DoThings', 'printString');

$tracker->warn("this is warning log.");
$tracker->error("this is error log.");
$tracker->info("this is info log.");
$tracker->debug("this is debug log.");

class DoThings {
    public static function printString($str): void {
        // sleep(1);
        global $tracker;
        $tracker->warn("this is warning log, but from inner function.");

        echo $str . PHP_EOL;
    }
}

class DemoClass {
    public static function runCode(): void {
        DoThings::printString('Hello World!');
    }
}

DemoClass::runCode();

$tracker->postTrack();