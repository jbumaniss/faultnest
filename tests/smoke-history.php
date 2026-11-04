<?php

// Dependency-free checks for history options and shared identity (not a DB integration test).
require __DIR__.'/../src/Domain/ErrorLevel.php';
require __DIR__.'/../src/Domain/ErrorEvent.php';
require __DIR__.'/../src/Storage/GroupIdentity.php';
require __DIR__.'/../src/Storage/Database/HistorySettings.php';
require __DIR__.'/../src/Storage/Database/GroupQuery.php';
require __DIR__.'/../src/Storage/Database/EventQuery.php';

use FaultNest\Domain\ErrorEvent;
use FaultNest\Domain\ErrorLevel;
use FaultNest\Storage\Database\{EventQuery, GroupQuery, HistorySettings};
use FaultNest\Storage\GroupIdentity;

$settings = HistorySettings::fromArray(['enabled' => true, 'retention_days' => 7, 'max_samples_per_group_per_day' => 2]);
assert($settings->enabled && $settings->retentionDays === 7 && $settings->maxSamplesPerGroupPerDay === 2);
$event = new ErrorEvent('abc', 'test', 'prod', 'manual', ErrorLevel::Error, null, 'failure', new DateTimeImmutable(), null, null, fingerprint: 'x');
assert(GroupIdentity::id($event) === hash('sha256', GroupIdentity::cacheKey('test', 'prod', 1, 'x')));
assert((new GroupQuery('test', 'prod'))->perPage === 25);
assert((new EventQuery('test', 'prod'))->perPage === 25);
try {
    new GroupQuery('test', 'prod', page: 0);
    throw new RuntimeException('Invalid query was accepted');
} catch (InvalidArgumentException) {
    // Expected.
}
try {
    HistorySettings::fromArray(['max_samples_per_group_per_day' => '3']);
    throw new RuntimeException('Invalid history settings were accepted');
} catch (InvalidArgumentException) {
    // Expected.
}
echo "history config + identity smoke passed\n";
