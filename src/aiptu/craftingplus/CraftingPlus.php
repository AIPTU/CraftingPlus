<?php

/*
 * Copyright (c) 2022-2023 AIPTU
 *
 * For the full copyright and license information, please view
 * the LICENSE.md file that was distributed with this source code.
 *
 * @see https://github.com/AIPTU/CraftingPlus
 */

declare(strict_types=1);

namespace aiptu\craftingplus;

use pocketmine\crafting\FurnaceRecipe;
use pocketmine\crafting\FurnaceType;
use pocketmine\crafting\ShapedRecipe;
use pocketmine\crafting\ShapelessRecipe;
use pocketmine\crafting\ShapelessRecipeType;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\StringToEnchantmentParser;
use pocketmine\item\Item;
use pocketmine\item\ItemFactory;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Filesystem;
use pocketmine\utils\TextFormat;
use Symfony\Component\Filesystem\Path;
use function array_map;
use function is_array;
use function json_decode;
use function trim;

final class CraftingPlus extends PluginBase
{
	public function onEnable(): void
	{
		foreach ($this->getResources() as $resource) {
			$this->saveResource($resource->getFilename());

			$this->registerRecipe($resource->getFilename());
		}
	}

	private function registerRecipe(string $filePath): void
	{
		$recipes = json_decode(Filesystem::fileGetContents(Path::join($this->getDataFolder(), $filePath)), true);
		if (!is_array($recipes)) {
			throw new AssumptionFailedError($filePath . ' root should contain a map of recipe types');
		}
		$result = $this->getServer()->getCraftingManager();

		$itemDeserializerFunc = \Closure::fromCallable([Item::class, 'jsonDeserialize']);

		if (isset($recipes['shapeless'])) {
			foreach ($recipes['shapeless'] as $recipe) {
				$recipeType = match ($recipe['block']) {
					'crafting_table' => ShapelessRecipeType::CRAFTING(),
					'stonecutter' => ShapelessRecipeType::STONECUTTER(),
					default => null,
				};
				if ($recipeType === null) {
					continue;
				}
				$output = array_map($itemDeserializerFunc, $recipe['output']);
				if (self::containsUnknownOutputs($output)) {
					continue;
				}
				$result->registerShapelessRecipe(new ShapelessRecipe(
					array_map($itemDeserializerFunc, $recipe['input']),
					$output,
					$recipeType,
				));
			}
		}

		if (isset($recipes['shaped'])) {
			foreach ($recipes['shaped'] as $recipe) {
				if ($recipe['block'] !== 'crafting_table') {
					continue;
				}
				$output = array_map($itemDeserializerFunc, $recipe['output']);
				if (self::containsUnknownOutputs($output)) {
					continue;
				}
				$items = $output;
				if (isset($recipe['output']['name'])) {
					$output = array_map(static function (Item $item) use ($recipe): Item {
						return (trim($recipe['output']['name']) !== '') ? $item->setCustomName(TextFormat::colorize($recipe['output']['name'])) : $item;
					}, $items);
				}
				if (isset($recipe['output']['enchantments'])) {
					foreach ($recipe['output']['enchantments'] as $ench => $level) {
						$output = array_map(static function (Item $item) use ($ench, $level): Item {
							$enchant = StringToEnchantmentParser::getInstance()->parse($ench);
							return ($enchant !== null) ? $item->addEnchantment(new EnchantmentInstance($enchant, $level)) : $item;
						}, $items);
					}
				}
				$result->registerShapedRecipe(new ShapedRecipe(
					$recipe['shape'],
					array_map($itemDeserializerFunc, $recipe['input']),
					$output,
				));
			}
		}

		if (isset($recipes['smelting'])) {
			foreach ($recipes['smelting'] as $recipe) {
				$furnaceType = match ($recipe['block']) {
					'furnace' => FurnaceType::FURNACE(),
					'blast_furnace' => FurnaceType::BLAST_FURNACE(),
					'smoker' => FurnaceType::SMOKER(),
					default => null,
				};
				if ($furnaceType === null) {
					continue;
				}
				$output = Item::jsonDeserialize($recipe['output']);
				if (self::containsUnknownOutputs([$output])) {
					continue;
				}
				$result->getFurnaceRecipeManager($furnaceType)->register(
					new FurnaceRecipe(
						$output,
						Item::jsonDeserialize($recipe['input']),
					),
				);
			}
		}
	}

	/**
	 * @param array<Item> $items
	 */
	private static function containsUnknownOutputs(array $items): bool
	{
		$factory = ItemFactory::getInstance();
		foreach ($items as $item) {
			if ($item->hasAnyDamageValue()) {
				throw new \InvalidArgumentException('Recipe outputs must not have wildcard meta values');
			}
			if (!$factory->isRegistered($item->getId(), $item->getMeta())) {
				return true;
			}
		}

		return false;
	}
}
