import { Injectable } from '@angular/core';
import { Camera, CameraResultType, CameraSource } from '@capacitor/camera';

export interface OcrDraft {
  name: string;
  brand: string;
  barcode: string;
  kcal_per_100g: number;
  protein_per_100g: number;
  carbs_per_100g: number;
  fat_per_100g: number;
  /** Siempre true: la extracción real todavía no está conectada. */
  simulated: true;
}

@Injectable({ providedIn: 'root' })
export class OcrService {
  async capture(): Promise<string> {
    const photo = await Camera.getPhoto({
      quality: 70,
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

  /**
   * PLACEHOLDER DE OCR.
   * No lee la imagen. Devuelve una tabla de ejemplo para que la persona
   * la revise y la corrija antes de guardarla en el catálogo.
   * Sustituir por un modelo de visión cuando se defina el proveedor.
   */
  extract(_imageDataUrl: string): OcrDraft {
    return {
      name: 'Yogurt natural',
      brand: 'Ejemplo',
      barcode: '',
      kcal_per_100g: 62,
      protein_per_100g: 3.5,
      carbs_per_100g: 4.7,
      fat_per_100g: 3.3,
      simulated: true,
    };
  }
}
