import { FoodSource } from './nutrition';

export interface Food {
  id: number;
  name: string;
  brand: string | null;
  barcode: string | null;
  kcal_per_100g: number;
  protein_per_100g: number;
  carbs_per_100g: number;
  fat_per_100g: number;
  source: FoodSource;
  created_by: number | null;
}

export interface CreateFood {
  name: string;
  brand?: string | null;
  barcode?: string | null;
  kcal_per_100g: number;
  protein_per_100g: number;
  carbs_per_100g: number;
  fat_per_100g: number;
  source: FoodSource;
}
