import { Injectable } from '@angular/core';
import { Food } from '../models/food.model';

@Injectable({ providedIn: 'root' })
export class FoodPickerService {
  private chosen: Food | null = null;

  choose(food: Food): void {
    this.chosen = food;
  }

  take(): Food | null {
    const food = this.chosen;
    this.chosen = null;
    return food;
  }
}
