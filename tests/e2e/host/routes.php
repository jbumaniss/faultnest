<?php

use FaultNest\Browser\BrowserEventReceiver;
use FaultNest\Contracts\{EventReaderInterface, GroupReaderInterface};
use FaultNest\Domain\CaptureStatus;
use FaultNest\Storage\Database\{EventQuery, GroupQuery};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Host-owned test routes. FaultNest itself registers none.
Route::get('/e2e/page', fn () => response()->file(base_path('e2e-page.html')));
Route::get('/e2e/bundle.js', fn () => response()->file(base_path('e2e-bundle.js'), ['Content-Type' => 'text/javascript']));
Route::get('/e2e/boom', fn () => throw new RuntimeException('http e2e failure 123'));

Route::post('/e2e/errors', function (Request $request, BrowserEventReceiver $receiver) {
    $result = $receiver->accept($request->json()->all());
    return response()->noContent($result->status === CaptureStatus::Failed ? 422 : 202);
});

Route::get('/e2e/state', function () {
    $project = config('faultnest.project');
    $environment = config('faultnest.environment');
    $groups = resolve(GroupReaderInterface::class)->paginate(new GroupQuery($project, $environment, perPage: 100));
    $events = resolve(EventReaderInterface::class)->paginate(new EventQuery($project, $environment, perPage: 100));
    return response()->json([
        'queued' => \Illuminate\Support\Facades\Queue::size(),
        'groups' => array_map(fn ($g) => ['title' => $g->title, 'count' => $g->occurrences, 'level' => $g->maxLevel->value], $groups->items),
        'events' => array_map(fn ($e) => ['source' => $e->source, 'type' => $e->exceptionType, 'message' => $e->message, 'level' => $e->level->value,
            'file' => $e->file, 'line' => $e->line, 'frames' => $e->frames, 'context' => $e->context, 'tags' => $e->tags, 'breadcrumbs' => $e->breadcrumbs], $events->items),
    ]);
});
