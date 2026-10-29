import { Component, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { IonButton, IonContent, IonInput } from '@ionic/angular';
import { ACTIVITIES } from '../../core/constants';
import { ActivityLevel, Gender } from '../../core/models/nutrition';
import { AuthService } from '../../core/services/auth.service';
import { NotifyService } from '../../core/services/notify.service';
import { NutritionService } from '../../core/services/nutrition.service';
import { apiErrorMessage, formatGrams, formatKcal, inputNumber } from '../../core/utils/format';

@Component({
  selector: 'app-onboarding',
  templateUrl: './onboarding.page.html',
  imports: [IonContent, IonButton, IonInput],
})
export class OnboardingPage {
  private readonly auth = inject(AuthService);
  private readonly nutrition = inject(NutritionService);
  private readonly router = inject(Router);
  private readonly notify = inject(NotifyService);

  readonly activities = ACTIVITIES;
  readonly step = signal(0);
  readonly gender = signal<Gender | null>(null);
  readonly age = signal<number | null>(null);
  readonly weight = signal<number | null>(null);
  readonly height = signal<number | null>(null);
  readonly activity = signal<ActivityLevel | null>(null);
  readonly busy = signal(false);
  readonly number = inputNumber;
  readonly kcal = formatKcal;
  readonly grams = formatGrams;

  readonly preview = computed(() => {
    const gender = this.gender();
    const age = this.age();
    const weight = this.weight();
    const height = this.height();
    const activity = this.activity();
    if (!gender || age == null || weight == null || height == null || !activity) {
      return null;
    }
    const bmr = this.nutrition.bmr(gender, weight, height, age);
    const tdee = this.nutrition.tdee(bmr, activity);
    return { bmr, tdee, goals: this.nutrition.goals(tdee, weight) };
  });

  next(): void {
    if (!this.valid()) {
      void this.notify.show('Completa este paso para continuar.', 'danger');
      return;
    }
    this.step.update((value) => Math.min(5, value + 1));
  }

  back(): void {
    this.step.update((value) => Math.max(0, value - 1));
  }

  save(): void {
    const gender = this.gender();
    const age = this.age();
    const weight = this.weight();
    const height = this.height();
    const activity = this.activity();
    if (!gender || age == null || weight == null || height == null || !activity) {
      return;
    }
    this.busy.set(true);
    this.auth
      .saveOnboarding({ gender, age, weight_kg: weight, height_cm: height, activity_level: activity })
      .subscribe({
        next: () => {
          this.busy.set(false);
          void this.router.navigateByUrl('/dashboard');
        },
        error: (error) => {
          this.busy.set(false);
          void this.notify.show(apiErrorMessage(error), 'danger');
        },
      });
  }

  leave(): void {
    this.auth.clearSession();
    void this.router.navigateByUrl('/login');
  }

  private valid(): boolean {
    switch (this.step()) {
      case 0:
        return this.gender() !== null;
      case 1:
        return (this.age() ?? 0) >= 10 && (this.age() ?? 0) <= 120;
      case 2:
        return (this.weight() ?? 0) >= 25 && (this.weight() ?? 0) <= 400;
      case 3:
        return (this.height() ?? 0) >= 80 && (this.height() ?? 0) <= 250;
      case 4:
        return this.activity() !== null;
      default:
        return true;
    }
  }
}
