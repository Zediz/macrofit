export interface Macros {
  kcal: number;
  protein: number;
  carbs: number;
  fat: number;
}

export type Gender = 'male' | 'female';
export type ActivityLevel = 'sedentary' | 'light' | 'moderate' | 'active';
export type MealType = 'breakfast' | 'lunch' | 'dinner' | 'snack';
export type QuantityUnit = 'grams' | 'portions';
export type FoodSource = 'local' | 'openfoodfacts' | 'ocr';

export interface MacroPercentages {
  protein: number;
  carbs: number;
  fat: number;
}
