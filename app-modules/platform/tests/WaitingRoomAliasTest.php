<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Konventix\Platform\Http\Middleware\EnsureWaitingRoomAdmission;
use Konventix\Platform\WaitingRoom\WaitingRoomGate;

/**
 * The `ensure-waiting-room` alias (ADR-0047 point 5): Platform owns the
 * alias and the `WaitingRoomGate` contract but binds nothing itself —
 * Commerce's provider binds the implementation in the real application.
 * This proves the alias degrades to a harmless pass-through, not a fatal
 * container error, the moment nothing is bound — the scenario a module
 * standing behind the door must never be broken by.
 */
it('passes a request through when no WaitingRoomGate is bound', function (): void {
    app()->offsetUnset(WaitingRoomGate::class);

    expect(app()->bound(WaitingRoomGate::class))->toBeFalse();

    $middleware = new EnsureWaitingRoomAdmission;
    $request = Request::create('/anything');

    $response = $middleware->handle($request, fn (Request $req) => response('ok'));

    expect($response->getContent())->toBe('ok');
});
