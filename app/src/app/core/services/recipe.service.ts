import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { Recipe, RecipePayload } from '../models/recipe.model';
import { ApiService } from './api.service';

@Injectable({ providedIn: 'root' })
export class RecipeService {
  private readonly api = inject(ApiService);

  list(): Observable<Recipe[]> {
    return this.api.get<Recipe[]>('/recipes');
  }

  get(id: number): Observable<Recipe> {
    return this.api.get<Recipe>(`/recipes/${id}`);
  }

  create(payload: RecipePayload): Observable<Recipe> {
    return this.api.post<Recipe>('/recipes', payload);
  }

  update(id: number, payload: RecipePayload): Observable<Recipe> {
    return this.api.put<Recipe>(`/recipes/${id}`, payload);
  }

  remove(id: number): Observable<{ deleted: boolean }> {
    return this.api.delete<{ deleted: boolean }>(`/recipes/${id}`);
  }
}
