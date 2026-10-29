import { Component, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { AlertController } from '@ionic/angular';
import { IonContent, IonFab, IonFabButton, IonHeader, IonIcon, IonTitle, IonToolbar } from '@ionic/angular';
import { forkJoin } from 'rxjs';
import { MEALS, RING_CIRCUMFERENCE } from '../../core/constants';
import { DailyLog, DailySummary } from '../../core/models/daily-log.model';
import { MealType } from '../../core/models/nutrition';
import { DiaryState } from '../../core/services/diary.service';
import { TrackingService } from '../../core/services/tracking.service';
import { apiErrorMessage, clampPercent, formatGrams, formatKcal, formatLongDate, shiftIsoDate } from '../../core/utils/format';

@Component({
  selector: 'app-dashboard',
  templateUrl: './dashboard.page.html',
  imports: [IonHeader, IonToolbar, IonTitle, IonContent, IonFab, IonFabButton, IonIcon],
})
export class DashboardPage {
  private readonly tracking = inject(TrackingService);
  private readonly router = inject(Router);
  private readonly alerts = inject(AlertController);
  readonly diary = inject(DiaryState);

  readonly loading = signal(true);
  readonly error = signal<string | null>(null);
  readonly summary = signal<DailySummary | null>(null);
  readonly logs = signal<DailyLog[]>([]);
  readonly circumference = RING_CIRCUMFERENCE;
  readonly kcal = formatKcal;
  readonly grams = formatGrams;

  readonly title = computed(() => formatLongDate(this.diary.date()));
  readonly grouped = computed(() =>
    MEALS.map((meal) => {
      const items = this.logs().filter((log) => log.meal_type === meal.id);
      return {
        ...meal,
        items,
        kcal: items.reduce((sum, item) => sum + item.kcal, 0),
      };
    }),
  );

  ionViewWillEnter(): void {
    this.load();
  }

  shift(days: number): void {
    this.diary.date.update((date) => shiftIsoDate(date, days));
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.error.set(null);
    const date = this.diary.date();
    forkJoin({
      summary: this.tracking.summary(date),
      logs: this.tracking.list(date),
    }).subscribe({
      next: ({ summary, logs }) => {
        this.summary.set(summary);
        this.logs.set(logs);
        this.loading.set(false);
      },
      error: (error) => {
        this.error.set(apiErrorMessage(error, 'No pudimos cargar tu día.'));
        this.loading.set(false);
      },
    });
  }

  add(meal?: MealType): void {
    void this.router.navigate(['/add-food'], {
      queryParams: { date: this.diary.date(), meal: meal ?? 'breakfast' },
    });
  }

  ringOffset(consumed: number, goal: number): number {
    const ratio = goal > 0 ? Math.min(consumed / goal, 1) : 0;
    return this.circumference * (1 - ratio);
  }

  bar(consumed: number, goal: number): number {
    return clampPercent(consumed, goal);
  }

  remainingText(remaining: number, unit: string): string {
    const amount = unit === 'kcal' ? this.kcal(Math.abs(remaining)) : this.grams(Math.abs(remaining));
    return remaining >= 0 ? `Te faltan ${amount} ${unit}` : `Te pasaste ${amount} ${unit}`;
  }

  async remove(log: DailyLog): Promise<void> {
    const alert = await this.alerts.create({
      header: 'Quitar del día',
      message: `¿Quitamos ${log.name}?`,
      buttons: [
        { text: 'Cancelar', role: 'cancel' },
        { text: 'Quitar', role: 'destructive' },
      ],
    });
    await alert.present();
    const { role } = await alert.onDidDismiss();
    if (role !== 'destructive') {
      return;
    }
    this.tracking.remove(log.id).subscribe({
      next: () => this.load(),
      error: (error) => this.error.set(apiErrorMessage(error, 'No pudimos quitar el registro.')),
    });
  }
}
