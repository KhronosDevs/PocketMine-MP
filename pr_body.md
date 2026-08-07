## Summary

Phase 6 of the optimization plan: **Region-Based Architecture** — spatial partitioning with dedicated threads per region.

### Components Implemented

| Component | Description |
|-----------|-------------|
| **RegionWorld** | ECS World slice per spatial region (16×16 chunks = 256×256 blocks) |
| **RegionThread** | Dedicated thread per region with independent ECS tick loop |
| **CoordinationThread** | Global coordinator for entity migration, events, chunk coordination |
| **NetworkThread** | Dedicated RakLib I/O + packet encoding thread |

### RegionWorld

- ECS World slice per spatial region (16×16 chunks = 256×256 blocks)
- Spatial bounds (min/max chunk X/Z)
- `ownsChunk()`, `ownsEntity()` for region ownership checks
- Region ID for identification

### RegionThread

- Dedicated thread per region with independent ECS tick loop (20 TPS)
- Command queue from coordination thread
- Sync queue for network updates
- Migration queue for cross-region entity transfer
- Entity migration out via component snapshot

### CoordinationThread

- Manages all region threads
- Global command queue (player join/leave, entity spawn/despawn, chunk load/unload)
- Global migration queue for cross-region entity migration
- Global event queue for plugin event dispatch
- `findRegionForPosition()` for entity routing

### NetworkThread

- Dedicated network I/O thread
- Outbound queue for packet sending
- Inbound queue for packet receiving
- Dedicated thread for RakLib I/O

### Kernel Updates

- `initializeRegions()` creates region threads
- `run()` starts all threads and runs main coordination loop
- `shutdown()` properly stops all threads
- `processGlobalCoordination()` and `flushNetworkSync()` for main thread coordination

### Decision D009

Region size: 16×16 chunks (256×256 blocks) — balance between parallelism and migration overhead

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

Implements Phase 6 of the optimization plan: docs/PLAN.md