import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from '../services/auth.service';

/**
 * Protects authenticated routes (e.g. /imports).
 * Redirects unauthenticated users to /login.
 */
export const authGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  const router = inject(Router);

  if (auth.isAuthenticated()) {
    return true;
  }

  return router.createUrlTree(['/login']);
};

/**
 * Protects unauthenticated routes (e.g. /login).
 * Redirects already authenticated users to /imports.
 */
export const guestGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  const router = inject(Router);

  if (auth.isAuthenticated()) {
    return router.createUrlTree(['/imports']);
  }

  return true;
};

/**
 * Handles root / navigation:
 * Redirects authenticated users to /imports and unauthenticated users to /login.
 */
export const rootGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  const router = inject(Router);

  if (auth.isAuthenticated()) {
    return router.createUrlTree(['/imports']);
  }

  return router.createUrlTree(['/login']);
};
