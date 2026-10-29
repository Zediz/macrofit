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
import { OcrService } from '../../core/services/ocr.service';
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
  readonly simulated = signal(false);
  readonly name = signal('');
  readonly brand = signal('');
  readonly barcode = signal(this.route.snapshot.queryParamMap.get('barcode') ?? '');
  readonly kcal = signal<number | null>(null);
  readonly protein = signal<number | null>(null);
  readonly carbs = signal<number | null>(null);
  readonly fat = signal<number | null>(null);
  readonly busy = signal(false);
  readonly text = inputText;
  readonly number = inputNumber;

  async capture(): Promise<void> {
    try {
      const image = await this.ocr.capture();
      const draft = this.ocr.extract(image);
      this.photo.set(image);
      this.simulated.set(draft.simulated);
      this.name.set(draft.name);
      this.brand.set(draft.brand);
      if (!this.barcode()) {
        this.barcode.set(draft.barcode);
      }
      this.kcal.set(draft.kcal_per_100g);
      this.protein.set(draft.protein_per_100g);
      this.carbs.set(draft.carbs_per_100g);
      this.fat.set(draft.fat_per_100g);
    } catch (error) {
      void this.notify.show(apiErrorMessage(error, 'No se pudo abrir la cámara.'), 'danger');
    }
  }

  save(): void {
    const name = this.name().trim();
    const kcal = this.kcal();
    const protein = this.protein();
    const carbs = this.carbs();
    const fat = this.fat();
    if (!name || kcal == null || protein == null || carbs == null || fat == null) {
      void this.notify.show('Revisa nombre y los cuatro valores por 100 g.', 'danger');
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
}
