<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

use pocketmine\core\ecs\Resource;

/**
 * Static item property registry (protocol 84 era item IDs).
 *
 * Plain data: max stack sizes and display names for the items that differ
 * from the 64 default. Systems and API facades read from here instead of
 * hard-coding item behavior.
 */
#[Resource]
final class ItemRegistry {
    /** Items that do not stack to 64. */
    private const MAX_STACK = [
        // tools / weapons / armor / equipment
        256 => 1, 257 => 1, 258 => 1, 259 => 1, 261 => 1, 267 => 1, 268 => 1, 269 => 1,
        270 => 1, 271 => 1, 272 => 1, 273 => 1, 274 => 1, 275 => 1, 276 => 1, 277 => 1,
        278 => 1, 279 => 1, 283 => 1, 284 => 1, 285 => 1, 286 => 1, 290 => 1, 291 => 1,
        292 => 1, 293 => 1, 294 => 1, 298 => 1, 299 => 1, 300 => 1, 301 => 1, 302 => 1,
        303 => 1, 304 => 1, 305 => 1, 306 => 1, 307 => 1, 308 => 1, 309 => 1, 310 => 1,
        311 => 1, 312 => 1, 313 => 1, 314 => 1, 315 => 1, 316 => 1, 317 => 1, 346 => 1,
        359 => 1, 424 => 1,
        // single-use / special items
        325 => 16, // bucket
        323 => 16, // sign
        328 => 1,  // minecart
        329 => 1,  // saddle
        332 => 16, // snowball
        333 => 1,  // boat
        335 => 1,  // milk bucket
        344 => 16, // egg
        373 => 1,  // potion
        407 => 16, // ender pearl
        438 => 1,  // splash potion
        442 => 1,  // shield
    ];

    private const NAMES = [
        // block-as-item names (block IDs 1-255)
        1 => 'Stone', 2 => 'Grass', 3 => 'Dirt', 4 => 'Cobblestone', 5 => 'Planks', 7 => 'Bedrock',
        12 => 'Sand', 13 => 'Gravel', 14 => 'Gold Ore', 15 => 'Iron Ore', 16 => 'Coal Ore',
        17 => 'Log', 18 => 'Leaves', 20 => 'Glass', 21 => 'Lapis Lazuli Ore', 24 => 'Sandstone',
        30 => 'Cobweb', 31 => 'Tall Grass', 32 => 'Dead Bush', 35 => 'Wool', 37 => 'Dandelion',
        38 => 'Poppy', 41 => 'Gold Block', 42 => 'Iron Block', 44 => 'Stone Slab', 45 => 'Bricks',
        46 => 'TNT', 47 => 'Bookshelf', 48 => 'Mossy Cobblestone', 49 => 'Obsidian', 50 => 'Torch',
        53 => 'Oak Wood Stairs', 54 => 'Chest', 56 => 'Diamond Ore', 57 => 'Diamond Block',
        58 => 'Crafting Table', 61 => 'Furnace', 65 => 'Ladder', 66 => 'Rail', 67 => 'Cobblestone Stairs',
        79 => 'Ice', 80 => 'Snow Block', 81 => 'Cactus', 82 => 'Clay Block', 85 => 'Fence',
        86 => 'Pumpkin', 87 => 'Netherrack', 88 => 'Soul Sand', 89 => 'Glowstone', 98 => 'Stone Bricks',
        103 => 'Melon', 110 => 'Mycelium', 112 => 'Nether Brick',
        256 => 'Iron Shovel', 257 => 'Iron Pickaxe', 258 => 'Iron Axe', 259 => 'Flint and Steel',
        260 => 'Apple', 261 => 'Bow', 262 => 'Arrow', 263 => 'Coal', 264 => 'Diamond',
        265 => 'Iron Ingot', 266 => 'Gold Ingot', 267 => 'Iron Sword', 268 => 'Wooden Sword',
        269 => 'Wooden Shovel', 270 => 'Wooden Pickaxe', 271 => 'Wooden Axe', 272 => 'Stone Sword',
        273 => 'Stone Shovel', 274 => 'Stone Pickaxe', 275 => 'Stone Axe', 276 => 'Diamond Sword',
        277 => 'Diamond Shovel', 278 => 'Diamond Pickaxe', 279 => 'Diamond Axe', 280 => 'Stick',
        281 => 'Bowl', 282 => 'Mushroom Stew', 283 => 'Golden Sword', 284 => 'Golden Shovel',
        285 => 'Golden Pickaxe', 286 => 'Golden Axe', 287 => 'String', 288 => 'Feather',
        289 => 'Gunpowder', 290 => 'Wooden Hoe', 291 => 'Stone Hoe', 292 => 'Iron Hoe',
        293 => 'Diamond Hoe', 294 => 'Golden Hoe', 295 => 'Seeds', 296 => 'Wheat',
        297 => 'Bread', 298 => 'Leather Cap', 299 => 'Leather Tunic', 300 => 'Leather Pants',
        301 => 'Leather Boots', 302 => 'Chain Helmet', 303 => 'Chain Chestplate', 304 => 'Chain Leggings',
        305 => 'Chain Boots', 306 => 'Iron Helmet', 307 => 'Iron Chestplate', 308 => 'Iron Leggings',
        309 => 'Iron Boots', 310 => 'Diamond Helmet', 311 => 'Diamond Chestplate', 312 => 'Diamond Leggings',
        313 => 'Diamond Boots', 314 => 'Golden Helmet', 315 => 'Golden Chestplate', 316 => 'Golden Leggings',
        317 => 'Golden Boots', 318 => 'Flint', 319 => 'Raw Porkchop', 320 => 'Cooked Porkchop',
        321 => 'Painting', 322 => 'Golden Apple', 323 => 'Sign', 324 => 'Wooden Door',
        325 => 'Bucket', 326 => 'Water Bucket', 327 => 'Lava Bucket', 328 => 'Minecart',
        329 => 'Saddle', 330 => 'Iron Door', 331 => 'Redstone', 332 => 'Snowball',
        333 => 'Boat', 334 => 'Leather', 335 => 'Milk Bucket', 336 => 'Brick',
        337 => 'Clay', 338 => 'Sugar Cane', 339 => 'Paper', 340 => 'Book', 341 => 'Slimeball',
        342 => 'Chest Minecart', 343 => 'Furnace Minecart', 344 => 'Egg', 345 => 'Compass',
        346 => 'Fishing Rod', 347 => 'Clock', 348 => 'Glowstone Dust', 349 => 'Raw Fish',
        350 => 'Cooked Fish', 351 => 'Dye', 352 => 'Bone', 353 => 'Sugar', 354 => 'Cake',
        355 => 'Bed', 356 => 'Redstone Repeater', 357 => 'Cookie', 358 => 'Map', 359 => 'Shears',
        360 => 'Melon Slice', 361 => 'Pumpkin Seeds', 362 => 'Melon Seeds', 363 => 'Raw Beef',
        364 => 'Steak', 365 => 'Raw Chicken', 366 => 'Cooked Chicken', 367 => 'Rotten Flesh',
        368 => 'Ender Pearl', 369 => 'Blaze Rod', 370 => 'Ghast Tear', 371 => 'Gold Nugget',
        372 => 'Nether Wart', 373 => 'Potion', 374 => 'Glass Bottle', 375 => 'Spider Eye',
        376 => 'Fermented Spider Eye', 377 => 'Blaze Powder', 378 => 'Magma Cream', 379 => 'Brewing Stand',
        380 => 'Cauldron', 381 => 'Eye of Ender', 382 => 'Glistering Melon', 383 => 'Spawn Egg',
        384 => 'Bottle o Enchanting', 388 => 'Emerald', 389 => 'Item Frame', 390 => 'Flower Pot',
        391 => 'Carrot', 392 => 'Potato', 393 => 'Baked Potato', 394 => 'Poisonous Potato',
        395 => 'Empty Map', 396 => 'Golden Carrot', 397 => 'Skull', 398 => 'Carrot on a Stick',
        399 => 'Nether Star', 406 => 'End Crystal', 407 => 'Ender Pearl', 408 => 'Rabbit Stew',
        409 => 'Rabbit Foot', 410 => 'Rabbit Hide', 411 => 'Leather Horse Armor', 412 => 'Iron Horse Armor',
        413 => 'Golden Horse Armor', 414 => 'Diamond Horse Armor', 415 => 'Lead', 416 => 'Name Tag',
        417 => 'Command Block Minecart', 418 => 'Mutton', 419 => 'Cooked Mutton', 420 => 'Black Banner',
        421 => 'Banner', 422 => 'End Crystal', 423 => 'Chorus Fruit', 424 => 'Shears',
        425 => 'Chorus Fruit', 426 => 'End Rod', 427 => 'Chorus Plant', 428 => 'Pufferfish',
        429 => 'Cooked Salmon', 430 => 'Salmon', 431 => 'Tropical Fish', 432 => 'Cooked Fish',
        433 => 'Cooked Salmon', 434 => 'Cake', 435 => 'Totem of Undying', 436 => 'Iron Nugget',
    ];

    /** Tool/armor durability in uses (0 = not durable). */
    private const DURABILITY = [
        // wooden tools
        268 => 59, 269 => 59, 270 => 59, 271 => 59, 290 => 59,
        // stone tools
        272 => 131, 273 => 131, 274 => 131, 275 => 131, 291 => 131,
        // iron tools
        256 => 250, 257 => 250, 258 => 250, 267 => 250, 292 => 250,
        // golden tools
        283 => 32, 284 => 32, 285 => 32, 286 => 32, 294 => 32,
        // diamond tools
        276 => 1561, 277 => 1561, 278 => 1561, 279 => 1561, 293 => 1561,
        // misc tools
        259 => 64, 261 => 384, 346 => 64, 359 => 238, 398 => 25, 424 => 238,
        // leather armor
        298 => 55, 299 => 80, 300 => 75, 301 => 65,
        // chain armor
        302 => 165, 303 => 240, 304 => 225, 305 => 195,
        // iron armor
        306 => 165, 307 => 240, 308 => 225, 309 => 195,
        // diamond armor
        310 => 363, 311 => 528, 312 => 495, 313 => 429,
        // golden armor
        314 => 77, 315 => 112, 316 => 105, 317 => 91,
    ];

    public function getMaxStackSize(int $itemId): int {
        return self::MAX_STACK[$itemId] ?? 64;
    }

    public function getMaxDurability(int $itemId): int {
        return self::DURABILITY[$itemId] ?? 0;
    }

    public function getName(int $itemId): string {
        return self::NAMES[$itemId] ?? 'item.' . $itemId;
    }
}
