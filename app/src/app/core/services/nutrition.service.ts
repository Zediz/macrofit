import { Injectable } from '@angular/core';
import { ActivityLevel, Gender, MacroPercentages, Macros, QuantityUnit } from '../models/nutrition';

export const ACTIVITY_MULTIPLIERS: Record<ActivityLevel, number> = {
  sedentary: 1.2,
  light: 1.375,
  moderate: 1.55,
  active: 1.725,
};

/** Mifflin-St Jeor. Hombres +5, mujeres −161. */
export function calculateBmr(gender: Gender, weightKg: number, heightCm: number, age: number): number {
  const base = 10 * weightKg + 6.25 * heightCm - 5 * age;
  return gender === 'male' ? base + 5 : base - 161;
}

export function calculateTdee(bmr: number, activity: ActivityLevel): number {
  return bmr * ACTIVITY_MULTIPLIERS[activity];
}

export interface MacroGoals extends Macros {
  protein_pct: number | null;
  carbs_pct: number | null;
  fat_pct: number | null;
}

/**
 * Proteína 2 g/kg (4 kcal/g), grasa 25 % de las kcal (9 kcal/g),
 * carbohidratos con el resto (4 kcal/g). Si hay porcentajes, sustituyen ese reparto.
 */
export function calculateMacroGoals(tdee: number, weightKg: number, percentages?: MacroPercentages | null): MacroGoals {
  if (percentages) {
    const sum = percentages.protein + percentages.carbs + percentages.fat;
    if (Math.abs(sum - 100) > 0.05) {
      throw new Error('Los porcentajes de macros deben sumar 100');
    }
    for (const value of [percentages.protein, percentages.carbs, percentages.fat]) {
      if (value < 0 || value > 100) {
        throw new Error('Cada porcentaje debe estar entre 0 y 100');
      }
    }
    return {
      kcal: tdee,
      protein: (tdee * (percentages.protein / 100)) / 4,
      carbs: (tdee * (percentages.carbs / 100)) / 4,
      fat: (tdee * (percentages.fat / 100)) / 9,
      protein_pct: percentages.protein,
      carbs_pct: percentages.carbs,
      fat_pct: percentages.fat,
    };
  }
  const protein = 2 * weightKg;
  const proteinKcal = protein * 4;
  const fatKcal = tdee * 0.25;
  const carbKcal = Math.max(0, tdee - proteinKcal - fatKcal);
  return {
    kcal: tdee,
    protein,
    carbs: carbKcal / 4,
    fat: fatKcal / 9,
    protein_pct: null,
    carbs_pct: null,
    fat_pct: null,
  };
}

/** (macro por 100 g / 100) × gramos. */
export function scalePer100g(per100: Macros, grams: number): Macros {
  const factor = grams / 100;
  return {
    kcal: per100.kcal * factor,
    protein: per100.protein * factor,
    carbs: per100.carbs * factor,
    fat: per100.fat * factor,
  };
}

export function sumMacros(items: Macros[]): Macros {
  return items.reduce(
    (acc, item) => ({
      kcal: acc.kcal + item.kcal,
      protein: acc.protein + item.protein,
      carbs: acc.carbs + item.carbs,
      fat: acc.fat + item.fat,
    }),
    { kcal: 0, protein: 0, carbs: 0, fat: 0 },
  );
}

export interface RecipeIngredientAmount {
  per100: Macros;
  grams: number;
}

/** Suma de (macros por 1 g × gramos usados). */
export function recipeTotals(ingredients: RecipeIngredientAmount[]): Macros {
  return sumMacros(ingredients.map((item) => scalePer100g(item.per100, item.grams)));
}

export function perPortion(total: Macros, servings: number): Macros {
  if (!(servings > 0)) {
    throw new Error('El número de porciones debe ser mayor a 0');
  }
  return {
    kcal: total.kcal / servings,
    protein: total.protein / servings,
    carbs: total.carbs / servings,
    fat: total.fat / servings,
  };
}

export interface LogMathInput {
  kind: 'food' | 'recipe';
  quantity: number;
  unit: QuantityUnit;
  per100?: Macros;
  /** Gramos de una porción del alimento. Obligatorio si unit es portions. */
  servingGrams?: number | null;
  recipeTotal?: Macros;
  recipeServings?: number;
  recipeGrams?: number;
}

/** Gramos de un alimento. 1.5 porciones de 30 g = 45 g. Sin tamaño de porción no se asume 100 g. */
export function resolveFoodGrams(quantity: number, unit: QuantityUnit, servingGrams?: number | null): number {
  if (!(quantity > 0)) {
    throw new Error('La cantidad debe ser mayor a 0');
  }
  if (unit === 'grams') {
    return quantity;
  }
  if (!(servingGrams && servingGrams > 0)) {
    throw new Error('Este alimento no tiene tamaño de porción');
  }
  return quantity * servingGrams;
}

/**
 * Alimento en gramos: regla de tres sobre 100 g.
 * Alimento en porciones: gramos = cantidad × tamaño de porción.
 * Receta en porciones: (total / porciones) × cantidad.
 * Receta en gramos: total × (gramos / gramos de la receta).
 */
export function logEntryMacros(input: LogMathInput): Macros {
  if (!(input.quantity > 0)) {
    throw new Error('La cantidad debe ser mayor a 0');
  }
  if (input.kind === 'food') {
    if (!input.per100) {
      throw new Error('Faltan los macros por 100 g');
    }
    return scalePer100g(input.per100, resolveFoodGrams(input.quantity, input.unit, input.servingGrams));
  }
  if (!input.recipeTotal || !(input.recipeServings && input.recipeServings > 0)) {
    throw new Error('Faltan los totales de la receta');
  }
  if (input.unit === 'portions') {
    const one = perPortion(input.recipeTotal, input.recipeServings);
    return {
      kcal: one.kcal * input.quantity,
      protein: one.protein * input.quantity,
      carbs: one.carbs * input.quantity,
      fat: one.fat * input.quantity,
    };
  }
  if (!(input.recipeGrams && input.recipeGrams > 0)) {
    throw new Error('La receta no tiene gramos para escalar');
  }
  const factor = input.quantity / input.recipeGrams;
  return {
    kcal: input.recipeTotal.kcal * factor,
    protein: input.recipeTotal.protein * factor,
    carbs: input.recipeTotal.carbs * factor,
    fat: input.recipeTotal.fat * factor,
  };
}

export function remainingMacros(goals: Macros, consumed: Macros): Macros {
  return {
    kcal: goals.kcal - consumed.kcal,
    protein: goals.protein - consumed.protein,
    carbs: goals.carbs - consumed.carbs,
    fat: goals.fat - consumed.fat,
  };
}

@Injectable({ providedIn: 'root' })
export class NutritionService {
  bmr(gender: Gender, weightKg: number, heightCm: number, age: number): number {
    return calculateBmr(gender, weightKg, heightCm, age);
  }

  tdee(bmr: number, activity: ActivityLevel): number {
    return calculateTdee(bmr, activity);
  }

  goals(tdee: number, weightKg: number, percentages?: MacroPercentages | null): MacroGoals {
    return calculateMacroGoals(tdee, weightKg, percentages);
  }

  recipeTotals(ingredients: RecipeIngredientAmount[]): Macros {
    return recipeTotals(ingredients);
  }

  perPortion(total: Macros, servings: number): Macros {
    return perPortion(total, servings);
  }

  logEntry(input: LogMathInput): Macros {
    return logEntryMacros(input);
  }

  remaining(goals: Macros, consumed: Macros): Macros {
    return remainingMacros(goals, consumed);
  }
}
