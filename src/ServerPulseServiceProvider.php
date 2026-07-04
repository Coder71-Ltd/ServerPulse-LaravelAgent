<?php

namespace ServerPulse\Agent;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use ServerPulse\Agent\Collectors\DatabaseCollector;
use ServerPulse\Agent\Collectors\DomainCollector;
use ServerPulse\Agent\Collectors\GitCollector;
use ServerPulse\Agent\Collectors\LaravelCollector;
use ServerPulse\Agent\Collectors\LogsCollector;
use ServerPulse\Agent\Collectors\PhpCollector;
use ServerPulse\Agent\Collectors\SecurityCollector;
use ServerPulse\Agent\Collectors\ServerCollector;
use ServerPulse\Agent\Collectors\WebServerCollector;
use ServerPulse\Agent\Console\Commands\ReportCommand;
use ServerPulse\Agent\Middleware\BlockMiddleware;
use ServerPulse\Agent\Middleware\RequestTaggingMiddleware;
use ServerPulse\Agent\Monolog\ServerPulseHandler;
use ServerPulse\Agent\Services\ConfigService;
use ServerPulse\Agent\Services\ReportService;

class ServerPulseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ConfigService::class);
        $this->app->singleton(ReportService::class);
        $this->app->singleton(ServerPulseHandler::class);

        $this->app->tag(ServerCollector::class, 'serverpulse.collectors');
        $this->app->tag(WebServerCollector::class, 'serverpulse.collectors');
        $this->app->tag(PhpCollector::class, 'serverpulse.collectors');
        $this->app->tag(DatabaseCollector::class, 'serverpulse.collectors');
        $this->app->tag(GitCollector::class, 'serverpulse.collectors');
        $this->app->tag(LogsCollector::class, 'serverpulse.collectors');
        $this->app->tag(SecurityCollector::class, 'serverpulse.collectors');
        $this->app->tag(LaravelCollector::class, 'serverpulse.collectors');
        $this->app->tag(DomainCollector::class, 'serverpulse.collectors');
    }

    public function boot(): void
    {
        $this->commands([ReportCommand::class]);

        $this->callAfterResolving(Kernel::class, function (Kernel $kernel): void {
            if (method_exists($kernel, 'prependMiddleware')) {
                /** @var \Illuminate\Foundation\Http\Kernel $kernel */
                $kernel->prependMiddleware(BlockMiddleware::class);
            }

            if (method_exists($kernel, 'pushMiddleware')) {
                /** @var \Illuminate\Foundation\Http\Kernel $kernel */
                $kernel->pushMiddleware(RequestTaggingMiddleware::class);
            }
        });

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('serverpulse:report')
                ->everyMinute()
                ->withoutOverlapping(55);
        });

        $this->callAfterResolving('log', function (LogManager $log): void {
            $handler = app(ServerPulseHandler::class);
            $log->driver()->getLogger()->pushHandler($handler);
        });
    }
}
