import { Injectable, signal } from '@angular/core';
import { Food } from '../models/food.model';
import { Recipe } from '../models/recipe.model';

export interface DraftIngredient {
  food_id: number;
  food_name: string;
  brand: string | null;
  grams: number;
  kcal_per_100g: number;
  protein_per_100g: number;
  carbs_per_100g: number;
  fat_per_100g: number;
}

@Injectable({ providedIn: 'root' })
export class RecipeDraftService {
  readonly editingId = signal<number | 'new' | null>(null);
  readonly keep = signal(false);
  readonly name = signal('');
  readonly description = signal('');
  readonly servings = signal(2);
  readonly ingredients = signal<DraftIngredient[]>([]);

  reset(): void {
    this.editingId.set('new');
    this.name.set('');
    this.description.set('');
    this.servings.set(2);
    this.ingredients.set([]);
  }

  load(recipe: Recipe): void {
    this.editingId.set(recipe.id);
    this.name.set(recipe.name);
    this.description.set(recipe.description ?? '');
    this.servings.set(recipe.servings);
    this.ingredients.set(
      recipe.ingredients.map((item) => ({
        food_id: item.food_id,
        food_name: item.food_name ?? 'Ingrediente',
        brand: item.brand ?? null,
        grams: item.grams,
        kcal_per_100g: item.kcal_per_100g ?? 0,
        protein_per_100g: item.protein_per_100g ?? 0,
        carbs_per_100g: item.carbs_per_100g ?? 0,
        fat_per_100g: item.fat_per_100g ?? 0,
      })),
    );
  }

  addFood(food: Food): void {
    this.ingredients.update((items) => [
      ...items,
      {
        food_id: food.id,
        food_name: food.name,
        brand: food.brand,
        grams: 100,
        kcal_per_100g: food.kcal_per_100g,
        protein_per_100g: food.protein_per_100g,
        carbs_per_100g: food.carbs_per_100g,
        fat_per_100g: food.fat_per_100g,
      },
    ]);
  }

  setGrams(index: number, grams: number): void {
    this.ingredients.update((items) => items.map((item, i) => (i === index ? { ...item, grams } : item)));
  }

  remove(index: number): void {
    this.ingredients.update((items) => items.filter((_, i) => i !== index));
  }

  clear(): void {
    this.keep.set(false);
    this.editingId.set(null);
    this.name.set('');
    this.description.set('');
    this.servings.set(2);
    this.ingredients.set([]);
  }
}
