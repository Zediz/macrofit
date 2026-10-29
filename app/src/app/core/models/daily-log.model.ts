import { Macros, MealType, QuantityUnit } from './nutrition';

export interface DailyLog {
  id: number;
  user_id: number;
  food_id: number | null;
  recipe_id: number | null;
  quantity: number;
  unit: QuantityUnit;
  meal_type: MealType;
  log_date: string;
  name: string;
  brand: string | null;
  kcal: number;
  protein: number;
  carbs: number;
  fat: number;
}

export interface DailySummary {
  date: string;
  consumed: Macros;
  goals: Macros;
  remaining: Macros;
  onboarding_complete: boolean;
}

export interface CreateDailyLog {
  food_id?: number;
  recipe_id?: number;
  quantity: number;
  unit: QuantityUnit;
  meal_type: MealType;
  log_date: string;
}
