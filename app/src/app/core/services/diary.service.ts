import { Injectable, signal } from '@angular/core';
import { isoDate } from '../utils/format';

@Injectable({ providedIn: 'root' })
export class DiaryState {
  readonly date = signal(isoDate());
}
