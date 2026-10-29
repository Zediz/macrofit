import { Component, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import {
  IonBackButton,
  IonButton,
  IonButtons,
  IonContent,
  IonHeader,
  IonInput,
  IonTitle,
  IonToolbar,
} from '@ionic/angular';
import { FoodService } from '../../core/services/food.service';
import { NotifyService } from '../../core/services/notify.service';
import { OcrLabel, OcrService } from '../../core/services/ocr.service';
import { apiErrorMessage, inputNumber, inputText } from '../../core/utils/format';

@Component({
  selector: 'app-ocr',
  templateUrl: './ocr.page.html',
  imports: [IonHeader, IonToolbar, IonButtons, IonBackButton, IonTitle, IonContent, IonButton, IonInput],
})
export class OcrPage {
  private readonly ocr = inject(OcrService);
  private readonly foods = inject(FoodService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly notify = inject(NotifyService);

  readonly photo = signal<string | null>(null);
  readonly name = signal('');
  readonly brand = signal('');
  readonly barcode = signal(this.route.snapshot.queryParamMap.get('barcode') ?? '');
  readonly serving = signal<number | null>(null);
  readonly kcal = signal<number | null>(null);
  readonly protein = signal<number | null>(null);
  readonly carbs = signal<number | null>(null);
  readonly fat = signal<number | null>(null);
  readonly reading = signal(false);
  readonly busy = signal(false);
  readonly text = inputText;
  readonly number = inputNumber;

  async capture(): Promise<void> {
    this.reading.set(true);
    try {
      const raw = await this.ocr.takePhoto();
      const image = await this.ocr.compress(raw);
      this.photo.set(image);
      this.name.set('');
      this.brand.set('');
      this.serving.set(null);
      this.kcal.set(null);
      this.protein.set(null);
      this.carbs.set(null);
      this.fat.set(null);
      this.apply(await this.ocr.readLabel(image));
    } catch (error) {
      void this.notify.show(apiErrorMessage(error, 'No se pudo leer la etiqueta.'), 'danger');
    } finally {
      this.reading.set(false);
    }
  }

  save(): void {
    const name = this.name().trim();
    const kcal = this.kcal();
    const protein = this.protein();
    const carbs = this.carbs();
    const fat = this.fat();
    const serving = this.serving();
    if (!name || kcal == null || protein == null || carbs == null || fat == null) {
      void this.notify.show('Revisa nombre y los cuatro valores por 100 g.', 'danger');
      return;
    }
    if (serving != null && !(serving > 0)) {
      void this.notify.show('La porción en gramos debe ser mayor a 0, o déjala vacía.', 'danger');
      return;
    }
    this.busy.set(true);
    this.foods
      .create({
        name,
        brand: this.brand().trim() || null,
        barcode: this.barcode().trim() || null,
        kcal_per_100g: kcal,
        protein_per_100g: protein,
        carbs_per_100g: carbs,
        fat_per_100g: fat,
        serving_grams: serving != null && serving > 0 ? serving : null,
        source: 'ocr',
      })
      .subscribe({
        next: async (food) => {
          this.busy.set(false);
          await this.notify.show('Alimento guardado en el catálogo.', 'success');
          const params = this.route.snapshot.queryParamMap;
          await this.router.navigate(['/add-food'], {
            queryParams: { foodId: food.id, date: params.get('date'), meal: params.get('meal') },
          });
        },
        error: (error) => {
          this.busy.set(false);
          void this.notify.show(apiErrorMessage(error), 'danger');
        },
      });
  }

  private apply(label: OcrLabel): void {
    this.name.set(label.name ?? '');
    this.serving.set(label.serving_grams);
    this.kcal.set(label.kcal_per_100g);
    this.protein.set(label.protein_per_100g);
    this.carbs.set(label.carbs_per_100g);
    this.fat.set(label.fat_per_100g);
  }
}
