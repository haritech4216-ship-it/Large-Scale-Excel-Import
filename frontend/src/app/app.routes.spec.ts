import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter, Router } from '@angular/router';
import { of } from 'rxjs';
import { routes } from './app.routes';
import { AuthService } from './services/auth.service';
import { ImportApiService } from './services/import-api.service';
import { ImportModalService } from './services/import-modal.service';

describe('App Routing Integration', () => {
  let router: Router;
  let authService: AuthService;

  beforeEach(async () => {
    localStorage.clear();
    await TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter(routes),
        AuthService,
        ImportApiService,
        ImportModalService,
      ],
    }).compileComponents();

    router = TestBed.inject(Router);
    authService = TestBed.inject(AuthService);
    const apiService = TestBed.inject(ImportApiService);
    vi.spyOn(apiService, 'getImports').mockReturnValue(
      of({ current_page: 1, data: [], from: 1, last_page: 1, per_page: 15, to: 0, total: 0 })
    );
    vi.spyOn(apiService, 'getImportedData').mockReturnValue(
      of({ current_page: 1, data: [], from: 1, last_page: 1, per_page: 15, to: 0, total: 0, total_eligible_count: 0 })
    );
  });

  afterEach(() => {
    localStorage.clear();
  });

  it('should redirect unauthenticated navigation from / to /login', async () => {
    authService.clearSession();
    await router.navigate(['/']);
    expect(router.url).toBe('/login');
  });

  it('should redirect unauthenticated navigation from /imports to /login', async () => {
    authService.clearSession();
    await router.navigate(['/imports']);
    expect(router.url).toBe('/login');
  });

  it('should redirect authenticated navigation from / to /imports', async () => {
    authService.setSession('test-token', { id: 1, name: 'Admin', email: 'admin@example.com' });
    await router.navigate(['/']);
    expect(router.url).toBe('/imports');
  });

  it('should redirect authenticated navigation from /login to /imports', async () => {
    authService.setSession('test-token', { id: 1, name: 'Admin', email: 'admin@example.com' });
    await router.navigate(['/login']);
    expect(router.url).toBe('/imports');
  });

  it('should allow authenticated navigation directly to /imports', async () => {
    authService.setSession('test-token', { id: 1, name: 'Admin', email: 'admin@example.com' });
    await router.navigate(['/imports']);
    expect(router.url).toBe('/imports');
  });

  it('should redirect unknown routes to /login when unauthenticated', async () => {
    authService.clearSession();
    await router.navigate(['/some-random-route']);
    expect(router.url).toBe('/login');
  });

  it('should redirect unauthenticated navigation from /imported-data to /login', async () => {
    authService.clearSession();
    await router.navigate(['/imported-data']);
    expect(router.url).toBe('/login');
  });

  it('should allow authenticated navigation directly to /imported-data', async () => {
    authService.setSession('test-token', { id: 1, name: 'Admin', email: 'admin@example.com' });
    await router.navigate(['/imported-data']);
    expect(router.url).toBe('/imported-data');
  });
});
