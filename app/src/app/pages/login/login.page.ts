import { Component, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { IonButton, IonContent, IonInput, IonLabel, IonSegment, IonSegmentButton, IonSpinner } from '@ionic/angular';
import { AuthService } from '../../core/services/auth.service';
import { NotifyService } from '../../core/services/notify.service';
import { apiErrorMessage, inputText } from '../../core/utils/format';

@Component({
  selector: 'app-login',
  templateUrl: './login.page.html',
  imports: [IonContent, IonSegment, IonSegmentButton, IonLabel, IonInput, IonButton, IonSpinner],
})
export class LoginPage {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly notify = inject(NotifyService);

  readonly mode = signal<'login' | 'register'>('login');
  readonly name = signal('');
  readonly email = signal('');
  readonly password = signal('');
  readonly busy = signal(false);
  readonly text = inputText;

  setMode(event: Event): void {
    const value = (event as CustomEvent<{ value?: string }>).detail.value;
    if (value === 'login' || value === 'register') {
      this.mode.set(value);
    }
  }

  submit(): void {
    const email = this.email().trim().toLowerCase();
    const password = this.password();
    const name = this.name().trim();
    if (this.mode() === 'register' && name.length < 2) {
      void this.notify.show('Escribe tu nombre.', 'danger');
      return;
    }
    if (!email.includes('@') || password.length < 8) {
      void this.notify.show('Usa un correo válido y una contraseña de al menos 8 caracteres.', 'danger');
      return;
    }
    this.busy.set(true);
    const request =
      this.mode() === 'register'
        ? this.auth.register({ name, email, password })
        : this.auth.login({ email, password });
    request.subscribe({
      next: (user) => {
        this.busy.set(false);
        void this.router.navigateByUrl(user.onboarding_complete ? '/dashboard' : '/onboarding');
      },
      error: (error) => {
        this.busy.set(false);
        void this.notify.show(apiErrorMessage(error, 'No pudimos entrar. Revisa tus datos.'), 'danger');
      },
    });
  }
}
