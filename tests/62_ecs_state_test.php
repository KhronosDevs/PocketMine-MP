<?php
declare(strict_types=1);
require __DIR__ . '/../autoload.php';

use pocketmine\core\ecs\ComponentRegistry;
use pocketmine\core\ecs\Entity;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\QueryBuilder;
use pocketmine\core\ecs\ResourceRegistry;
use pocketmine\core\ecs\SystemScheduler;
use pocketmine\core\ecs\World;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\adapter\driven\threading\FutureImpl;
use pocketmine\port\driven\Future;
use pocketmine\port\driven\ThreadingPort;

require __DIR__ . '/helpers.php';

/**
 * ECS process-wide state regression tests (2026-09 memory round):
 *
 * 1. EntityRef eviction — EntityRef::$idToRef is process-wide and ids are
 *    monotonic; without eviction every despawned entity (items, arrows,
 *    TNT, mobs) leaked its ref + Entity + World forever.
 * 2. Query cache world scoping — the cache used to be ONE static array
 *    keyed only by query shape, so two worlds in one process served each
 *    other's cached entity lists on shape collision.
 */

/** Minimal port: these tests register no systems, so nothing is dispatched. */
final class NoopThreadingPort implements ThreadingPort {
	public function submit(callable $task): Future {
		$task();
		$f = new FutureImpl();
		$f->resolve(true);
		return $f;
	}

	public function submitToWorker(int $workerId, callable $task): Future {
		return $this->submit($task);
	}

	public function parallelFor(iterable $items, callable $body): void {
		foreach ($items as $item) {
			$body($item);
		}
	}

	public function awaitAll(iterable $futures): void {
	}

	public function submitPluginTask(\pmmp\thread\Runnable $task): \pocketmine\adapter\driven\threading\PluginFuture {
		throw new \LogicException('no worker pool in this test');
	}

	public function submitPluginTaskToWorker(int $workerId, \pmmp\thread\Runnable $task): \pocketmine\adapter\driven\threading\PluginFuture {
		throw new \LogicException('no worker pool in this test');
	}

	public function submitTaskToWorker(int $workerId, \pmmp\thread\Runnable $task): void {
		throw new \LogicException('no worker pool in this test');
	}

	public function shutdown(): void {
	}
}

function makeWorld(): World {
	$reg = new ComponentRegistry();
	$res = new ResourceRegistry();
	$reg->register(PositionComponent::class);
	$reg->register(VelocityComponent::class);
	return new World($reg, $res, new SystemScheduler(new NoopThreadingPort()));
}

test('despawn evicts the EntityRef from the process-wide map', function (): void {
	$world = makeWorld();
	$ref = $world->spawn((new EntityBuilder())->at(1.0, 64.0, 1.0)->with(new VelocityComponent(0.0, 0.0, 0.0)));
	$id = $ref->getId();
	ok(EntityRef::get($id) !== null, 'live entity resolves through the map');

	$world->despawn($world->getEntity($id));
	$world->tick(0.05); // flushes the removal queue

	same(null, EntityRef::get($id), 'despawned entity must not stay in the map');
});

test('despawning many entities leaks zero refs', function (): void {
	$world = makeWorld();
	$ids = [];
	for ($i = 0; $i < 50; ++$i) {
		$ref = $world->spawn((new EntityBuilder())->at((float)$i, 64.0, 0.0));
		$ids[] = $ref->getId();
	}
	foreach ($ids as $id) {
		$world->despawn($world->getEntity($id));
	}
	$world->tick(0.05);
	foreach ($ids as $id) {
		ok(EntityRef::get($id) === null, "ref $id evicted after despawn");
	}
	// The world itself is empty and consistent afterwards.
	same(0, count($world->getEntities()));
});

test('query cache is world-scoped: identical shape serves different entity sets', function (): void {
	$w1 = makeWorld();
	$w2 = makeWorld();

	$w1->spawn((new EntityBuilder())->at(1.0, 64.0, 1.0));
	// w2 has no entities. Same query shape in both worlds.

	// First builds populate each world's cache.
	same(1, $w1->query()->with(PositionComponent::class)->build()->count());
	// The discriminator: with the old shared static cache this returned
	// w1's cached entity list (count 1) for world 2.
	same(0, $w2->query()->with(PositionComponent::class)->build()->count(), 'world 2 must not see world 1 cached results');

	// Cached-path re-queries keep serving the per-world snapshots.
	same(1, $w1->query()->with(PositionComponent::class)->build()->count());
	same(0, $w2->query()->with(PositionComponent::class)->build()->count());
});

test('spawn/despawn invalidates the owning world cache only', function (): void {
	$w1 = makeWorld();
	$w2 = makeWorld();

	$e1 = $w1->spawn((new EntityBuilder())->at(0.0, 64.0, 0.0));
	$w2->spawn((new EntityBuilder())->at(0.0, 64.0, 0.0));
	same(1, $w1->query()->with(PositionComponent::class)->build()->count());
	same(1, $w2->query()->with(PositionComponent::class)->build()->count());

	// Mutate w1: its cached query must refresh; w2's stays as-is.
	$w1->spawn((new EntityBuilder())->at(5.0, 64.0, 5.0));
	same(2, $w1->query()->with(PositionComponent::class)->build()->count(), 'w1 cache invalidated on spawn');
	same(1, $w2->query()->with(PositionComponent::class)->build()->count(), 'w1 churn must not thrash w2 cache');

	// Entity ids come from the process-global monotonic counter — never
	// assume a literal id here.
	$w1->despawn($w1->getEntity($e1->getId()));
	$w1->tick(0.05);
	same(1, $w1->query()->with(PositionComponent::class)->build()->count(), 'w1 cache invalidated on despawn');
});

test('clearCache() with no argument still clears every world (legacy call sites)', function (): void {
	$w1 = makeWorld();
	$w2 = makeWorld();
	$w1->spawn((new EntityBuilder())->at(0.0, 64.0, 0.0));
	$w2->spawn((new EntityBuilder())->at(0.0, 64.0, 0.0));
	same(1, $w1->query()->with(PositionComponent::class)->build()->count());
	same(1, $w2->query()->with(PositionComponent::class)->build()->count());

	QueryBuilder::clearCache();

	// After clearing, a rebuild must observe the live entity sets (still
	// 1 each — the assertion is that rebuild succeeds and stays correct;
	// tests/04 exercises the invalidation-on-mutation path with this call).
	same(1, $w1->query()->with(PositionComponent::class)->build()->count());
	same(1, $w2->query()->with(PositionComponent::class)->build()->count());
});

echo runTests() === 0 ? "\nDONE\n" : "\nFAILED\n";
exit(runTests());
