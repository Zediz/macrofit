import { describe, expect, it } from 'vitest';
import { mapOpenFoodFactsProduct } from './scanner.service';

describe('Open Food Facts', () => {
  it('mapea nutriments por 100 g y prefiere el nombre en español', () => {
    const food = mapOpenFoodFactsProduct('7501234567890', {
      product_name: 'Yogurt',
      product_name_es: 'Yogurt natural',
      brands: 'Lala, otra',
      nutriments: {
        'energy-kcal_100g': 62,
        proteins_100g: 3.5,
        carbohydrates_100g: '4.7',
        fat_100g: 3.3,
      },
    });
    expect(food).toMatchObject({
      name: 'Yogurt natural',
      brand: 'Lala',
      barcode: '7501234567890',
      kcal_per_100g: 62,
      protein_per_100g: 3.5,
      carbs_per_100g: 4.7,
      fat_per_100g: 3.3,
      serving_grams: null,
      source: 'openfoodfacts',
    });
  });

  it('guarda los gramos de la porción y no trata los mililitros como gramos', () => {
    const withGrams = mapOpenFoodFactsProduct('1', {
      product_name: 'Galleta',
      serving_quantity: 30,
      serving_quantity_unit: 'g',
      nutriments: { 'energy-kcal_100g': 400, proteins_100g: 5, carbohydrates_100g: 60, fat_100g: 15 },
    });
    const fromText = mapOpenFoodFactsProduct('2', {
      product_name: 'Barrita',
      serving_size: '1 barrita (40 g)',
      nutriments: { 'energy-kcal_100g': 400, proteins_100g: 5, carbohydrates_100g: 60, fat_100g: 15 },
    });
    const millilitres = mapOpenFoodFactsProduct('3', {
      product_name: 'Jugo',
      serving_quantity: 250,
      serving_quantity_unit: 'ml',
      serving_size: '250 ml',
      nutriments: { 'energy-kcal_100g': 40, proteins_100g: 0, carbohydrates_100g: 10, fat_100g: 0 },
    });
    expect(withGrams?.serving_grams).toBe(30);
    expect(fromText?.serving_grams).toBe(40);
    expect(millilitres?.serving_grams).toBeNull();
  });

  it('convierte energía en kJ cuando no hay kcal', () => {
    const food = mapOpenFoodFactsProduct('1234', {
      product_name: 'Avena',
      nutriments: {
        energy_100g: 418.4,
        proteins_100g: 10,
        carbohydrates_100g: 60,
        fat_100g: 7,
      },
    });
    expect(food?.kcal_per_100g).toBeCloseTo(100, 5);
  });

  it('regresa null si falta la tabla por 100 g', () => {
    expect(mapOpenFoodFactsProduct('1234', { product_name: 'Sin datos', nutriments: { proteins_100g: 1 } })).toBeNull();
  });
});
