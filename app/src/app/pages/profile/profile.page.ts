import { Component, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { IonButton, IonContent, IonHeader, IonIcon, IonInput, IonTitle, IonToolbar } from '@ionic/angular';
import { ACTIVITIES } from '../../core/constants';
import { ActivityLevel } from '../../core/models/nutrition';
import { AuthService } from '../../core/services/auth.service';
import { NotifyService } from '../../core/services/notify.service';
import { NutritionService } from '../../core/services/nutrition.service';
import { apiErrorMessage, formatGrams, formatKcal, inputNumber, inputText } from '../../core/utils/format';

@Component({
  selector: 'app-profile',
  templateUrl: './profile.page.html',
  imports: [IonHeader, IonToolbar, IonTitle, IonContent, IonInput, IonButton, IonIcon],
})
export class ProfilePage {
  private readonly auth = inject(AuthService);
  private readonly nutrition = inject(NutritionService);
  private readonly notify = inject(NotifyService);
  private readonly router = inject(Router);

  readonly activities = ACTIVITIES;
  readonly user = this.auth.user;
  readonly name = signal('');
  readonly weight = signal<number | null>(null);
  readonly activity = signal<ActivityLevel>('moderate');
  readonly custom = signal(false);
  readonly proteinPct = signal<number | null>(30);
  readonly carbsPct = signal<number | null>(45);
  readonly fatPct = signal<number | null>(25);
  readonly busy = signal(false);
  readonly text = inputText;
  readonly number = inputNumber;
  readonly kcal = formatKcal;
  readonly grams = formatGrams;

  readonly percentSum = computed(() => (this.proteinPct() ?? 0) + (this.carbsPct() ?? 0) + (this.fatPct() ?? 0));

  readonly preview = computed(() => {
    const user = this.user();
    const weight = this.weight();
    if (!user?.gender || user.age == null || user.height_cm == null || weight == null) {
      return null;
    }
    const bmr = this.nutrition.bmr(user.gender, weight, user.height_cm, user.age);
    const tdee = this.nutrition.tdee(bmr, this.activity());
    if (!this.custom()) {
      return { bmr, tdee, goals: this.nutrition.goals(tdee, weight) };
    }
    const protein = this.proteinPct();
    const carbs = this.carbsPct();
    const fat = this.fatPct();
    if (protein == null || carbs == null || fat == null) {
      return { bmr, tdee, goals: null };
    }
    try {
      return { bmr, tdee, goals: this.nutrition.goals(tdee, weight, { protein, carbs, fat }) };
    } catch {
      return { bmr, tdee, goals: null };
    }
  });

  ionViewWillEnter(): void {
    const user = this.user();
    if (!user) {
      return;
    }
    this.name.set(user.name);
    this.weight.set(user.weight_kg);
    this.activity.set(user.activity_level ?? 'moderate');
    const custom = user.protein_pct != null && user.carbs_pct != null && user.fat_pct != null;
    this.custom.set(custom);
    if (custom) {
      this.proteinPct.set(user.protein_pct);
      this.carbsPct.set(user.carbs_pct);
      this.fatPct.set(user.fat_pct);
    }
  }

  activityLabel(id: ActivityLevel | null): string {
    return ACTIVITIES.find((item) => item.id === id)?.label ?? 'Sin definir';
  }

  saveBody(): void {
    const weight = this.weight();
    if (weight == null) {
      void this.notify.show('Escribe tu peso en kilogramos.', 'danger');
      return;
    }
    this.busy.set(true);
    this.auth.updateProfile({ name: this.name().trim(), weight_kg: weight, activity_level: this.activity() }).subscribe({
      next: () => {
        this.busy.set(false);
        void this.notify.show('Metas recalculadas con tu peso nuevo.', 'success');
      },
      error: (error) => {
        this.busy.set(false);
        void this.notify.show(apiErrorMessage(error), 'danger');
      },
    });
  }

  saveMacros(): void {
    if (!this.custom()) {
      this.busy.set(true);
      this.auth.updateMacros({ reset: true }).subscribe({
        next: () => {
          this.busy.set(false);
          void this.notify.show('Volviste al reparto automático.', 'success');
        },
        error: (error) => {
          this.busy.set(false);
          void this.notify.show(apiErrorMessage(error), 'danger');
        },
      });
      return;
    }
    const protein = this.proteinPct();
    const carbs = this.carbsPct();
    const fat = this.fatPct();
    if (protein == null || carbs == null || fat == null || Math.abs(protein + carbs + fat - 100) > 0.05) {
      void this.notify.show('Los tres porcentajes deben sumar 100.', 'danger');
      return;
    }
    this.busy.set(true);
    this.auth.updateMacros({ protein_pct: protein, carbs_pct: carbs, fat_pct: fat }).subscribe({
      next: () => {
        this.busy.set(false);
        void this.notify.show('Porcentajes guardados.', 'success');
      },
      error: (error) => {
        this.busy.set(false);
        void this.notify.show(apiErrorMessage(error), 'danger');
      },
    });
  }

  logout(): void {
    this.auth.clearSession();
    void this.router.navigateByUrl('/login');
  }
}
