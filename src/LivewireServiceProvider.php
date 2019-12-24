<?php

namespace Livewire;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Livewire\Commands\CpCommand;
use Livewire\Commands\MvCommand;
use Livewire\Commands\RmCommand;
use Livewire\Macros\RouteMacros;
use Livewire\Macros\RouterMacros;
use Livewire\Commands\CopyCommand;
use Livewire\Commands\MakeCommand;
use Livewire\Commands\MoveCommand;
use Livewire\Commands\StubCommand;
use Livewire\Commands\TouchCommand;
use Livewire\Commands\DeleteCommand;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Blade;
use Livewire\Commands\ComponentParser;
use Livewire\Commands\DiscoverCommand;
use Illuminate\Support\ServiceProvider;
use Livewire\Commands\MakeLivewireCommand;
use Livewire\Connection\HttpConnectionHandler;
use Livewire\HydrationMiddleware\ForwardPrefetch;
use Livewire\HydrationMiddleware\PersistErrorBag;
use Illuminate\Support\Facades\Route as RouteFacade;
use Livewire\HydrationMiddleware\InterceptRedirects;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Livewire\HydrationMiddleware\RegisterEmittedEvents;
use Livewire\HydrationMiddleware\HydratePublicProperties;
use Livewire\HydrationMiddleware\IncludeIdAsRootTagAttribute;
use Livewire\HydrationMiddleware\SecureHydrationWithChecksum;
use Livewire\HydrationMiddleware\RegisterEventsBeingListenedFor;
use Livewire\HydrationMiddleware\HashPropertiesForDirtyDetection;
use Livewire\HydrationMiddleware\HydratePreviouslyRenderedChildren;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Livewire\HydrationMiddleware\ClearFlashMessagesIfNotRedirectingAway;
use Livewire\HydrationMiddleware\PrioritizeDataUpdatesBeforeActionCalls;
use Livewire\Experiments\CacheProtectedProperties\HydrationMiddleware\HydrateProtectedProperties;
use Livewire\Experiments\CacheProtectedProperties\HydrationMiddleware\GarbageCollectUnusedComponents;

class LivewireServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton('livewire', LivewireManager::class);

        $this->app->singleton(LivewireComponentsFinder::class, function () {
            $isHostedOnVapor = $_ENV['SERVER_SOFTWARE'] ?? null === 'vapor';
            return new LivewireComponentsFinder(
                new Filesystem,
                config('livewire.manifest_path') ?? (
                    $isHostedOnVapor
                        ? '/tmp/storage/bootstrap/cache/livewire-components.php'
                        : app()->bootstrapPath('cache/livewire-components.php')),
                ComponentParser::generatePathFromNamespace(config('livewire.class_namespace', 'App\\Http\\Livewire'))
            );
        });
    }

    public function boot()
    {
        if ($this->app['livewire']->isLivewireRequest()) {
            $this->bypassMiddleware([
                TrimStrings::class,
                // In case the user has over-rode "TrimStrings"
                \App\Http\Middleware\TrimStrings::class,
                ConvertEmptyStringsToNull::class,
            ]);
        }

        $this->registerViews();
        $this->registerRoutes();
        $this->registerCommands();
        $this->registerRouteMacros();
        $this->registerPublishables();
        $this->registerBladeDirectives();
        $this->registerViewCompilerEngine();
        $this->registerHydrationMiddleware();
    }

    public function registerViews()
    {
        // This is for Livewire's pagination views.
        $this->loadViewsFrom(__DIR__ . DIRECTORY_SEPARATOR . 'views', config('livewire.view-path', 'livewire'));
    }

    public function registerRoutes()
    {
        RouteFacade::get('/livewire/livewire.js', [LivewireJavaScriptAssets::class, 'unminified']);
        RouteFacade::get('/livewire/livewire.min.js', [LivewireJavaScriptAssets::class, 'minified']);

        RouteFacade::post('/livewire/message/{name}', HttpConnectionHandler::class)
            ->middleware(config('livewire.middleware_group', 'web'));
    }

    public function registerCommands()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CopyCommand::class,
                CpCommand::class,
                DeleteCommand::class,
                DiscoverCommand::class,
                MakeCommand::class,
                MakeLivewireCommand::class,
                MoveCommand::class,
                MvCommand::class,
                RmCommand::class,
                TouchCommand::class,
                StubCommand::class,
            ]);
        }
    }

    public function registerRouteMacros()
    {
        Route::mixin(new RouteMacros);
        Router::mixin(new RouterMacros);
    }

    public function registerBladeDirectives()
    {
        Blade::directive('livewireAssets', [LivewireBladeDirectives::class, 'livewireAssets']);
        Blade::directive('livewire', [LivewireBladeDirectives::class, 'livewire']);
    }

    public function registerHydrationMiddleware()
    {
        Livewire::registerInitialHydrationMiddleware([
            [InterceptRedirects::class, 'hydrate'],
        ]);

        Livewire::registerInitialDehydrationMiddleware([
            [PersistErrorBag::class, 'dehydrate'],
            [RegisterEventsBeingListenedFor::class, 'dehydrate'],
            [RegisterEmittedEvents::class, 'dehydrate'],
            [HydratePublicProperties::class, 'dehydrate'],
            [HydrateProtectedProperties::class, 'dehydrate'],
            [HydratePreviouslyRenderedChildren::class, 'dehydrate'],
            [SecureHydrationWithChecksum::class, 'dehydrate'],
            [IncludeIdAsRootTagAttribute::class, 'dehydrate'],
            [InterceptRedirects::class, 'dehydrate'],
        ]);

        Livewire::registerHydrationMiddleware([
            GarbageCollectUnusedComponents::class,
            IncludeIdAsRootTagAttribute::class,
            ClearFlashMessagesIfNotRedirectingAway::class,
            SecureHydrationWithChecksum::class,
            RegisterEventsBeingListenedFor::class,
            RegisterEmittedEvents::class,
            PersistErrorBag::class,
            HydratePublicProperties::class,
            HydratePreviouslyRenderedChildren::class,
            HashPropertiesForDirtyDetection::class,
            InterceptRedirects::class,
            PrioritizeDataUpdatesBeforeActionCalls::class,
            ForwardPrefetch::class,
        ]);
    }

    protected function registerPublishables()
    {
        $this->publishesToGroups([
            __DIR__ . '/../config/livewire.php' => base_path('config/livewire.php'),
        ], ['livewire', 'livewire:config']);

        $this->publishesToGroups([
            __DIR__ . '/../dist' => public_path('vendor/livewire'),
        ], ['livewire', 'livewire:assets']);
    }

    protected function registerViewCompilerEngine()
    {
        $this->app->make('view.engine.resolver')->register('blade', function () {
            return new LivewireViewCompilerEngine($this->app['blade.compiler']);
        });
    }

    protected function bypassMiddleware(array $middlewareToExclude)
    {
        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);

        $openKernel = new ObjectPrybar($kernel);

        $middleware = $openKernel->getProperty('middleware');

        $openKernel->setProperty('middleware', array_diff($middleware, $middlewareToExclude));
    }

    protected function publishesToGroups(array $paths, $groups = null)
    {
        if (is_null($groups)) {
            $this->publishes($paths);

            return;
        }

        foreach ((array) $groups as $group) {
            $this->publishes($paths, $group);
        }
    }
}
