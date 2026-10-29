import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { CreateDailyLog, DailyLog, DailySummary } from '../models/daily-log.model';
import { ApiService } from './api.service';

@Injectable({ providedIn: 'root' })
export class TrackingService {
  private readonly api = inject(ApiService);

  list(date: string): Observable<DailyLog[]> {
    return this.api.get<DailyLog[]>('/logs', { date });
  }

  summary(date: string): Observable<DailySummary> {
    return this.api.get<DailySummary>('/logs/summary', { date });
  }

  add(entry: CreateDailyLog): Observable<DailyLog> {
    return this.api.post<DailyLog>('/logs', entry);
  }

  remove(id: number): Observable<{ deleted: boolean }> {
    return this.api.delete<{ deleted: boolean }>(`/logs/${id}`);
  }
}
