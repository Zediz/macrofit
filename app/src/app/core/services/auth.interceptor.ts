import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { environment } from '../../../environments/environment';

const TOKEN_KEY = 'macrofit_token';

export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const isApi = req.url.startsWith(environment.apiBaseUrl);
  const token = localStorage.getItem(TOKEN_KEY);
  if (isApi && token) {
    req = req.clone({ setHeaders: { Authorization: `Bearer ${token}` } });
  }
  const router = inject(Router);
  return next(req).pipe(
    catchError((error: HttpErrorResponse) => {
      const isAuthAttempt = req.url.includes('/auth/login') || req.url.includes('/auth/register');
      if (isApi && error.status === 401 && !isAuthAttempt) {
        localStorage.removeItem(TOKEN_KEY);
        void router.navigateByUrl('/login');
      }
      return throwError(() => error);
    }),
  );
};

export const MACROFIT_TOKEN_KEY = TOKEN_KEY;
