<?php

namespace FaultNest\Tests\Integration;

use DateTimeImmutable;
use FaultNest\Contracts\{EventReaderInterface, GroupReaderInterface};
use FaultNest\Domain\{CaptureStatus, ErrorEvent, ErrorLevel};
use FaultNest\Laravel\{FaultNestManager, FaultNestServiceProvider};
use FaultNest\Storage\Database\{DatabaseEventStore, DatabaseHistoryPruner, EventQuery, GroupQuery, HistorySettings};
use Illuminate\Support\Facades\{Artisan, DB, Schema};
use Orchestra\Testbench\TestCase;
use RuntimeException;

/** Runs against the throwaway database named by IT_DB_DRIVER; the schema is dropped around every test. */
class DatabaseBackendTest extends TestCase
{
    use IntegrationSupport;

    protected function getPackageProviders($app): array
    {
        return [FaultNestServiceProvider::class];
    }

    /** @return array<string, mixed> */
    protected function connectionConfig(): array
    {
        $driver = getenv('IT_DB_DRIVER') ?: 'sqlite';
        if ($driver === 'sqlite') {
            return ['driver' => 'sqlite', 'database' => getenv('IT_SQLITE_PATH'), 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 15000, 'journal_mode' => 'WAL'];
        }
        return [
            'driver' => $driver, 'host' => getenv('IT_DB_HOST'), 'port' => (int) getenv('IT_DB_PORT'),
            'database' => getenv('IT_DB_NAME'), 'username' => getenv('IT_DB_USER'), 'password' => getenv('IT_DB_PASS'),
            'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8', 'collation' => $driver === 'mysql' ? 'utf8mb4_unicode_ci' : null,
            'prefix' => '', 'strict' => true, 'search_path' => 'public', 'sslmode' => 'disable',
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Hard guard: only hosts on the dedicated review network or a scratch file.
        $config = $this->connectionConfig();
        if ($config['driver'] !== 'sqlite' && !str_starts_with((string) $config['host'], 'fnreview-')) {
            throw new RuntimeException('Refusing to run destructive tests on a non-review database host.');
        }
        $app['config']->set('database.default', 'it');
        $app['config']->set('database.connections.it', $config);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('faultnest.enabled', true);
        $app['config']->set('faultnest.project', 'it-project');
        $app['config']->set('faultnest.environment', 'it-env');
        $app['config']->set('faultnest.storage.enabled', false);
        $app['config']->set('faultnest.storage.database.enabled', true);
        $app['config']->set('faultnest.storage.database.connection', 'it');
        $app['config']->set('faultnest.storage.database.max_samples_per_group_per_day', 3);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::connection('it')->dropIfExists('faultnest_event_samples');
        Schema::connection('it')->dropIfExists('faultnest_groups');
        Schema::connection('it')->dropIfExists('migrations');
        Artisan::call('migrate', ['--database' => 'it', '--force' => true]);
    }

    protected function event(string $fingerprint = 'fp', string $when = 'now', ErrorLevel $level = ErrorLevel::Error, string $project = 'it-project', string $environment = 'it-env', array $context = []): ErrorEvent
    {
        return new ErrorEvent(bin2hex(random_bytes(16)), $project, $environment, 'manual', $level, 'App\\Failure',
            'failure '.$fingerprint, new DateTimeImmutable($when), 'app/Failure.php', 12,
            [['file' => 'app/Failure.php', 'line' => 12, 'call' => 'App\\Failure->run']], $context, ['t' => 'v'],
            [['message' => 'm', 'data' => ['n' => 1]]], fingerprint: $fingerprint);
    }

    protected function store(int $samples = 3): DatabaseEventStore
    {
        return new DatabaseEventStore(DB::connection('it'), new HistorySettings(true, 'it', 30, $samples));
    }

    public function test_migration_creates_rolls_back_and_recreates_host_tables(): void
    {
        self::assertTrue(Schema::connection('it')->hasTable('faultnest_groups'));
        self::assertTrue(Schema::connection('it')->hasTable('faultnest_event_samples'));
        Artisan::call('migrate:rollback', ['--database' => 'it', '--force' => true]);
        self::assertFalse(Schema::connection('it')->hasTable('faultnest_groups'));
        Artisan::call('migrate', ['--database' => 'it', '--force' => true]);
        self::assertTrue(Schema::connection('it')->hasTable('faultnest_event_samples'));
    }

    public function test_manager_pipeline_stores_counts_and_reader_returns_context_round_trip(): void
    {
        $manager = $this->app->make(FaultNestManager::class);
        $manager->init();
        $context = ['unicode' => 'Jūris 你好 🎉', 'nested' => ['a' => [1, 2.5, true, null]], 'empty' => [], 'password' => 'hide'];
        $result = $manager->captureException(new RuntimeException('Order 1 failed'), $context);
        self::assertSame(CaptureStatus::Captured, $result->status);
        for ($i = 2; $i <= 6; $i++) {
            $manager->captureException(new RuntimeException("Order {$i} failed"));
        }

        $groups = $this->app->make(GroupReaderInterface::class)->paginate(new GroupQuery('it-project', 'it-env'));
        self::assertSame(1, $groups->total);
        self::assertGreaterThanOrEqual(1, $groups->items[0]->occurrences);
        $events = $this->app->make(EventReaderInterface::class);
        $stored = $events->find($result->event->id, 'it-project', 'it-env');
        self::assertNotNull($stored);
        self::assertEqualsCanonicalizing(['unicode', 'nested', 'empty', 'password'], array_keys($stored->context));
        self::assertSame('Jūris 你好 🎉', $stored->context['unicode']);
        self::assertSame([1, 2.5, true, null], $stored->context['nested']['a']);
        self::assertSame([], $stored->context['empty']);
        self::assertSame('[REDACTED]', $stored->context['password']);
        self::assertSame($result->event->occurredAt->format('Y-m-d H:i:s'), $stored->occurredAt->format('Y-m-d H:i:s'));
    }

    public function test_anonymous_exception_class_is_storable_on_this_driver(): void
    {
        $manager = $this->app->make(FaultNestManager::class);
        $result = $manager->captureException(new class('anon') extends RuntimeException {});
        self::assertSame(CaptureStatus::Captured, $result->status);
        self::assertSame(1, DB::connection('it')->table('faultnest_event_samples')->count(), 'Anonymous exception was not stored.');
    }

    public function test_multibyte_message_truncation_is_storable_on_this_driver(): void
    {
        $manager = $this->app->make(FaultNestManager::class);
        $result = $manager->captureMessage(str_repeat('ā', 600));
        self::assertSame(CaptureStatus::Captured, $result->status);
        self::assertSame(1, DB::connection('it')->table('faultnest_event_samples')->count());
    }

    public function test_group_counters_max_level_and_time_bounds(): void
    {
        $this->app->make(FaultNestManager::class)->init();
        $store = $this->store();
        $store->record($this->event('g', '2026-11-03T15:00:00.654321+00:00', ErrorLevel::Warning));
        $store->record($this->event('g', '2026-11-03T08:00:00.000001+00:00', ErrorLevel::Critical));
        $store->record($this->event('g', '2026-11-03T16:00:00.100000+00:00', ErrorLevel::Error));
        $group = $this->app->make(GroupReaderInterface::class)->paginate(new GroupQuery('it-project', 'it-env'))->items[0];
        self::assertSame(3, $group->occurrences);
        self::assertSame(ErrorLevel::Critical, $group->maxLevel);
        self::assertSame('2026-11-03 08:00:00', $group->firstSeenAt->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-03 16:00:00', $group->lastSeenAt->format('Y-m-d H:i:s'));
    }

    public function test_daily_sample_quota_is_per_group_per_utc_day(): void
    {
        $store = $this->store(2);
        foreach (['2026-11-01T01:00:00+00:00', '2026-11-01T02:00:00+00:00', '2026-11-01T03:00:00+00:00', '2026-11-02T01:00:00+00:00'] as $when) {
            $store->record($this->event('quota', $when));
        }
        $store->record($this->event('other', '2026-11-01T01:00:00+00:00'));
        self::assertSame(4, DB::connection('it')->table('faultnest_event_samples')->count());
    }

    public function test_reader_pagination_filtering_and_scope_isolation(): void
    {
        $this->app->make(FaultNestManager::class)->init();
        $store = $this->store();
        foreach (range(1, 5) as $i) {
            $store->record($this->event('group-'.$i, "2026-11-0{$i}T00:00:00+00:00", $i === 5 ? ErrorLevel::Critical : ErrorLevel::Error));
        }
        $store->record($this->event('foreign', 'now', project: 'other'));
        $store->record($this->event('foreign', 'now', environment: 'other'));

        $reader = $this->app->make(GroupReaderInterface::class);
        $first = $reader->paginate(new GroupQuery('it-project', 'it-env', perPage: 2));
        self::assertSame(5, $first->total);
        self::assertCount(2, $first->items);
        self::assertSame('group-5', $first->items[0]->fingerprint, 'Newest group must come first.');
        $third = $reader->paginate(new GroupQuery('it-project', 'it-env', page: 3, perPage: 2));
        self::assertCount(1, $third->items);
        self::assertSame(1, $reader->paginate(new GroupQuery('it-project', 'it-env', level: ErrorLevel::Critical))->total);
        self::assertSame(5, $reader->paginate(new GroupQuery('it-project', 'it-env', category: 'application'))->total);
        self::assertSame(0, $reader->paginate(new GroupQuery('it-project', 'it-env', category: 'nothing'))->total);
        self::assertSame(1, $reader->paginate(new GroupQuery('other', 'it-env'))->total);

        $groupId = $first->items[0]->id;
        $events = $this->app->make(EventReaderInterface::class);
        self::assertSame(1, $events->paginate(new EventQuery('it-project', 'it-env', groupId: $groupId))->total);
        self::assertSame(0, $events->paginate(new EventQuery('other', 'it-env', groupId: $groupId))->total);
        self::assertSame(1, $events->paginate(new EventQuery('it-project', 'it-env', level: ErrorLevel::Critical))->total);
        self::assertNull($reader->find($groupId, 'other', 'it-env'));
        $sample = $events->paginate(new EventQuery('it-project', 'it-env', groupId: $groupId))->items[0];
        self::assertSame(12, $sample->line);
        self::assertSame(['t' => 'v'], $sample->tags);
        self::assertEquals([['message' => 'm', 'data' => ['n' => 1]]], $sample->breadcrumbs, 'MySQL JSON does not preserve key order.');
        self::assertSame('App\\Failure->run', $sample->frames[0]['call']);
    }

    public function test_pruning_is_bounded_and_touches_only_expired_rows_and_cascades(): void
    {
        $store = $this->store(100);
        foreach (range(1, 7) as $i) {
            $store->record($this->event('old-'.$i, '2026-01-01T00:00:00+00:00'));
        }
        // Group stays alive, its old sample expires.
        $store->record($this->event('mixed', '2026-01-01T00:00:00+00:00'));
        $store->record($this->event('mixed', '2026-11-03T00:00:00+00:00'));
        $store->record($this->event('new', '2026-11-03T00:00:00+00:00'));

        $pruner = new DatabaseHistoryPruner(DB::connection('it'), new HistorySettings(true, 'it', 30, 100));
        $now = new DateTimeImmutable('2026-11-04T00:00:00+00:00');
        $bounded = $pruner->prune(1, 3, $now);
        self::assertSame(3, $bounded->events, 'One batch of three.');
        self::assertSame(3, $bounded->groups, 'One batch of three.');
        $rest = $pruner->prune(10, 500, $now);
        self::assertSame(4, $rest->groups);
        self::assertSame(2, DB::connection('it')->table('faultnest_groups')->count());
        self::assertSame(2, DB::connection('it')->table('faultnest_event_samples')->count());
        self::assertSame(0, $pruner->prune(10, 500, $now)->events);
    }

    public function test_deleting_a_group_cascades_to_samples(): void
    {
        $this->store()->record($this->event('cascade'));
        DB::connection('it')->table('faultnest_groups')->delete();
        self::assertSame(0, DB::connection('it')->table('faultnest_event_samples')->count());
    }

    public function test_prune_command_runs_after_init_and_validates_input(): void
    {
        $this->app->make(FaultNestManager::class)->init();
        self::assertSame(0, Artisan::call('faultnest:prune', ['--max-batches' => 1]));
        self::assertSame(1, Artisan::call('faultnest:prune', ['--max-batches' => 0]));
    }

    public function test_concurrent_writers_keep_exact_counters_and_daily_quota(): void
    {
        $result = $this->runWorkers('database', 8, 40, 'race', [
            'IT_DB_CONFIG' => json_encode($this->connectionConfig()), 'IT_SAMPLES' => '5',
        ]);
        self::assertSame(0, $result['failed'], 'Lost writes: '.json_encode($result['errors']));
        $group = DB::connection('it')->table('faultnest_groups')->where('fingerprint', 'race')->first();
        self::assertSame(320, (int) $group->occurrence_count);
        self::assertSame(1, DB::connection('it')->table('faultnest_groups')->count());
        self::assertLessThanOrEqual(10, DB::connection('it')->table('faultnest_event_samples')->count());
        // At most two UTC days can occur if the run crosses midnight.
        self::assertGreaterThanOrEqual(5, DB::connection('it')->table('faultnest_event_samples')->count());
    }
}
