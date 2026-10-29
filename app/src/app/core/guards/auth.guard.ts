import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { catchError, map, of } from 'rxjs';
import { AuthService } from '../services/auth.service';

export const authGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  if (!auth.token()) {
    return inject(Router).createUrlTree(['/login']);
  }
  return true;
};

export const readyGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  const router = inject(Router);
  const current = auth.user();
  const user$ = current ? of(current) : auth.loadProfile();
  return user$.pipe(
    map((user) => (user.onboarding_complete ? true : router.createUrlTree(['/onboarding']))),
    catchError(() => {
      auth.clearSession();
      return of(router.createUrlTree(['/login']));
    }),
  );
};

export const guestGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  if (auth.token()) {
    return inject(Router).createUrlTree(['/dashboard']);
  }
  return true;
};
