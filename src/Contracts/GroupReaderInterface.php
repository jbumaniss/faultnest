<?php

namespace FaultNest\Contracts;

use FaultNest\Storage\Database\{GroupQuery, Page};
use FaultNest\Storage\GroupSnapshot;

/** Host-side, read-only group data; caller authorizes before invoking. */
interface GroupReaderInterface
{
    /** @return Page<GroupSnapshot> */
    public function paginate(GroupQuery $query): Page;

    public function find(string $groupId, string $project, string $environment): ?GroupSnapshot;
}
