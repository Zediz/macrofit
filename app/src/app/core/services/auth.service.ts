import { Injectable, inject, signal } from '@angular/core';
import { Observable, map, tap } from 'rxjs';
import { AuthResponse, User } from '../models/user.model';
import { ApiService } from './api.service';
import { MACROFIT_TOKEN_KEY } from './auth.interceptor';

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly api = inject(ApiService);
  readonly token = signal<string | null>(localStorage.getItem(MACROFIT_TOKEN_KEY));
  readonly user = signal<User | null>(null);

  register(payload: { name: string; email: string; password: string }): Observable<User> {
    return this.api.post<AuthResponse>('/auth/register', payload).pipe(
      tap((response) => this.persist(response)),
      map((response) => response.user),
    );
  }

  login(payload: { email: string; password: string }): Observable<User> {
    return this.api.post<AuthResponse>('/auth/login', payload).pipe(
      tap((response) => this.persist(response)),
      map((response) => response.user),
    );
  }

  loadProfile(): Observable<User> {
    return this.api.get<User>('/profile').pipe(tap((user) => this.user.set(user)));
  }

  saveOnboarding(payload: {
    gender: string;
    age: number;
    weight_kg: number;
    height_cm: number;
    activity_level: string;
  }): Observable<User> {
    return this.api.post<User>('/profile/onboarding', payload).pipe(tap((user) => this.user.set(user)));
  }

  updateProfile(payload: Record<string, unknown>): Observable<User> {
    return this.api.put<User>('/profile', payload).pipe(tap((user) => this.user.set(user)));
  }

  updateMacros(payload: Record<string, unknown>): Observable<User> {
    return this.api.put<User>('/profile/macros', payload).pipe(tap((user) => this.user.set(user)));
  }

  clearSession(): void {
    localStorage.removeItem(MACROFIT_TOKEN_KEY);
    this.token.set(null);
    this.user.set(null);
  }

  private persist(response: AuthResponse): void {
    localStorage.setItem(MACROFIT_TOKEN_KEY, response.token);
    this.token.set(response.token);
    this.user.set(response.user);
  }
}
