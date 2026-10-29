import { ActivityLevel, MealType } from './models/nutrition';

export const ACTIVITIES: Array<{ id: ActivityLevel; label: string; hint: string }> = [
  { id: 'sedentary', label: 'Sedentario', hint: 'Casi no te mueves en el día' },
  { id: 'light', label: 'Ligero', hint: 'Ejercicio 1 a 3 días por semana' },
  { id: 'moderate', label: 'Moderado', hint: 'Ejercicio 3 a 5 días por semana' },
  { id: 'active', label: 'Activo', hint: 'Entrenas casi todos los días' },
];

export const MEALS: Array<{ id: MealType; label: string }> = [
  { id: 'breakfast', label: 'Desayuno' },
  { id: 'lunch', label: 'Comida' },
  { id: 'dinner', label: 'Cena' },
  { id: 'snack', label: 'Snack' },
];

export const RING_RADIUS = 52;
export const RING_CIRCUMFERENCE = 2 * Math.PI * RING_RADIUS;
