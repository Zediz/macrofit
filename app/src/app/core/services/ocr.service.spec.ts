import { describe, expect, it } from 'vitest';
import { fittedSize, mapOcrLabel } from './ocr.service';

describe('OCR', () => {
  it('encoge el lado largo a 1280 px y deja intactas las fotos pequeñas', () => {
    expect(fittedSize(2000, 1000)).toEqual({ width: 1280, height: 640 });
    expect(fittedSize(100, 3000)).toEqual({ width: 43, height: 1280 });
    expect(fittedSize(800, 600)).toEqual({ width: 800, height: 600 });
  });

  it('normaliza la respuesta del API y descarta una porción imposible', () => {
    expect(
      mapOcrLabel({
        name: '  Yogurt natural  ',
        serving_grams: 125,
        kcal_per_100g: 62,
        protein_per_100g: 3.5,
        carbs_per_100g: 4.7,
        fat_per_100g: 3.3,
      }),
    ).toEqual({
      name: 'Yogurt natural',
      serving_grams: 125,
      kcal_per_100g: 62,
      protein_per_100g: 3.5,
      carbs_per_100g: 4.7,
      fat_per_100g: 3.3,
    });
    expect(mapOcrLabel({ name: '', serving_grams: 0, kcal_per_100g: 1, protein_per_100g: 0, carbs_per_100g: 0, fat_per_100g: 0 }).name).toBeNull();
    expect(mapOcrLabel({ name: 'Agua', serving_grams: 9000, kcal_per_100g: 0, protein_per_100g: 0, carbs_per_100g: 0, fat_per_100g: 0 }).serving_grams).toBeNull();
  });

  it('rechaza una respuesta sin los cuatro valores por 100 g', () => {
    expect(() => mapOcrLabel({ name: 'Incompleta', kcal_per_100g: 10 })).toThrow(/100 g/);
  });
});
