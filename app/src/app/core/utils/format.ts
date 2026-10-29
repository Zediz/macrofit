import { HttpErrorResponse } from '@angular/common/http';
import { FoodSource, MealType } from '../models/nutrition';

export function isoDate(date = new Date()): string {
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${date.getFullYear()}-${month}-${day}`;
}

export function shiftIsoDate(iso: string, days: number): string {
  const [year, month, day] = iso.split('-').map(Number);
  const date = new Date(year, (month ?? 1) - 1, day ?? 1);
  date.setDate(date.getDate() + days);
  return isoDate(date);
}

export function formatLongDate(iso: string): string {
  const [year, month, day] = iso.split('-').map(Number);
  const text = new Intl.DateTimeFormat('es-MX', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
  }).format(new Date(year, (month ?? 1) - 1, day ?? 1));
  return text.charAt(0).toLocaleUpperCase('es-MX') + text.slice(1);
}

export function formatKcal(value: number): string {
  return new Intl.NumberFormat('es-MX', { maximumFractionDigits: 0 }).format(value);
}

export function formatGrams(value: number): string {
  return new Intl.NumberFormat('es-MX', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(value);
}

export function inputText(event: Event): string {
  const detail = (event as CustomEvent<{ value?: string | number | null }>).detail;
  return detail?.value == null ? '' : String(detail.value);
}

export function inputNumber(event: Event): number | null {
  const raw = inputText(event).trim().replace(',', '.');
  if (raw === '') {
    return null;
  }
  const value = Number(raw);
  return Number.isFinite(value) ? value : null;
}

export function apiErrorMessage(error: unknown, fallback = 'No pudimos completar la acción.'): string {
  if (error instanceof HttpErrorResponse) {
    const payload = error.error as { body?: unknown } | null;
    if (payload && typeof payload.body === 'string' && payload.body.trim()) {
      return payload.body;
    }
    if (error.status === 0) {
      return 'No hay conexión con el servidor.';
    }
  }
  if (error instanceof Error && error.message.trim()) {
    return error.message;
  }
  return fallback;
}

export function isNotFound(error: unknown): boolean {
  return error instanceof HttpErrorResponse && error.status === 404;
}

export function mealLabel(meal: MealType): string {
  switch (meal) {
    case 'breakfast':
      return 'Desayuno';
    case 'lunch':
      return 'Comida';
    case 'dinner':
      return 'Cena';
    case 'snack':
      return 'Snack';
  }
}

export function sourceLabel(source: FoodSource): string {
  switch (source) {
    case 'openfoodfacts':
      return 'Open Food Facts';
    case 'ocr':
      return 'Foto';
    default:
      return 'Catálogo';
  }
}

export function clampPercent(consumed: number, goal: number): number {
  if (!(goal > 0)) {
    return 0;
  }
  return Math.max(0, Math.min(100, (consumed / goal) * 100));
}
