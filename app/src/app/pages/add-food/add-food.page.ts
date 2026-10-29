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
  IonTitle,
  IonToolbar,
} from '@ionic/angular';
import { Subject, catchError, debounceTime, distinctUntilChanged, of, switchMap } from 'rxjs';
import { MEALS } from '../../core/constants';
import { Food } from '../../core/models/food.model';
import { MealType, QuantityUnit } from '../../core/models/nutrition';
import { Recipe } from '../../core/models/recipe.model';
import { DiaryState } from '../../core/services/diary.service';
import { FoodPickerService } from '../../core/services/food-picker.service';
import { FoodService } from '../../core/services/food.service';
import { NotifyService } from '../../core/services/notify.service';
import { NutritionService, resolveFoodGrams } from '../../core/services/nutrition.service';
import { RecipeService } from '../../core/services/recipe.service';
import { ScannerService } from '../../core/services/scanner.service';
import { TrackingService } from '../../core/services/tracking.service';
import { apiErrorMessage, formatGrams, formatKcal, inputNumber, inputText, sourceLabel } from '../../core/utils/format';

function amount(value: number): string {
  return new Intl.NumberFormat('es-MX', { maximumFractionDigits: 2 }).format(value);
}

type Target = { kind: 'food'; food: Food } | { kind: 'recipe'; recipe: Recipe };

@Component({
  selector: 'app-add-food',
  templateUrl: './add-food.page.html',
  imports: [IonHeader, IonToolbar, IonTitle, IonButtons, IonBackButton, IonContent, IonInput, IonButton, IonIcon],
})
export class AddFoodPage {
  private readonly foods = inject(FoodService);
  private readonly recipesApi = inject(RecipeService);
  private readonly scanner = inject(ScannerService);
  private readonly tracking = inject(TrackingService);
  private readonly nutrition = inject(NutritionService);
  private readonly picker = inject(FoodPickerService);
  private readonly notify = inject(NotifyService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly diary = inject(DiaryState);
  private readonly destroyRef = inject(DestroyRef);
  private readonly queries = new Subject<string>();

  readonly meals = MEALS;
  readonly pickMode = signal(false);
  readonly catalog = signal<'foods' | 'recipes'>('foods');
  readonly query = signal('');
  readonly barcode = signal('');
  readonly results = signal<Food[]>([]);
  readonly recipes = signal<Recipe[]>([]);
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly lookupMessage = signal<string | null>(null);
  readonly scanning = signal(false);
  readonly target = signal<Target | null>(null);
  readonly quantity = signal<number | null>(100);
  readonly unit = signal<QuantityUnit>('grams');
  readonly meal = signal<MealType>('breakfast');
  readonly saving = signal(false);
  readonly text = inputText;
  readonly number = inputNumber;
  readonly kcal = formatKcal;
  readonly grams = formatGrams;
  readonly sourceLabel = sourceLabel;

  readonly quantityLabel = computed(() => (this.unit() === 'portions' ? 'Porciones' : 'Gramos'));
  readonly showUnitToggle = computed(() => {
    const target = this.target();
    if (!target) {
      return false;
    }
    if (target.kind === 'recipe') {
      return true;
    }
    return !!(target.food.serving_grams && target.food.serving_grams > 0);
  });
  readonly portionHint = computed(() => {
    const target = this.target();
    if (!target || target.kind !== 'food') {
      return null;
    }
    const serving = target.food.serving_grams;
    if (!(serving && serving > 0)) {
      return 'Los alimentos se registran en gramos.';
    }
    const quantity = this.quantity();
    if (this.unit() === 'portions' && quantity && quantity > 0) {
      return `1 porción = ${amount(serving)} g. ${amount(quantity)} porciones equivalen a ${amount(quantity * serving)} g.`;
    }
    return `1 porción = ${amount(serving)} g.`;
  });

  readonly preview = computed(() => {
    const target = this.target();
    const quantity = this.quantity();
    if (!target || !(quantity && quantity > 0)) {
      return null;
    }
    if (target.kind === 'food') {
      let grams: number;
      try {
        grams = resolveFoodGrams(quantity, this.unit(), target.food.serving_grams);
      } catch {
        return null;
      }
      return this.nutrition.logEntry({
        kind: 'food',
        quantity: grams,
        unit: 'grams',
        per100: {
          kcal: target.food.kcal_per_100g,
          protein: target.food.protein_per_100g,
          carbs: target.food.carbs_per_100g,
          fat: target.food.fat_per_100g,
        },
      });
    }
    return this.nutrition.logEntry({
      kind: 'recipe',
      quantity,
      unit: this.unit(),
      recipeTotal: {
        kcal: target.recipe.total_kcal,
        protein: target.recipe.total_protein,
        carbs: target.recipe.total_carbs,
        fat: target.recipe.total_fat,
      },
      recipeServings: target.recipe.servings,
      recipeGrams: target.recipe.total_grams,
    });
  });

  constructor() {
    this.queries
      .pipe(
        debounceTime(250),
        distinctUntilChanged(),
        switchMap((query) => {
          this.loading.set(true);
          this.error.set(null);
          return this.foods.search(query).pipe(
            catchError((error) => {
              this.error.set(apiErrorMessage(error, 'No pudimos buscar alimentos.'));
              return of([] as Food[]);
            }),
          );
        }),
        takeUntilDestroyed(),
      )
      .subscribe((foods) => {
        this.results.set(foods);
        this.loading.set(false);
      });
  }

  ngOnInit(): void {
    const params = this.route.snapshot.queryParamMap;
    this.pickMode.set(params.get('pick') === 'ingredient');
    const date = params.get('date');
    if (date) {
      this.diary.date.set(date);
    }
    const meal = params.get('meal');
    if (meal === 'breakfast' || meal === 'lunch' || meal === 'dinner' || meal === 'snack') {
      this.meal.set(meal);
    }
    const foodId = Number(params.get('foodId'));
    if (foodId) {
      this.foods.byId(foodId).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
        next: (food) => this.chooseFood(food),
        error: () => this.lookupMessage.set('No encontramos el alimento que acabas de guardar.'),
      });
    }
    const recipeId = Number(params.get('recipeId'));
    if (recipeId) {
      this.catalog.set('recipes');
      this.recipesApi.get(recipeId).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
        next: (recipe) => this.chooseRecipe(recipe),
        error: () => this.lookupMessage.set('No encontramos esa receta.'),
      });
    }
    this.queries.next('');
    if (!this.pickMode()) {
      this.recipesApi.list().pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
        next: (recipes) => this.recipes.set(recipes),
        error: () => undefined,
      });
    }
  }

  onQuery(event: Event): void {
    const value = inputText(event);
    this.query.set(value);
    this.queries.next(value.trim());
  }

  async scan(): Promise<void> {
    this.scanning.set(true);
    this.lookupMessage.set(null);
    try {
      const code = await this.scanner.scanBarcode();
      this.barcode.set(code);
      await this.lookup(code);
    } catch (error) {
      void this.notify.show(apiErrorMessage(error), 'danger');
    } finally {
      this.scanning.set(false);
    }
  }

  async lookupManual(): Promise<void> {
    await this.lookup(this.barcode());
  }

  chooseFood(food: Food): void {
    if (this.pickMode()) {
      this.picker.choose(food);
      const returnTo = this.route.snapshot.queryParamMap.get('returnTo') || '/recipes/new';
      void this.router.navigateByUrl(returnTo);
      return;
    }
    this.unit.set('grams');
    this.quantity.set(100);
    this.target.set({ kind: 'food', food });
  }

  chooseRecipe(recipe: Recipe): void {
    this.unit.set('portions');
    this.quantity.set(1);
    this.target.set({ kind: 'recipe', recipe });
  }

  clearTarget(): void {
    this.target.set(null);
  }

  setUnit(next: QuantityUnit): void {
    const target = this.target();
    const current = this.unit();
    if (!target || next === current) {
      this.unit.set(next);
      return;
    }
    if (target.kind === 'recipe') {
      this.unit.set(next);
      return;
    }
    const serving = target.food.serving_grams;
    const quantity = this.quantity();
    if (!(serving && serving > 0) || quantity == null) {
      return;
    }
    const converted = next === 'portions' ? quantity / serving : quantity * serving;
    this.quantity.set(Math.round(converted * 100) / 100);
    this.unit.set(next);
  }

  goRecipes(): void {
    void this.router.navigateByUrl('/recipes');
  }

  goOcr(): void {
    void this.router.navigate(['/ocr'], {
      queryParams: {
        barcode: this.barcode() || null,
        date: this.diary.date(),
        meal: this.meal(),
      },
    });
  }

  save(): void {
    const target = this.target();
    const quantity = this.quantity();
    if (!target || !(quantity && quantity > 0)) {
      void this.notify.show('Indica una cantidad mayor a 0.', 'danger');
      return;
    }
    let quantityToSave = quantity;
    let unitToSave = this.unit();
    if (target.kind === 'food') {
      try {
        quantityToSave = resolveFoodGrams(quantity, unitToSave, target.food.serving_grams);
      } catch (error) {
        void this.notify.show(error instanceof Error ? error.message : 'Indica una cantidad mayor a 0.', 'danger');
        return;
      }
      unitToSave = 'grams';
    }
    this.saving.set(true);
    this.tracking
      .add({
        food_id: target.kind === 'food' ? target.food.id : undefined,
        recipe_id: target.kind === 'recipe' ? target.recipe.id : undefined,
        quantity: quantityToSave,
        unit: unitToSave,
        meal_type: this.meal(),
        log_date: this.diary.date(),
      })
      .subscribe({
        next: async () => {
          this.saving.set(false);
          await this.notify.show('Listo, quedó en tu día.', 'success');
          await this.router.navigateByUrl('/dashboard');
        },
        error: (error) => {
          this.saving.set(false);
          void this.notify.show(apiErrorMessage(error), 'danger');
        },
      });
  }

  private async lookup(code: string): Promise<void> {
    const barcode = code.trim();
    if (!barcode) {
      void this.notify.show('Escribe o escanea un código.', 'danger');
      return;
    }
    this.scanning.set(true);
    this.lookupMessage.set(null);
    try {
      const result = await this.scanner.lookup(barcode);
      if (result.status === 'not_found') {
        this.lookupMessage.set('No está en tu catálogo ni en Open Food Facts.');
        return;
      }
      if (result.origin === 'openfoodfacts') {
        void this.notify.show('Lo guardamos desde Open Food Facts.', 'success');
      }
      this.chooseFood(result.food);
    } catch (error) {
      void this.notify.show(apiErrorMessage(error, 'No pudimos consultar el código.'), 'danger');
    } finally {
      this.scanning.set(false);
    }
  }
}
