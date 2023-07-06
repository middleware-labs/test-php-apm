<?php
declare(strict_types=1);
namespace Middleware\AgentApmPhp;

require 'vendor/autoload.php';

use DateTime;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Span;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SemConv\ResourceAttributes;
use OpenTelemetry\SemConv\TraceAttributes;

use OpenTelemetry\API\Logs\EventLogger;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\LogRecordLimitsBuilder;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogsProcessor;

use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\SDK\Metrics\View\CriteriaViewRegistry;
use OpenTelemetry\SDK\Metrics\Exemplar\ExemplarFilter\WithSampledTraceExemplarFilter;
use OpenTelemetry\SDK\Metrics\StalenessHandler\ImmediateStalenessHandlerFactory;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\API\Metrics\ObserverInterface;

use OpenTelemetry\API\Common\Instrumentation;
use function OpenTelemetry\Instrumentation\hook;
use Throwable;

final class MwTracker {

    private string $host = 'localhost';
    private int $exportPort = 9320;
    private string $projectName;
    private string $serviceName;
    private TracerInterface $tracer;
    private Instrumentation\Configurator $scope;
    private TracerProvider $tracerProvider;
    private EventLogger $logger;
    private MeterInterface $meter;
    private MeterProvider $meterProvider;
    private ExportingReader $metricReader;

    public function __construct(string $projectName = null, string $serviceName = null) {
        if (!empty(getenv('MW_AGENT_SERVICE'))) {
            $this->host = getenv('MW_AGENT_SERVICE');
        }

        $pid = getmypid();
        $this->projectName = $projectName ?: 'Project-' . $pid;
        $this->serviceName = $serviceName ?: 'Service-' . $pid;

        $this->initTracer();
        $this->initLogger();
        $this->initMeter();
    }

    private function initTracer(): void {

        $traceTransport = (new OtlpHttpTransportFactory())->create(
            'http://' . $this->host . ':' . $this->exportPort . '/v1/traces',
            'application/x-protobuf');

        $traceExporter = new SpanExporter($traceTransport);

        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor($traceExporter),
            null,
            ResourceInfo::create(Attributes::create([
                'project.name' => $this->projectName,
                ResourceAttributes::SERVICE_NAME => $this->serviceName,
                Variables::OTEL_PHP_AUTOLOAD_ENABLED => true
            ]))
        );

        $tracer = $tracerProvider->getTracer('middleware/agent-apm-php', 'dev-master');

        $scope = Instrumentation\Configurator::create()
            ->withTracerProvider($tracerProvider);

        $this->tracerProvider = $tracerProvider;
        $this->tracer = $tracer;
        $this->scope = $scope;
    }

    private function initLogger(): void {

        $logTransport = (new OtlpHttpTransportFactory())->create(
            'http://' . $this->host . ':' . $this->exportPort . '/v1/logs',
            'application/x-protobuf');

        $logExporter = new LogsExporter($logTransport);

        $loggerProvider = new LoggerProvider(
            new SimpleLogsProcessor($logExporter),
            new InstrumentationScopeFactory(
                (new LogRecordLimitsBuilder())->build()->getAttributeFactory()
            ),
            ResourceInfo::create(Attributes::create([
                'project.name' => $this->projectName,
                ResourceAttributes::SERVICE_NAME => $this->serviceName,
                Variables::OTEL_PHP_AUTOLOAD_ENABLED => true,
            ]))
        );

        $this->logger = new EventLogger($loggerProvider->getLogger('middleware/agent-apm-php', 'dev-master'), 'middleware-apm-domain');

    }

    private function initMeter(): void {
        $clock = ClockFactory::getDefault();
        $metricTransport = (new OtlpHttpTransportFactory())->create(
            'http://' . $this->host . ':' . $this->exportPort . '/v1/metrics',
            'application/x-protobuf');

        $metricExporter = new MetricExporter($metricTransport, Temporality::CUMULATIVE);
        $metricReader = new ExportingReader($metricExporter, $clock);

        $meterProvider = new MeterProvider(
            null,
            ResourceInfo::create(Attributes::create([
                'project.name' => $this->projectName,
                'mw.app.lang' => 'php',
                'runtime.metrics.php' => 'true',
                ResourceAttributes::SERVICE_NAME => $this->serviceName,
                Variables::OTEL_PHP_AUTOLOAD_ENABLED => true,
            ])),
            $clock,
            Attributes::factory(),
            new InstrumentationScopeFactory(Attributes::factory()),
            [$metricReader],
            new CriteriaViewRegistry(),
            new WithSampledTraceExemplarFilter(),
            new ImmediateStalenessHandlerFactory(),
        );

        $this->meterProvider = $meterProvider;
        $this->metricReader = $metricReader;
        $this->meter = $this->meterProvider->getMeter('io.opentelemetry.contrib.php');
        // $this->collectMetrics();

        // Fetch system metrics periodically
        while (true) {
            $this->collectMetrics();

            // Wait for some time before fetching metrics again
            sleep(10);
        }
    }

    public function registerHook(string $className, string $functionName, ?iterable $attributes = null): void {
        $tracer = $this->tracer;
        $serviceName = $this->serviceName;
        $projectName = $this->projectName;

        hook(
            $className,
            $functionName,
            static function ($object, ?array $params, ?string $class, string $function, ?string $filename, ?int $lineno) use ($tracer, $serviceName, $projectName, $attributes) {
                $span = $tracer->spanBuilder(sprintf('%s::%s', $class, $function))
                    ->setAttribute('service.name', $serviceName)
                    ->setAttribute('project.name', $projectName)
                    ->setAttribute('code.function', $function)
                    ->setAttribute('code.namespace', $class)
                    ->setAttribute('code.filepath', $filename)
                    ->setAttribute('code.lineno', $lineno);

                if (!empty($attributes)) {
                    foreach ($attributes as $key => $value) {
                        $span->setAttribute($key, $value);
                    }
                }

                if (!empty($params)) {

                    // echo $function . PHP_EOL;
                    // print_r($params);
                    switch ($function) {
                        case 'curl_init':
                            isset($params[0]) && $span->setAttribute('code.params.uri', $params[0]);

                            break;
                        case 'curl_exec':
                            $span->setAttribute('code.params.curl', $params[0]);

                            break;

                        case 'fopen':
                            $span->setAttribute('code.params.filename', $params[0])
                                ->setAttribute('code.params.mode', $params[1]);

                            break;
                        case 'fwrite':
                            $span->setAttribute('code.params.file', $params[0])
                                ->setAttribute('code.params.data', $params[1]);

                            break;
                        case 'fread':
                            $span->setAttribute('code.params.file', $params[0])
                                ->setAttribute('code.params.length', $params[1]);

                            break;

                        case 'file_get_contents':
                        case 'file_put_contents':
                        $span->setAttribute('code.params.filename', $params[0]);

                            break;
                    }
                }

                $span = $span->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            static function ($object, ?array $params, mixed $return, ?Throwable $exception) use ($tracer) {
                if (!$scope = Context::storage()->scope()) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());
                if ($exception) {
                    $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                } else {
                    $span->setStatus(StatusCode::STATUS_OK);
                }
                // $exception && $span->recordException($exception);
                // $span->setStatus($exception ? StatusCode::STATUS_ERROR : StatusCode::STATUS_OK);
                $span->end();
            }
        );
    }

    public function preTrack(): void {
        $this->scope->activate();

        // these will support in php8.2 version.
        // $this->registerHook('fopen', 'fopen');
        // $this->registerHook('fwrite', 'fwrite');
        // $this->registerHook('fread', 'fread');
        // $this->registerHook('file_get_contents', 'file_get_contents');
        // $this->registerHook('file_put_contents', 'file_put_contents');
        // $this->registerHook('curl_init', 'curl_init');
        // $this->registerHook('curl_exec', 'curl_exec');
    }

    public function postTrack(): void {
        if (!$scope = Context::storage()->scope()) {
            return;
        }
        $scope->detach();
        $this->tracerProvider->shutdown();
    }

    private function logging(string $type, int $number, string $text): void {

        $traceId = '';
        $spanId = '';
        if ($scope = Context::storage()->scope()) {
            $span = Span::fromContext($scope->context());
            $spanContext = $span->getContext();
            $traceId = $spanContext->getTraceId();
            $spanId = $spanContext->getSpanId();
        }

        $timestamp = (new DateTime())->getTimestamp() * LogRecord::NANOS_PER_SECOND;

        $logRecord = (new LogRecord($text))
            ->setTimestamp($timestamp)
            ->setObservedTimestamp($timestamp)
            ->setSeverityText($type)
            ->setSeverityNumber($number)
            ->setAttributes([
                'project.name' => $this->projectName,
                'service.name' => $this->serviceName,
                'mw.app.lang' => 'php',
                'fluent.tag' => 'php.app',
                'level' => strtolower($type),
                'trace_id' => $traceId != 0 ? $traceId : '',
                'span_id' => $spanId != 0 ? $spanId : '',
            ]);

        $this->logger->logEvent(
            'logger',
            $logRecord
        );
    }

    public function debug(string $message): void {
        $this->logging('DEBUG', 5, $message);
    }

    public function info(string $message): void {
        $this->logging('INFO', 9, $message);
    }

    public function warn(string $message): void {
        $this->logging('WARN', 13, $message);
    }

    public function error(string $message): void {
        $this->logging('ERROR', 17, $message);
    }

    private function collectMetrics(): void {
        $meter = $this->meter;

        $memoryUsage = memory_get_usage(true);
        $memoryPeakUsage = memory_get_peak_usage(true);
        $cpuUsage = sys_getloadavg()[0];
        $diskUsageTotal = disk_total_space(__DIR__);
        $diskFreeSpace = disk_free_space(__DIR__);
        $diskUsageUsed = $diskUsageTotal - $diskFreeSpace;
        $diskSpaceUsage = $diskUsageUsed / $diskUsageTotal;

        $meter->createObservableUpDownCounter('process.memory.usage')
            ->observe(static function (ObserverInterface $observer) use ($memoryUsage): void {
                $observer->observe($memoryUsage);
            });

        $meter->createObservableUpDownCounter('process.peak_memory.usage')
            ->observe(static function (ObserverInterface $observer) use ($memoryPeakUsage): void {
                $observer->observe($memoryPeakUsage);
            });

        $meter->createObservableUpDownCounter('cpu.usage')
            ->observe(static function (ObserverInterface $observer) use ($cpuUsage) {
                $observer->observe($cpuUsage);
            });

        $meter->createObservableUpDownCounter('disk.usage.total')
            ->observe(static function (ObserverInterface $observer) use ($diskUsageTotal) {
                $observer->observe($diskUsageTotal);
            });

        $meter->createObservableUpDownCounter('disk.usage.free')
            ->observe(static function (ObserverInterface $observer) use ($diskFreeSpace) {
                $observer->observe($diskFreeSpace);
            });

        $meter->createObservableUpDownCounter('disk.usage.used')
            ->observe(static function (ObserverInterface $observer) use ($diskUsageUsed) {
                $observer->observe($diskUsageUsed);
            });

        $meter->createObservableUpDownCounter('disk.space.usage')
            ->observe(static function (ObserverInterface $observer) use ($diskSpaceUsage) {
                $observer->observe($diskSpaceUsage);
            });

        $meter->createObservableUpDownCounter('network.traffic')
            ->observe(static function (ObserverInterface $observer) {
                $networkStats = file_get_contents('/proc/net/dev');
                preg_match_all('/\w+: (\d+)/', $networkStats, $matches);
                $rxBytes = array_sum($matches[1]);
                $observer->observe($rxBytes);
            });

        /*$serverDuration = $meter->createHistogram('http.server.duration');
        $serverDuration->record(58, ['http.method' => 'GET', 'http.status_code' => "200"]);
        $serverDuration->record(108, ['http.method' => 'GET', 'http.status_code' => "200"]);
        $serverDuration->record(18, ['http.method' => 'GET', 'http.status_code' => "500"]);*/

        // --------------------

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'LIN') {
            // Supporting on Linux

            // $cpuCore = shell_exec('nproc');
            $pid = getmypid();
            $processCpuPercentage = shell_exec("ps -p " . $pid . " -o %cpu | tail -n 1");
            $processMemoryPercentage = shell_exec("ps -p " . $pid . " -o %mem | tail -n 1");
            $processStartTime = $_SERVER['REQUEST_TIME'] * 1000;
            $processUptime = microtime(true) - $_SERVER['REQUEST_TIME'];
            $fdCount = shell_exec("ls /proc/" . $pid . "/fd | wc -l");
            $threadCount = shell_exec("ps -p " . $pid . " -o nlwp | tail -n 1");
            $tcpConnections = shell_exec("ss -tnp | grep " . $pid . " | wc -l");
            $ioReadOps = shell_exec("cat /proc/" . $pid . "/io | grep rchar | awk '{print $2}'");
            $ioWriteOps = shell_exec("cat /proc/" . $pid . "/io | grep wchar | awk '{print $2}'");
            $virtualMemorySize = shell_exec("ps -p " . $pid . " -o vsz | tail -n 1");
            $residentSetSize = shell_exec("ps -p " . $pid . " -o rss | tail -n 1");
            $childrenProcesses = shell_exec("ps -p " . $pid . " --no-headers --ppid " . $pid . " | wc -l");

            $meter->createObservableUpDownCounter('process.cpu_usage.percentage')
                ->observe(static function (ObserverInterface $observer) use ($processCpuPercentage) {
                    $observer->observe($processCpuPercentage);
                });

            $meter->createObservableUpDownCounter('process.memory_usage.percentage')
                ->observe(static function (ObserverInterface $observer) use ($processMemoryPercentage) {
                    $observer->observe($processMemoryPercentage);
                });

            $meter->createObservableUpDownCounter('process.start.time')
                ->observe(static function (ObserverInterface $observer) use ($processStartTime) {
                    $observer->observe($processStartTime);
                });

            $meter->createObservableUpDownCounter('process.up.time')
                ->observe(static function (ObserverInterface $observer) use ($processUptime) {
                    $observer->observe($processUptime);
                });

            $meter->createObservableUpDownCounter('process.file_descriptor.count')
                ->observe(static function (ObserverInterface $observer) use ($fdCount) {
                    $observer->observe($fdCount);
                });

            $meter->createObservableUpDownCounter('process.thread.count')
                ->observe(static function (ObserverInterface $observer) use ($threadCount) {
                    $observer->observe($threadCount);
                });

            $meter->createObservableUpDownCounter('process.tcp_connection.count')
                ->observe(static function (ObserverInterface $observer) use ($tcpConnections) {
                    $observer->observe($tcpConnections);
                });

            $meter->createObservableUpDownCounter('process.disk.io.read')
                ->observe(static function (ObserverInterface $observer) use ($ioReadOps) {
                    $observer->observe($ioReadOps);
                });

            $meter->createObservableUpDownCounter('process.disk.io.write')
                ->observe(static function (ObserverInterface $observer) use ($ioWriteOps) {
                    $observer->observe($ioWriteOps);
                });

            $meter->createObservableUpDownCounter('process.virtual_memory.size')
                ->observe(static function (ObserverInterface $observer) use ($virtualMemorySize) {
                    $observer->observe($virtualMemorySize);
                });

            $meter->createObservableUpDownCounter('process.rss.size')
                ->observe(static function (ObserverInterface $observer) use ($residentSetSize) {
                    $observer->observe($residentSetSize);
                });

            $meter->createObservableUpDownCounter('process.children.count')
                ->observe(static function (ObserverInterface $observer) use ($childrenProcesses) {
                    $observer->observe($childrenProcesses);
                });
        }

        $this->metricReader->collect();
    }

    public function __destruct() {
        if (isset($this->meterProvider)) {
            $this->meterProvider->shutdown();
        }
    }

}