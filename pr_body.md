## Summary

Phase 1 of the optimization plan: ECS component expansion and plugin API foundation.

### Components Added

| Component | Purpose |
|-----------|---------|
| **RotationComponent** | Yaw/pitch/headYaw with normalization, forward vector |
| **CollisionComponent** | AABB bounds, intersection testing, configurable sizes |
| **EffectComponent** | Effect instances with amplifier/duration, per-tick decay |
| **AttributeComponent** | Modifiers (add/multiply_base/multiply_total), recalculation |
| **InventoryComponent** | Slot-based, stacking logic, ItemStack with NBT |
| **AIStateComponent** | 6 states (idle/wander/pathfind/attack/flee/interact), targets, path following |
| **PathComponent** | PathNode array with types (walk/jump/swim/climb), costs |

### EntityRef (Plugin API)

Opaque handle providing stable entity identity:
- Read-only component access for plugins
- Convenience methods: teleport(), damage(), heal(), setVelocity(), addVelocity(), getDistanceTo()
- Survives thread migration and region partitioning

### Systems

- **EffectSystem** (PARALLEL): Ticks effect durations per entity
- **AISystem** (SEQUENTIAL): Handles idle/wander/pathfind/attack/flee state machine

### Serialization

- ComponentSerializer: serialize/deserialize components for storage/network snapshots

### Kernel Updates

- All 17 components registered in registerBuiltinComponents()
- EffectSystem (parallel) and AISystem (sequential) registered

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

Implements Phase 1 of the optimization plan: docs/PLAN.md