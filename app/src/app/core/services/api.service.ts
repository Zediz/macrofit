import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';

@Injectable({ providedIn: 'root' })
export class ApiService {
  private readonly http = inject(HttpClient);

  get<T>(path: string, params?: Record<string, string | number | null | undefined>): Observable<T> {
    let httpParams = new HttpParams();
    for (const [key, value] of Object.entries(params ?? {})) {
      if (value !== undefined && value !== null && value !== '') {
        httpParams = httpParams.set(key, String(value));
      }
    }
    return this.http.get<unknown>(this.url(path), { params: httpParams }).pipe(map((body) => unwrap<T>(body)));
  }

  post<T>(path: string, body: unknown): Observable<T> {
    return this.http.post<unknown>(this.url(path), body).pipe(map((response) => unwrap<T>(response)));
  }

  put<T>(path: string, body: unknown): Observable<T> {
    return this.http.put<unknown>(this.url(path), body).pipe(map((response) => unwrap<T>(response)));
  }

  delete<T>(path: string): Observable<T> {
    return this.http.delete<unknown>(this.url(path)).pipe(map((response) => unwrap<T>(response)));
  }

  private url(path: string): string {
    return `${environment.apiBaseUrl.replace(/\/$/, '')}${path}`;
  }
}

function unwrap<T>(response: unknown): T {
  if (Array.isArray(response)) {
    return response as T;
  }
  if (response && typeof response === 'object' && 'success' in response) {
    const envelope = response as { success: boolean; body: T };
    if (!envelope.success) {
      const message = typeof envelope.body === 'string' ? envelope.body : 'Error de la API';
      throw new Error(message);
    }
    return envelope.body;
  }
  return response as T;
}
