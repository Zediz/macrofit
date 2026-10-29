import { Injectable, inject } from '@angular/core';
import { ToastController } from '@ionic/angular';

@Injectable({ providedIn: 'root' })
export class NotifyService {
  private readonly toast = inject(ToastController);

  async show(message: string, color: 'success' | 'danger' | 'dark' = 'dark'): Promise<void> {
    const toast = await this.toast.create({
      message,
      duration: 2800,
      color,
      position: 'top',
    });
    await toast.present();
  }
}
