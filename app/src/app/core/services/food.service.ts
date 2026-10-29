import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { CreateFood, Food } from '../models/food.model';
import { ApiService } from './api.service';

@Injectable({ providedIn: 'root' })
export class FoodService {
  private readonly api = inject(ApiService);

  search(query: string): Observable<Food[]> {
    return this.api.get<Food[]>('/foods/search', { q: query });
  }

  byBarcode(barcode: string): Observable<Food> {
    return this.api.get<Food>(`/foods/barcode/${encodeURIComponent(barcode)}`);
  }

  byId(id: number): Observable<Food> {
    return this.api.get<Food>(`/foods/${id}`);
  }

  create(payload: CreateFood): Observable<Food> {
    return this.api.post<Food>('/foods', payload);
  }
}
