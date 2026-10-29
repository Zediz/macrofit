import { Component, DestroyRef, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router } from '@angular/router';
import {
  IonBackButton,
  IonButton,
  IonButtons,
  IonContent,
  IonHeader,
  IonIcon,
  IonInput,
  IonTextarea,
  IonTitle,
  IonToolbar,
} from '@ionic/angular';
import { NotifyService } from '../../core/services/notify.service';
import { NutritionService } from '../../core/services/nutrition.service';
import { FoodPickerService } from '../../core/services/food-picker.service';
import { RecipeDraftService } from '../../core/services/recipe-draft.service';
import { RecipeService } from '../../core/services/recipe.service';
import { apiErrorMessage, formatGrams, formatKcal, inputNumber, inputText } from '../../core/utils/format';

@Component({
  selector: 'app-recipe-editor',
  templateUrl: './recipe-editor.page.html',
  imports: [IonHeader, IonToolbar, IonButtons, IonBackButton, IonTitle, IonContent, IonInput, IonTextarea, IonButton, IonIcon],
})
export class RecipeEditorPage {
  readonly draft = inject(RecipeDraftService);
  private readonly recipes = inject(RecipeService);
  private readonly nutrition = inject(NutritionService);
  private readonly picker = inject(FoodPickerService);
  private readonly notify = inject(NotifyService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly destroyRef = inject(DestroyRef);

  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly busy = signal(false);
  readonly text = inputText;
  readonly number = inputNumber;
  readonly kcal = formatKcal;
  readonly grams = formatGrams;

  readonly totals = computed(() =>
    this.nutrition.recipeTotals(
      this.draft.ingredients().map((item) => ({
        grams: Number(item.grams) || 0,
        per100: {
          kcal: item.kcal_per_100g,
          protein: item.protein_per_100g,
          carbs: item.carbs_per_100g,
          fat: item.fat_per_100g,
        },
      })),
    ),
  );

  readonly portion = computed(() => {
    const servings = this.draft.servings();
    if (!(servings > 0)) {
      return null;
    }
    return this.nutrition.perPortion(this.totals(), servings);
  });

  ionViewWillEnter(): void {
    const picked = this.picker.take();
    if (picked) {
      this.draft.addFood(picked);
      this.draft.keep.set(false);
      void this.notify.show(`${picked.name} entra con 100 g. Ajusta si hace falta.`, 'success');
      return;
    }
    if (this.draft.keep()) {
      this.draft.keep.set(false);
      return;
    }
    const idParam = this.route.snapshot.paramMap.get('id');
    if (!idParam) {
      this.draft.reset();
      return;
    }
    const id = Number(idParam);
    if (this.draft.editingId() === id) {
      return;
    }
    this.loading.set(true);
    this.error.set(null);
    this.recipes.get(id).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: (recipe) => {
        this.draft.load(recipe);
        this.loading.set(false);
      },
      error: (error) => {
        this.error.set(apiErrorMessage(error, 'No encontramos esa receta.'));
        this.loading.set(false);
      },
    });
  }

  openPicker(): void {
    this.draft.keep.set(true);
    const id = this.route.snapshot.paramMap.get('id');
    void this.router.navigate(['/add-food'], {
      queryParams: { pick: 'ingredient', returnTo: id ? `/recipes/${id}` : '/recipes/new' },
    });
  }

  setName(event: Event): void {
    this.draft.name.set(inputText(event));
  }

  setDescription(event: Event): void {
    this.draft.description.set(inputText(event));
  }

  setServings(event: Event): void {
    const value = inputNumber(event);
    this.draft.servings.set(value ?? 0);
  }

  save(): void {
    const name = this.draft.name().trim();
    const servings = this.draft.servings();
    const ingredients = this.draft.ingredients();
    if (!name || !(servings > 0) || ingredients.length === 0 || ingredients.some((item) => !(item.grams > 0))) {
      void this.notify.show('Nombre, porciones y gramos de cada ingrediente son obligatorios.', 'danger');
      return;
    }
    const payload = {
      name,
      description: this.draft.description().trim() || null,
      servings,
      ingredients: ingredients.map((item) => ({ food_id: item.food_id, grams: item.grams })),
    };
    const id = this.draft.editingId();
    this.busy.set(true);
    const request = typeof id === 'number' ? this.recipes.update(id, payload) : this.recipes.create(payload);
    request.subscribe({
      next: async () => {
        this.busy.set(false);
        this.draft.clear();
        await this.notify.show('Receta guardada.', 'success');
        await this.router.navigateByUrl('/recipes');
      },
      error: (error) => {
        this.busy.set(false);
        void this.notify.show(apiErrorMessage(error), 'danger');
      },
    });
  }

  cancel(): void {
    this.draft.clear();
    void this.router.navigateByUrl('/recipes');
  }
}
