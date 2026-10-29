import { TestBed } from '@angular/core/testing';
import { provideRouter, Router, UrlTree } from '@angular/router';
import { authGuard, guestGuard, rootGuard } from './auth.guard';
import { AuthService } from '../services/auth.service';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';

describe('Auth Guards', () => {
  let authService: AuthService;
  let router: Router;

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        AuthService,
      ],
    });

    authService = TestBed.inject(AuthService);
    router = TestBed.inject(Router);
  });

  afterEach(() => {
    localStorage.clear();
  });

  describe('authGuard', () => {
    it('should allow access when authenticated', () => {
      authService.setSession('valid-token', {
        id: 1,
        name: 'User',
        email: 'user@example.com',
      });

      const result = TestBed.runInInjectionContext(() =>
        authGuard({} as any, {} as any)
      );

      expect(result).toBe(true);
    });

    it('should redirect to /login when unauthenticated', () => {
      authService.clearSession();

      const result = TestBed.runInInjectionContext(() =>
        authGuard({} as any, {} as any)
      );

      expect(result instanceof UrlTree).toBe(true);
      expect((result as UrlTree).toString()).toBe('/login');
    });
  });

  describe('guestGuard', () => {
    it('should allow access to /login when unauthenticated', () => {
      authService.clearSession();

      const result = TestBed.runInInjectionContext(() =>
        guestGuard({} as any, {} as any)
      );

      expect(result).toBe(true);
    });

    it('should redirect to /imports when authenticated user visits /login', () => {
      authService.setSession('valid-token', {
        id: 1,
        name: 'User',
        email: 'user@example.com',
      });

      const result = TestBed.runInInjectionContext(() =>
        guestGuard({} as any, {} as any)
      );

      expect(result instanceof UrlTree).toBe(true);
      expect((result as UrlTree).toString()).toBe('/imports');
    });
  });

  describe('rootGuard', () => {
    it('should redirect unauthenticated users from / to /login', () => {
      authService.clearSession();

      const result = TestBed.runInInjectionContext(() =>
        rootGuard({} as any, {} as any)
      );

      expect(result instanceof UrlTree).toBe(true);
      expect((result as UrlTree).toString()).toBe('/login');
    });

    it('should redirect authenticated users from / to /imports', () => {
      authService.setSession('valid-token', {
        id: 1,
        name: 'User',
        email: 'user@example.com',
      });

      const result = TestBed.runInInjectionContext(() =>
        rootGuard({} as any, {} as any)
      );

      expect(result instanceof UrlTree).toBe(true);
      expect((result as UrlTree).toString()).toBe('/imports');
    });
  });
});
