import { Macros } from './nutrition';

export interface RecipeIngredient {
  id?: number;
  food_id: number;
  grams: number;
  food_name?: string;
  brand?: string | null;
  kcal_per_100g?: number;
  protein_per_100g?: number;
  carbs_per_100g?: number;
  fat_per_100g?: number;
  kcal: number;
  protein: number;
  carbs: number;
  fat: number;
}

export interface Recipe {
  id: number;
  user_id: number;
  name: string;
  description: string | null;
  servings: number;
  total_kcal: number;
  total_protein: number;
  total_carbs: number;
  total_fat: number;
  total_grams: number;
  per_portion: Macros;
  ingredients: RecipeIngredient[];
}

export interface RecipePayload {
  name: string;
  description: string | null;
  servings: number;
  ingredients: Array<{ food_id: number; grams: number }>;
}
