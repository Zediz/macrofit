import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { BarcodeFormat, BarcodeScanner } from '@capacitor-mlkit/barcode-scanning';
import { Capacitor } from '@capacitor/core';
import { firstValueFrom } from 'rxjs';
import { CreateFood, Food } from '../models/food.model';
import { isNotFound } from '../utils/format';
import { FoodService } from './food.service';

export interface OffProduct {
  product_name?: string;
  product_name_es?: string;
  product_name_en?: string;
  brands?: string;
  serving_size?: string;
  serving_quantity?: number | string;
  serving_quantity_unit?: string;
  nutriments?: Record<string, unknown>;
}

export interface OffResponse {
  status: number;
  product?: OffProduct;
}

export type BarcodeLookup =
  | { status: 'found'; food: Food; origin: 'local' | 'openfoodfacts' }
  | { status: 'not_found'; barcode: string };

const OFF_URL = 'https://world.openfoodfacts.org/api/v2/product';

@Injectable({ providedIn: 'root' })
export class ScannerService {
  private readonly http = inject(HttpClient);
  private readonly foods = inject(FoodService);

  async scanBarcode(): Promise<string> {
    if (!Capacitor.isNativePlatform()) {
      throw new Error('El escáner abre la cámara en la app instalada. En el navegador, escribe el código.');
    }
    const supported = await BarcodeScanner.isSupported();
    if (!supported.supported) {
      throw new Error('Este dispositivo no puede escanear códigos.');
    }
    const permission = await BarcodeScanner.requestPermissions();
    if (permission.camera !== 'granted' && permission.camera !== 'limited') {
      throw new Error('Se necesita permiso de cámara para escanear.');
    }
    if (Capacitor.getPlatform() === 'android') {
      const module = await BarcodeScanner.isGoogleBarcodeScannerModuleAvailable();
      if (!module.available) {
        await BarcodeScanner.installGoogleBarcodeScannerModule();
        throw new Error('Estamos instalando el escáner de Google. Inténtalo de nuevo en un momento.');
      }
    }
    const { barcodes } = await BarcodeScanner.scan({
      formats: [BarcodeFormat.Ean13, BarcodeFormat.Ean8, BarcodeFormat.UpcA, BarcodeFormat.UpcE, BarcodeFormat.Code128],
    });
    const code = barcodes[0]?.rawValue?.trim();
    if (!code) {
      throw new Error('No se leyó ningún código.');
    }
    return code;
  }

  /** Catálogo propio → Open Food Facts → no encontrado. Si viene de OFF, se guarda en la API. */
  async lookup(barcode: string): Promise<BarcodeLookup> {
    const code = barcode.trim();
    if (!code) {
      throw new Error('Escribe o escanea un código.');
    }
    try {
      const local = await firstValueFrom(this.foods.byBarcode(code));
      return { status: 'found', food: local, origin: 'local' };
    } catch (error) {
      if (!isNotFound(error)) {
        throw error;
      }
    }
    const draft = await this.fetchOpenFoodFacts(code);
    if (!draft) {
      return { status: 'not_found', barcode: code };
    }
    try {
      const food = await firstValueFrom(this.foods.create(draft));
      return { status: 'found', food, origin: 'openfoodfacts' };
    } catch (error) {
      if (!isNotFound(error)) {
        const again = await firstValueFrom(this.foods.byBarcode(code)).catch(() => null);
        if (again) {
          return { status: 'found', food: again, origin: 'local' };
        }
      }
      throw error;
    }
  }

  private async fetchOpenFoodFacts(barcode: string): Promise<CreateFood | null> {
    try {
      const response = await firstValueFrom(this.http.get<OffResponse>(`${OFF_URL}/${encodeURIComponent(barcode)}.json`));
      if (!response || response.status !== 1 || !response.product) {
        return null;
      }
      return mapOpenFoodFactsProduct(barcode, response.product);
    } catch (error) {
      if (isNotFound(error)) {
        return null;
      }
      throw new Error('No pudimos consultar Open Food Facts. Revisa tu conexión.');
    }
  }
}

export function mapOpenFoodFactsProduct(barcode: string, product: OffProduct): CreateFood | null {
  const nutriments = product.nutriments ?? {};
  const kcal = pickNumber(nutriments['energy-kcal_100g']) ?? kjToKcal(pickNumber(nutriments['energy_100g']));
  const protein = pickNumber(nutriments['proteins_100g']);
  const carbs = pickNumber(nutriments['carbohydrates_100g']);
  const fat = pickNumber(nutriments['fat_100g']);
  const name = (product.product_name_es || product.product_name || product.product_name_en || '').trim();
  if (kcal == null || protein == null || carbs == null || fat == null || !name) {
    return null;
  }
  const brand = (product.brands ?? '').split(',')[0]?.trim() ?? '';
  return {
    name,
    brand: brand || null,
    barcode,
    kcal_per_100g: roundNutrient(kcal),
    protein_per_100g: roundNutrient(protein),
    carbs_per_100g: roundNutrient(carbs),
    fat_per_100g: roundNutrient(fat),
    serving_grams: servingGramsFromOff(product),
    source: 'openfoodfacts',
  };
}

/** Gramos de la porción si Open Food Facts los declara. Los mililitros no se convierten. */
export function servingGramsFromOff(product: OffProduct): number | null {
  const unit = (product.serving_quantity_unit ?? '').trim().toLowerCase();
  const quantity = pickNumber(product.serving_quantity);
  if (quantity != null && quantity > 0 && quantity <= 5000 && ['g', 'gr', 'gram', 'grams', 'gramo', 'gramos'].includes(unit)) {
    return roundNutrient(quantity);
  }
  return gramsInText(product.serving_size);
}

function gramsInText(value: unknown): number | null {
  if (typeof value !== 'string') {
    return null;
  }
  const matches = [...value.matchAll(/(\d+(?:[.,]\d+)?)\s*(?:g|gr|gramos)\b/gi)];
  if (matches.length === 0) {
    return null;
  }
  const parsed = Number(matches[matches.length - 1][1].replace(',', '.'));
  if (!(parsed > 0) || parsed > 5000) {
    return null;
  }
  return roundNutrient(parsed);
}

function pickNumber(value: unknown): number | null {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return value;
  }
  if (typeof value === 'string' && value.trim() !== '' && Number.isFinite(Number(value))) {
    return Number(value);
  }
  return null;
}

function kjToKcal(kj: number | null): number | null {
  return kj == null ? null : kj / 4.184;
}

function roundNutrient(value: number): number {
  return Math.round(value * 100) / 100;
}
