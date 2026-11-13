<?php

namespace Tests\Feature;

use App\Models\Visit;
use App\Support\SentryPrivacy;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\Tracing\SamplingContext;
use Sentry\Tracing\TransactionContext;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Stevebauman\Location\Facades\Location;
use Stevebauman\Location\Position;
use Tests\TestCase;

/**
 * The tracker's requests and their analytics data stay out of Sentry
 * (App\Support\SentryPrivacy, config/sentry.php), and nothing else changes.
 *
 * Sentry runs for real here: the app boots with a DSN, as in production, so its
 * features (cache breadcrumbs, request data) are on; only the transport is
 * replaced, so events are captured instead of sent. Nothing leaves the machine.
 */
class SentryPrivacyTest extends TestCase
{
    use RefreshDatabase;

    /** Events that would have been sent to Sentry. */
    private static ?TransportInterface $transport = null;

    public function createApplication()
    {
        self::$transport = new class implements TransportInterface
        {
            /** @var list<Event> */
            public array $events = [];

            public function send(Event $event): Result
            {
                $this->events[] = $event;

                return new Result(ResultStatus::success(), $event);
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success());
            }
        };

        $app = require __DIR__.'/../../bootstrap/app.php';
        // Before the providers boot, so Sentry's features start as they do with a DSN in production.
        $app->beforeBootstrapping(BootProviders::class, function ($app) {
            $app['config']->set('sentry.dsn', 'https://public@sentry.invalid/1');
            $app['config']->set('sentry.transport', self::$transport);
            $app['config']->set('sentry.breadcrumbs.cache', true);
            $app['config']->set('sentry.traces_sample_rate', 1.0); // every other request traced
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function tearDown(): void
    {
        // Sentry's hub is global: later tests get a fresh one, without this DSN.
        SentrySdk::setCurrentHub(new Hub);
        parent::tearDown();
    }

    /** @return list<Event> error events (not transactions) captured so far */
    private function errors(): array
    {
        return array_values(array_filter(self::$transport->events, fn (Event $event) => (string) $event->getType() === 'event'));
    }

    private function cacheBreadcrumbs(Event $event): array
    {
        return array_values(array_filter($event->getBreadcrumbs(), fn (Breadcrumb $b) => $b->getCategory() === 'cache'));
    }

    public function test_an_error_in_the_tracker_reaches_sentry_without_the_request_body_or_cache_breadcrumbs(): void
    {
        $this->assertNotNull(SentrySdk::getCurrentHub()->getClient()?->getOptions()->getDsn(), 'Sentry is active in this test');
        // The GeoIP cache in use (its keys come from the IP), and an error after the lookup.
        config(['services.geoip.cache_salt' => 'test-secret']);
        $position = new Position;
        $position->countryCode = 'SE';
        Location::shouldReceive('get')->andReturn($position);
        Visit::creating(fn () => throw new RuntimeException('The database is down'));
        SentrySdk::getCurrentHub()->configureScope(fn ($scope) => $scope->clearBreadcrumbs());

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
            ->withHeaders(['User-Agent' => 'AuditBrowser/1.0'])
            ->postJson('/api/collect', ['url' => '/offer/', 'referrer' => 'https://news.example.org/story?id=42', 'language' => 'sv-SE', 'utm_source' => 'newsletter'])
            ->assertStatus(500);

        $errors = $this->errors();
        $this->assertCount(1, $errors, 'The error is still reported');
        $event = $errors[0];
        $this->assertSame('The database is down', $event->getExceptions()[0]->getValue());

        // Only the URL and the method of the request: no body, headers, query string or cookies.
        $this->assertSame(['url', 'method'], array_keys($event->getRequest()));
        $this->assertStringEndsWith('/api/collect', $event->getRequest()['url']);
        $sent = json_encode([$event->getRequest(), array_map(fn (Breadcrumb $b) => [$b->getCategory(), $b->getMessage(), $b->getMetadata()], $event->getBreadcrumbs())]);
        foreach (['/offer/', 'news.example.org', 'sv-SE', 'newsletter', 'AuditBrowser', '203.0.113.77', 'geo:'] as $analytics) {
            $this->assertStringNotContainsString($analytics, $sent);
        }
        $this->assertSame([], $this->cacheBreadcrumbs($event));

        // The tracker's requests were not traced, though every other request is (rate 1.0 here).
        $transactions = array_filter(self::$transport->events, fn (Event $e) => (string) $e->getType() === 'transaction');
        $this->assertSame([], array_values(array_filter($transactions, fn (Event $e) => SentryPrivacy::isTrackerPath($e->getTransaction()))));
    }

    public function test_other_routes_keep_their_request_body_and_cache_breadcrumbs(): void
    {
        // The control: the same kind of error elsewhere still carries both, so the
        // test above would see a leak, and the filter is scoped to the tracker.
        Route::post('/api/test-sentry-control', function () {
            Cache::get('control-key');
            throw new RuntimeException('Control error');
        })->middleware('api');
        SentrySdk::getCurrentHub()->configureScope(fn ($scope) => $scope->clearBreadcrumbs());

        $this->postJson('/api/test-sentry-control', ['note' => 'kept'])->assertStatus(500);

        $event = $this->errors()[0];
        $this->assertSame(['note' => 'kept'], $event->getRequest()['data'] ?? null);
        $this->assertArrayHasKey('headers', $event->getRequest());
        $this->assertNotSame([], $this->cacheBreadcrumbs($event));
    }

    public function test_the_tracker_routes_are_never_sampled_for_tracing(): void
    {
        $sample = fn (string $name, ?bool $parent = null) => SentryPrivacy::tracesSampler(SamplingContext::getDefault(
            tap(new TransactionContext($name), fn ($context) => $context->setParentSampled($parent)),
        ));

        foreach (['/api/collect', '/api/collect/123', '/api/collect/{visit}', '/api/events', 'api/collect'] as $tracker) {
            $this->assertSame(0.0, $sample($tracker), $tracker);
            $this->assertSame(0.0, $sample($tracker, true), "{$tracker}, even inside a sampled trace");
        }
        // Everything else as Sentry decides without a sampler: the parent's decision, else the rate.
        config(['sentry.traces_sample_rate' => 0.1]);
        foreach (['/dashboard', '/api/public/site', '/api/collections', '/api/events/x/y', '/analytics'] as $other) {
            $this->assertSame(0.1, $sample($other), $other);
        }
        $this->assertSame(1.0, $sample('/dashboard', true));
        $this->assertSame(0.0, $sample('/dashboard', false));

        // Through the SDK itself, with the options from config/sentry.php.
        $hub = new Hub(ClientBuilder::create([
            'dsn' => 'https://public@sentry.invalid/1',
            'traces_sample_rate' => 1.0,
            'traces_sampler' => config('sentry.traces_sampler'),
            'transport' => self::$transport,
        ])->getClient());
        config(['sentry.traces_sample_rate' => 1.0]);
        $this->assertFalse($hub->startTransaction(new TransactionContext('/api/collect'))->getSampled());
        $this->assertFalse($hub->startTransaction(new TransactionContext('/api/events'))->getSampled());
        $this->assertTrue($hub->startTransaction(new TransactionContext('/dashboard'))->getSampled());
    }

    public function test_the_config_uses_the_filters_and_can_be_cached(): void
    {
        $this->assertSame([SentryPrivacy::class, 'tracesSampler'], config('sentry.traces_sampler'));
        $this->assertSame([SentryPrivacy::class, 'beforeSend'], config('sentry.before_send'));
        // config:cache writes the config with var_export: arrays of strings survive, closures wouldn't.
        $this->assertSame(config('sentry.before_send'), eval('return '.var_export(config('sentry.before_send'), true).';'));
        $this->assertTrue(is_callable(config('sentry.traces_sampler')) && is_callable(config('sentry.before_send')));
    }
}
