import { describe, expect, it } from 'vitest';
import {
  calculateBmr,
  calculateMacroGoals,
  calculateTdee,
  logEntryMacros,
  perPortion,
  recipeTotals,
  remainingMacros,
} from './nutrition.service';

const chicken = { kcal: 110, protein: 23.1, carbs: 0, fat: 1.2 };
const rice = { kcal: 130, protein: 2.7, carbs: 28.2, fat: 0.3 };

describe('Mifflin-St Jeor y metas', () => {
  it('calcula el BMR de un hombre', () => {
    expect(calculateBmr('male', 80, 180, 30)).toBe(1780);
  });

  it('calcula el BMR de una mujer', () => {
    expect(calculateBmr('female', 60, 165, 25)).toBe(1345.25);
  });

  it('aplica los cuatro multiplicadores de actividad', () => {
    expect(calculateTdee(1780, 'sedentary')).toBeCloseTo(2136, 5);
    expect(calculateTdee(1780, 'light')).toBeCloseTo(2447.5, 5);
    expect(calculateTdee(1780, 'moderate')).toBe(2759);
    expect(calculateTdee(1780, 'active')).toBeCloseTo(3070.5, 5);
    expect(calculateTdee(1345.25, 'light')).toBeCloseTo(1849.71875, 5);
  });

  it('reparte proteína 2 g/kg, grasa 25 % y carbohidratos el resto', () => {
    const goals = calculateMacroGoals(2759, 80);
    expect(goals.kcal).toBe(2759);
    expect(goals.protein).toBe(160);
    expect(goals.fat).toBeCloseTo(76.6388888889, 5);
    expect(goals.carbs).toBeCloseTo(357.3125, 5);
    expect(goals.protein_pct).toBeNull();
  });

  it('acepta porcentajes manuales que suman 100', () => {
    const goals = calculateMacroGoals(2000, 80, { protein: 40, carbs: 40, fat: 20 });
    expect(goals.protein).toBe(200);
    expect(goals.carbs).toBe(200);
    expect(goals.fat).toBeCloseTo(44.4444444444, 5);
    expect(goals.fat_pct).toBe(20);
  });

  it('rechaza porcentajes que no suman 100', () => {
    expect(() => calculateMacroGoals(2000, 80, { protein: 30, carbs: 30, fat: 30 })).toThrow(/sumar 100/);
  });
});

describe('matemática de recetas', () => {
  it('suma macros por gramo y divide entre porciones', () => {
    const total = recipeTotals([
      { per100: chicken, grams: 200 },
      { per100: rice, grams: 150 },
    ]);
    expect(total.kcal).toBeCloseTo(415, 5);
    expect(total.protein).toBeCloseTo(50.25, 5);
    expect(total.carbs).toBeCloseTo(42.3, 5);
    expect(total.fat).toBeCloseTo(2.85, 5);
    const portion = perPortion(total, 2);
    expect(portion.kcal).toBeCloseTo(207.5, 5);
    expect(portion.protein).toBeCloseTo(25.125, 5);
  });

  it('exige porciones mayores a cero', () => {
    expect(() => perPortion({ kcal: 10, protein: 1, carbs: 1, fat: 1 }, 0)).toThrow(/porciones/);
  });
});

describe('matemática del diario', () => {
  it('escala un alimento con regla de tres sobre 100 g', () => {
    const eaten = logEntryMacros({ kind: 'food', quantity: 150, unit: 'grams', per100: chicken });
    expect(eaten.kcal).toBeCloseTo(165, 5);
    expect(eaten.protein).toBeCloseTo(34.65, 5);
    expect(eaten.carbs).toBeCloseTo(0, 5);
    expect(eaten.fat).toBeCloseTo(1.8, 5);
  });

  it('trata una porción de alimento como 100 g', () => {
    const eaten = logEntryMacros({ kind: 'food', quantity: 2, unit: 'portions', per100: chicken });
    expect(eaten.kcal).toBe(220);
    expect(eaten.protein).toBeCloseTo(46.2, 5);
  });

  it('multiplica los macros por porción de una receta', () => {
    const total = recipeTotals([
      { per100: chicken, grams: 200 },
      { per100: rice, grams: 150 },
    ]);
    const eaten = logEntryMacros({
      kind: 'recipe',
      quantity: 1.5,
      unit: 'portions',
      recipeTotal: total,
      recipeServings: 4,
      recipeGrams: 350,
    });
    expect(eaten.kcal).toBeCloseTo((415 / 4) * 1.5, 5);
    expect(eaten.protein).toBeCloseTo((50.25 / 4) * 1.5, 5);
  });

  it('escala una receta cuando se registra en gramos', () => {
    const total = { kcal: 415, protein: 50.25, carbs: 42.3, fat: 2.85 };
    const eaten = logEntryMacros({
      kind: 'recipe',
      quantity: 175,
      unit: 'grams',
      recipeTotal: total,
      recipeServings: 2,
      recipeGrams: 350,
    });
    expect(eaten.kcal).toBeCloseTo(207.5, 5);
    expect(eaten.fat).toBeCloseTo(1.425, 5);
  });

  it('resta lo consumido de la meta, aunque se pase', () => {
    expect(remainingMacros({ kcal: 2759, protein: 160, carbs: 357.31, fat: 76.64 }, { kcal: 2800, protein: 40, carbs: 10, fat: 5 })).toEqual({
      kcal: -41,
      protein: 120,
      carbs: 347.31,
      fat: 71.64,
    });
  });
});
