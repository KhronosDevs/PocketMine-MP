<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';
require_once __DIR__ . '/helpers.php';

use pocketmine\protocol\LevelEventPacket;
use pocketmine\core\service\WorldEventService;

// ── Helpers ────────────────────────────────────────────────────────────
/** Fake NetworkSessionService that captures broadcastWorldEvent calls. */
class FakeNetworkSessionForEvents {
    public array $sent = [];
    public function broadcastWorldEvent(int $worldId, int $chunkX, int $chunkZ, $packet): void {
        $this->sent[] = ['worldId' => $worldId, 'chunkX' => $chunkX, 'chunkZ' => $chunkZ, 'packet' => $packet];
    }
}

// ── Tests ──────────────────────────────────────────────────────────────

test('playBlockPlaceSound sends SOUND_BLOCK_PLACE with block id as data', function (): void {
    $fake = new FakeNetworkSessionForEvents();
    $wes = new WorldEventService($fake);

    $wes->playBlockPlaceSound(0, 0, 0, 5.5, 64.0, 7.5, 5 /* planks */);

    same(1, count($fake->sent), 'one packet sent');
    $pk = $fake->sent[0]['packet'];
    ok($pk instanceof LevelEventPacket, 'packet is LevelEventPacket');
    same(WorldEventService::SOUND_BLOCK_PLACE, $pk->evid, 'evid is SOUND_BLOCK_PLACE');
    same(5, $pk->data, 'data carries block id');
    same(5.5, $pk->x, 'x position');
    same(64.0, $pk->y, 'y position');
    same(7.5, $pk->z, 'z position');
});

test('spawnBlockBreakParticle sends PARTICLE_DESTROY with block id + meta', function (): void {
    $fake = new FakeNetworkSessionForEvents();
    $wes = new WorldEventService($fake);

    $wes->spawnBlockBreakParticle(0, 1, 2, 16.5, 65.0, 32.5, 3 /* dirt */, 1);

    same(1, count($fake->sent));
    $pk = $fake->sent[0]['packet'];
    same(WorldEventService::PARTICLE_DESTROY, $pk->evid, 'evid is PARTICLE_DESTROY');
    same(3 + (1 << 12), $pk->data, 'data = blockId + (meta << 12)');
});

test('spawnSmokeParticle sends EVENT_ADD_PARTICLE_MASK | TYPE_SMOKE', function (): void {
    $fake = new FakeNetworkSessionForEvents();
    $wes = new WorldEventService($fake);

    $wes->spawnSmokeParticle(0, 0, 0, 1.0, 2.0, 3.0, 3);

    same(1, count($fake->sent));
    $pk = $fake->sent[0]['packet'];
    same(LevelEventPacket::EVENT_ADD_PARTICLE_MASK | WorldEventService::TYPE_SMOKE, $pk->evid);
    same(3, $pk->data, 'scale passed as data');
});

test('broadcastEvent routes to correct chunk coordinates', function (): void {
    $fake = new FakeNetworkSessionForEvents();
    $wes = new WorldEventService($fake);

    $wes->playSound(0, 5, -3, 85.0, 70.0, -40.0, WorldEventService::SOUND_DOOR);

    same(5, $fake->sent[0]['chunkX']);
    same(-3, $fake->sent[0]['chunkZ']);
    same(0, $fake->sent[0]['worldId']);
});

test('multiple calls accumulate sent packets', function (): void {
    $fake = new FakeNetworkSessionForEvents();
    $wes = new WorldEventService($fake);

    $wes->playClickSound(0, 0, 0, 0, 0, 0);
    $wes->spawnFlameParticle(0, 0, 0, 1, 1, 1);
    $wes->playExplodeSound(0, 0, 0, 2, 2, 2);

    same(3, count($fake->sent));
    same(WorldEventService::SOUND_CLICK, $fake->sent[0]['packet']->evid);
    same(LevelEventPacket::EVENT_ADD_PARTICLE_MASK | WorldEventService::TYPE_FLAME, $fake->sent[1]['packet']->evid);
    same(WorldEventService::SOUND_EXPLODE, $fake->sent[2]['packet']->evid);
});
runTests();
