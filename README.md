# Getting Started

### agent-apm-php
Description: Agent APM for PHP

### Prerequisites
* To monitor APM data on dashboard, [Middleware Host-agent](https://docs.middleware.io/docs/getting-started) needs to be installed, You can refer [this demo project](https://github.com/middleware-labs/demo-apm/tree/master/php) to refer use cases of APM.
* PHP requires at least PHP 8+ and [OpenTelemetry PHP-Extension](https://opentelemetry.io/docs/instrumentation/php/automatic/#setup ) to run this agent.


### Guides
To use this APM agent, follow below steps:
1. Run `composer require middleware/agent-apm-php` in your project directory.
2. After successful installation, you need to add `require 'vendor/autoload.php';` in your file.
3. Then after, you need to add `use Middleware\AgentApmPhp\MwTracker;` line.
4. Now, add following code to the next line with your Project & Service name:
   ```
   $tracker = new MwTracker('<PROJECT-NAME>', '<SERVICE-NAME>');
   ```
5. Then we have 2 functions, named `preTrack()` & `postTrack()`, your code must be placed between these functions. After preTrack() calls, you need to register your desired classes & functions as follows:
   ```
   $tracker->preTrack();
   $tracker->registerHook('<CLASS-NAME-1>', '<FUNCTION-NAME-1>');
   $tracker->registerHook('<CLASS-NAME-2>', '<FUNCTION-NAME-2>');
   ```
6. You can add your own custom attributes as the third parameter, and checkout many other pre-defined attributes [here](https://opentelemetry.io/docs/reference/specification/trace/semantic_conventions/span-general/). 
   ```
   $tracker->registerHook('<CLASS-NAME-1>', '<FUNCTION-NAME-1>', [
       'custom.attr1' => 'value1',
       'custom.attr2' => 'value2',
   ]);
   ``` 
7. At the end, just call `postTrack()` function, which will send all the traces to the Middleware Host-agent.
   ```
   $tracker->postTrack();
   ``` 
8. If you want to enable Logging feature along with tracing in your project, then you can use below code snippet:
   ```
   $tracker->warn("this is warning log.");
   $tracker->error("this is error log.");
   $tracker->info("this is info log.");
   $tracker->debug("this is debug log.");
   ```
9. So, final code snippet will look like as:
   ```
   <?php
   require 'vendor/autoload.php';
   use Middleware\AgentApmPhp\MwTracker;
   
   $tracker = new MwTracker('<PROJECT-NAME>', '<SERVICE-NAME>');
   $tracker->preTrack();
   $tracker->registerHook('<CLASS-NAME-1>', '<FUNCTION-NAME-1>', [
       'custom.attr1' => 'value1',
       'custom.attr2' => 'value2',
   ]);
   $tracker->registerHook('<CLASS-NAME-2>', '<FUNCTION-NAME-2>');
   
   $tracker->info("this is info log.");
   
   // ----
   // Your code goes here.
   // ----
   
   $tracker->postTrack();
   ```


*Note: OTEL collector endpoint for all the traces, will be `http://localhost:9320/v1/traces` by default.*

### Sample Code
```
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

$tracker->info("this is info log.");

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
```