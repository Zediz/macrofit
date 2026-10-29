import { Injectable, inject } from '@angular/core';
import { Camera, CameraResultType, CameraSource } from '@capacitor/camera';
import { firstValueFrom } from 'rxjs';
import { ApiService } from './api.service';

export interface OcrLabel {
  name: string | null;
  serving_grams: number | null;
  kcal_per_100g: number;
  protein_per_100g: number;
  carbs_per_100g: number;
  fat_per_100g: number;
}

export const OCR_MAX_EDGE = 1280;
export const OCR_JPEG_QUALITY = 0.72;

export function fittedSize(width: number, height: number, maxEdge = OCR_MAX_EDGE): { width: number; height: number } {
  const safeWidth = Number.isFinite(width) && width > 0 ? width : 1;
  const safeHeight = Number.isFinite(height) && height > 0 ? height : 1;
  const edge = Math.max(safeWidth, safeHeight);
  if (edge <= maxEdge) {
    return { width: Math.max(1, Math.round(safeWidth)), height: Math.max(1, Math.round(safeHeight)) };
  }
  const scale = maxEdge / edge;
  return {
    width: Math.max(1, Math.round(safeWidth * scale)),
    height: Math.max(1, Math.round(safeHeight * scale)),
  };
}

export function compressDataUrl(dataUrl: string, maxEdge = OCR_MAX_EDGE, quality = OCR_JPEG_QUALITY): Promise<string> {
  return new Promise((resolve, reject) => {
    const image = new Image();
    image.onload = () => {
      const size = fittedSize(image.naturalWidth || image.width, image.naturalHeight || image.height, maxEdge);
      const canvas = document.createElement('canvas');
      canvas.width = size.width;
      canvas.height = size.height;
      const context = canvas.getContext('2d');
      if (!context) {
        reject(new Error('No se pudo preparar la foto.'));
        return;
      }
      context.drawImage(image, 0, 0, size.width, size.height);
      const jpeg = canvas.toDataURL('image/jpeg', quality);
      if (!jpeg.startsWith('data:image/jpeg')) {
        reject(new Error('No se pudo comprimir la foto.'));
        return;
      }
      resolve(jpeg);
    };
    image.onerror = () => reject(new Error('No se pudo leer la foto.'));
    image.src = dataUrl;
  });
}

export function mapOcrLabel(body: Partial<OcrLabel> | null | undefined): OcrLabel {
  const kcal = asNutrient(body?.kcal_per_100g, 1000);
  const protein = asNutrient(body?.protein_per_100g, 100);
  const carbs = asNutrient(body?.carbs_per_100g, 100);
  const fat = asNutrient(body?.fat_per_100g, 100);
  if (kcal == null || protein == null || carbs == null || fat == null) {
    throw new Error('La respuesta del OCR no trae los valores por 100 g.');
  }
  const name = typeof body?.name === 'string' ? body.name.trim() : '';
  return {
    name: name === '' ? null : name,
    serving_grams: asServing(body?.serving_grams),
    kcal_per_100g: kcal,
    protein_per_100g: protein,
    carbs_per_100g: carbs,
    fat_per_100g: fat,
  };
}

@Injectable({ providedIn: 'root' })
export class OcrService {
  private readonly api = inject(ApiService);

  async takePhoto(): Promise<string> {
    const photo = await Camera.getPhoto({
      quality: 60,
      width: 1600,
      allowEditing: false,
      resultType: CameraResultType.DataUrl,
      source: CameraSource.Prompt,
      promptLabelHeader: 'Tabla nutrimental',
      promptLabelCancel: 'Cancelar',
      promptLabelPhoto: 'Galería',
      promptLabelPicture: 'Cámara',
    });
    if (!photo.dataUrl) {
      throw new Error('No se obtuvo la foto.');
    }
    return photo.dataUrl;
  }

  compress(dataUrl: string): Promise<string> {
    return compressDataUrl(dataUrl);
  }

  async readLabel(image: string): Promise<OcrLabel> {
    const body = await firstValueFrom(this.api.post<OcrLabel>('/foods/ocr', { image }));
    return mapOcrLabel(body);
  }
}

function asNutrient(value: unknown, max: number): number | null {
  const number = typeof value === 'number' ? value : typeof value === 'string' && value.trim() !== '' ? Number(value) : Number.NaN;
  if (!Number.isFinite(number) || number < 0 || number > max) {
    return null;
  }
  return number;
}

function asServing(value: unknown): number | null {
  const number = typeof value === 'number' ? value : typeof value === 'string' && value.trim() !== '' ? Number(value) : Number.NaN;
  if (!Number.isFinite(number) || number <= 0 || number > 5000) {
    return null;
  }
  return number;
}
