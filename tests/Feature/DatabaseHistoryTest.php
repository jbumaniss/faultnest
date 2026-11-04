<?php

namespace FaultNest\Tests\Feature;

use DateTimeImmutable;
use FaultNest\Contracts\{EventReaderInterface, GroupReaderInterface};
use FaultNest\Domain\{CaptureStatus, ErrorEvent, ErrorLevel};
use FaultNest\Laravel\{FaultNestManager, FaultNestServiceProvider};
use FaultNest\Storage\Database\{DatabaseEventStore, DatabaseHistoryPruner, EventQuery, GroupQuery, HistorySettings};
use FaultNest\Storage\GroupIdentity;
use Orchestra\Testbench\TestCase;

class DatabaseHistoryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [FaultNestServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('database.default', 'sqlite');
        $this->app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $this->app['db']->purge('sqlite');
        $this->app['config']->set('faultnest.enabled', true);
        $this->app['config']->set('faultnest.project', 'project-one');
        $this->app['config']->set('faultnest.environment', 'testing');
        $this->app['config']->set('faultnest.storage.enabled', false);
        $this->app['config']->set('faultnest.storage.database.enabled', true);
        $this->app['config']->set('faultnest.storage.database.connection', 'sqlite');
        $this->app['config']->set('faultnest.storage.database.max_samples_per_group_per_day', 2);

        $migration = require __DIR__.'/../../database/migrations/2026_11_04_000001_create_faultnest_history_tables.php';
        $migration->up();
    }

    public function test_history_is_opt_in_and_round_trips_only_explicit_extra_data(): void
    {
        $manager = $this->app->make(FaultNestManager::class);
        $manager->init();
        $manager->setTag('feature', 'import');
        $captured = [];
        for ($i = 0; $i < 5; $i++) {
            $result = $manager->captureMessage('repeat failure', ErrorLevel::Error, [
                'caller' => ['id' => $i],
                'password' => 'do-not-persist',
            ]);
            self::assertSame(CaptureStatus::Captured, $result->status);
            $captured[] = $result->event;
        }

        $groups = $this->app->make(GroupReaderInterface::class)->paginate(
            new GroupQuery('project-one', 'testing'),
        );
        self::assertSame(1, $groups->total);
        self::assertSame(5, $groups->items[0]->occurrences);
        self::assertSame(2, $this->app->make(EventReaderInterface::class)->paginate(
            new EventQuery('project-one', 'testing'),
        )->total);

        $reader = $this->app->make(EventReaderInterface::class);
        $sample = $reader->find($captured[0]->id, 'project-one', 'testing');
        self::assertNotNull($sample);
        self::assertSame(['caller' => ['id' => 0], 'password' => '[REDACTED]'], $sample->context);
        self::assertSame(['feature' => 'import'], $sample->tags);
        self::assertSame([], $sample->breadcrumbs);
        self::assertNull($reader->find($captured[4]->id, 'project-one', 'testing'));
        self::assertNull($reader->find($sample->id, 'other-project', 'testing'));
        self::assertNull($this->app->make(GroupReaderInterface::class)->find(
            $groups->items[0]->id, 'project-one', 'production',
        ));
    }

    public function test_filtering_and_pagination_never_cross_project_environment_boundary(): void
    {
        $manager = $this->app->make(FaultNestManager::class);
        $manager->init();
        $manager->captureMessage('one');
        $manager->captureMessage('two');

        $groupReader = $this->app->make(GroupReaderInterface::class);
        $firstPage = $groupReader->paginate(new GroupQuery('project-one', 'testing', page: 1, perPage: 1));
        self::assertSame(2, $firstPage->total);
        self::assertCount(1, $firstPage->items);
        self::assertCount(1, $groupReader->paginate(new GroupQuery('project-one', 'testing', page: 2, perPage: 1))->items);
        self::assertSame(0, $groupReader->paginate(new GroupQuery('project-one', 'other'))->total);
        self::assertSame(0, $this->app->make(EventReaderInterface::class)
            ->paginate(new EventQuery('other-project', 'testing'))->total);
    }

    public function test_explicit_pruning_removes_only_expired_records(): void
    {
        $connection = $this->app['db']->connection('sqlite');
        $settings = new HistorySettings(true, 'sqlite', 30, 2);
        $store = new DatabaseEventStore($connection, $settings);
        $store->record($this->event('old', '2026-09-01T00:00:00+00:00'));
        $store->record($this->event('recent', '2026-11-03T00:00:00+00:00'));

        $pruned = (new DatabaseHistoryPruner($connection, $settings))
            ->prune(10, 100, new DateTimeImmutable('2026-11-04T00:00:00+00:00'));
        self::assertSame(1, $pruned->events);
        self::assertSame(1, $pruned->groups);
        self::assertSame(1, $connection->table('faultnest_groups')->count());
        self::assertSame(1, $connection->table('faultnest_event_samples')->count());
    }

    public function test_zero_sample_quota_retains_group_only(): void
    {
        $connection = $this->app['db']->connection('sqlite');
        $store = new DatabaseEventStore($connection, new HistorySettings(true, 'sqlite', 30, 0));
        $store->record($this->event('none', '2026-11-03T00:00:00+00:00'));

        self::assertSame(1, $connection->table('faultnest_groups')->count());
        self::assertSame(0, $connection->table('faultnest_event_samples')->count());
    }

    public function test_group_identity_matches_cache_and_out_of_order_time_and_max_level_are_stable(): void
    {
        $connection = $this->app['db']->connection('sqlite');
        $store = new DatabaseEventStore($connection, new HistorySettings(true, 'sqlite', 30, 2));
        $newer = $this->event('first', '2026-11-03T15:00:00+00:00', ErrorLevel::Critical, 'same-group');
        $older = $this->event('second', '2026-11-03T08:00:00+00:00', ErrorLevel::Warning, 'same-group');
        $store->record($newer);
        $store->record($older);
        $group = $this->app['db']->connection('sqlite')->table('faultnest_groups')->first();
        self::assertSame(GroupIdentity::id($newer), $group->id);
        self::assertSame(2, (int) $group->occurrence_count);
        self::assertSame('critical', $group->max_level);
        self::assertStringStartsWith('2026-11-03 08:00:00', $group->first_seen_at);
        self::assertStringStartsWith('2026-11-03 15:00:00', $group->last_seen_at);
    }

    protected function event(string $id, string $when, ErrorLevel $level = ErrorLevel::Error, ?string $fingerprint = null): ErrorEvent
    {
        return new ErrorEvent(
            str_pad(bin2hex($id), 32, '0'), 'project-one', 'testing', 'manual',
            $level, null, 'failure '.$id, new DateTimeImmutable($when), null, null,
            context: ['client' => ['value' => 123]], fingerprint: $fingerprint ?? $id,
        );
    }
}
