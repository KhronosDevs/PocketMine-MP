## Summary

Phase 3 of the optimization plan: Core Gameplay Services.

### Services Implemented

| Service | Description |
|---------|-------------|
| **PlayerJoinService** | Create/load player entity, send join packets, load player data from storage |
| **PlayerLeaveService** | Save player data, broadcast leave, despawn entity |
| **PlayerRespawnService** | Reset health/velocity/inventory, teleport to spawn, clear effects |

| **ChunkLoadService** | Load/generate/populate chunks, calculate light |
| **ChunkUnloadService** | Save entities, unload unused chunks |
| **ChunkSendService** | Send chunks to player based on view distance |

| **BlockBreakService** | Break blocks with reach check, tool speed, drops |
| **BlockPlaceService** | Place blocks with reach/inventory check |
| **BlockUpdateService** | Schedule and process block updates |

| **EntitySpawnService** | Spawn mobs, items, projectiles with type initialization |
| **EntityDespawnService** | Despawn with save, distance-based cleanup |
| **EntityInteractionService** | Interact, attack, pickup items, trade |

| **CombatService** | Damage with armor reduction, knockback, death handling |
| **DamageService** | Apply damage/healing, health management |
| **KnockbackService** | Horizontal/vertical/explosion/directional knockback |

| **InventoryService** | Add/remove/swap items, held slot management |
| **CraftingService** | Recipe matching, ingredient consumption |
| **ContainerService** | Open/close, slot operations, item transfers |

### Architecture

All services use:
- **ECS components**: EntityRef, PositionComponent, HealthComponent, InventoryComponent, MetadataComponent, VelocityComponent, etc.
- **Ports**: NetworkPort, StoragePort, WorldGenPort
- **World/EntityRef** for entity management
- **ComponentSerializer** for storage serialization

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

Implements Phase 3 of the optimization plan: docs/PLAN.md