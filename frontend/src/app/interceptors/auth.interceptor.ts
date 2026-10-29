import { HttpInterceptorFn, HttpErrorResponse } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { AuthService } from '../services/auth.service';

export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const authService = inject(AuthService);
  const router = inject(Router, { optional: true });
  const token = authService.token();

  let authReq = req;
  if (token && req.url.includes('/api')) {
    authReq = req.clone({
      setHeaders: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
    });
  }

  return next(authReq).pipe(
    catchError((error: unknown) => {
      if (error instanceof HttpErrorResponse && error.status === 401) {
        // Clear session on 401 unauthorized to prompt re-login
        authService.clearSession();
        router?.navigate(['/login']).catch(() => {});
      }
      return throwError(() => error);
    })
  );
};
