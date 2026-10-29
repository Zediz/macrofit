import { Component, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { AlertController } from '@ionic/angular';
import { IonButton, IonContent, IonHeader, IonIcon, IonTitle, IonToolbar } from '@ionic/angular';
import { Recipe } from '../../core/models/recipe.model';
import { NotifyService } from '../../core/services/notify.service';
import { RecipeDraftService } from '../../core/services/recipe-draft.service';
import { RecipeService } from '../../core/services/recipe.service';
import { apiErrorMessage, formatGrams, formatKcal } from '../../core/utils/format';

@Component({
  selector: 'app-recipes',
  templateUrl: './recipes.page.html',
  imports: [IonHeader, IonToolbar, IonTitle, IonContent, IonButton, IonIcon],
})
export class RecipesPage {
  private readonly recipes = inject(RecipeService);
  private readonly router = inject(Router);
  private readonly draft = inject(RecipeDraftService);
  private readonly alerts = inject(AlertController);
  private readonly notify = inject(NotifyService);

  readonly loading = signal(true);
  readonly error = signal<string | null>(null);
  readonly items = signal<Recipe[]>([]);
  readonly kcal = formatKcal;
  readonly grams = formatGrams;

  ionViewWillEnter(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.recipes.list().subscribe({
      next: (items) => {
        this.items.set(items);
        this.loading.set(false);
      },
      error: (error) => {
        this.error.set(apiErrorMessage(error, 'No pudimos cargar tus recetas.'));
        this.loading.set(false);
      },
    });
  }

  create(): void {
    this.draft.clear();
    void this.router.navigateByUrl('/recipes/new');
  }

  open(recipe: Recipe): void {
    void this.router.navigate(['/recipes', recipe.id]);
  }

  log(recipe: Recipe): void {
    void this.router.navigate(['/add-food'], { queryParams: { recipeId: recipe.id } });
  }

  async remove(recipe: Recipe): Promise<void> {
    const alert = await this.alerts.create({
      header: 'Eliminar receta',
      message: `¿Eliminamos “${recipe.name}”? Si ya está en un diario, no se podrá borrar.`,
      buttons: [
        { text: 'Cancelar', role: 'cancel' },
        { text: 'Eliminar', role: 'destructive' },
      ],
    });
    await alert.present();
    const { role } = await alert.onDidDismiss();
    if (role !== 'destructive') {
      return;
    }
    this.recipes.remove(recipe.id).subscribe({
      next: () => {
        void this.notify.show('Receta eliminada.', 'success');
        this.load();
      },
      error: (error) => void this.notify.show(apiErrorMessage(error), 'danger'),
    });
  }
}
