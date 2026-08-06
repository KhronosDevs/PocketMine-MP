## Summary

Phase 2 of the optimization plan: Infrastructure Adapters.

### Adapters Implemented

| Adapter | Description |
|---------|-------------|
| **Protocol84NetworkAdapter** | Wires new NetworkPort to legacy `Network.php` and `Player` |
| **AnvilStorageAdapter** | Wraps existing Anvil/LevelDB providers for chunk/entity/tile persistence |
| **ParallelGeneratorAdapter** | Chunk generation/population/light via ThreadingPort |
| **PmmpThreadPool** | Improved with round-robin, parallelMap, removed unused queues |

### Protocol84NetworkAdapter Details

- `sendPacket()` / `broadcastPacket()` → queues outbound packets
- `flushOutboundPackets()` → maps `PlayerRef.entityId` to legacy `Player` via `Server::getOnlinePlayers()`, calls `Player::dataPacket()`
- `disconnect()` → creates `DisconnectPacket` and sends
- `createPlayerRef(Player)` → factory for `PlayerRef` (uniqueId, entityId, name)

### AnvilStorageAdapter Details

- `loadChunkWithContext(Level, chunkX, chunkZ)` → extracts sections, biomes, heightmap, entities, tile entities from `FullChunk`
- `saveChunkWithContext(Level, ChunkData)` → applies sections, biomes, heightmap to `FullChunk`
- `serializeEntity()` / `serializeTileEntity()` → creates `EntitySnapshot` / `TileEntitySnapshot` with ECS components
- `saveAll()` → delegates to `Level::save()` for all levels

### ParallelGeneratorAdapter

- `generateChunk()` → synchronous generation via level's generator, converts `FullChunk` to `ChunkData`
- `populateChunk()` / `calculateLight()` → stubs for parallel execution

### PmmpThreadPool Improvements

- `submitRoundRobin()` for work distribution
- `parallelMap(iterable, callable)` → map-reduce pattern returning keyed results
- Removed unused `$resultQueue` from `WorkerThread`

### Kernel

- Added `wireLegacyDependencies(Kernel)` — connects `Protocol84NetworkAdapter` to legacy `Network` after `Server::getInstance()` is available

### Testing

Run baseline:
```
bin/php7/bin/php measure_baseline.php
```

Static analysis:
```
bin/php7/bin/php vendor/bin/phpstan analyse --configuration=phpstan.neon
```

### Related

Implements Phase 2 of the optimization plan: docs/PLAN.md