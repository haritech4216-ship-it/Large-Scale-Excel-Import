import { TestBed } from '@angular/core/testing';
import {
  HttpClientTestingModule,
  HttpTestingController,
} from '@angular/common/http/testing';
import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { AuthService } from './auth.service';
import { authInterceptor } from '../interceptors/auth.interceptor';

describe('AuthService', () => {
  let service: AuthService;
  let httpTesting: HttpTestingController;

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        AuthService,
      ],
    });

    service = TestBed.inject(AuthService);
    httpTesting = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpTesting.verify();
    localStorage.clear();
  });

  it('should be created and start unauthenticated when localStorage is empty', () => {
    expect(service).toBeTruthy();
    expect(service.isAuthenticated()).toBe(false);
    expect(service.token()).toBeNull();
    expect(service.currentUser()).toBeNull();
  });

  it('should set session and authenticate on successful login', () => {
    const mockResponse = {
      token: 'token-abc-123',
      user: { id: 1, name: 'Admin', email: 'admin@example.com' },
      message: 'Login successful.',
    };

    service.login('admin@example.com', 'secret').subscribe((res) => {
      expect(res.token).toBe('token-abc-123');
      expect(service.isAuthenticated()).toBe(true);
      expect(service.token()).toBe('token-abc-123');
      expect(service.currentUser()?.email).toBe('admin@example.com');
      expect(localStorage.getItem('excel_import_token')).toBe('token-abc-123');
    });

    const req = httpTesting.expectOne(`${service.baseUrl}/auth/login`);
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({
      email: 'admin@example.com',
      password: 'secret',
    });
    req.flush(mockResponse);
  });

  it('should clear session on logout', () => {
    service.setSession('token-to-clear', {
      id: 2,
      name: 'User 2',
      email: 'user2@example.com',
    });
    expect(service.isAuthenticated()).toBe(true);

    service.logout().subscribe(() => {
      expect(service.isAuthenticated()).toBe(false);
      expect(service.token()).toBeNull();
      expect(service.currentUser()).toBeNull();
      expect(localStorage.getItem('excel_import_token')).toBeNull();
    });

    const req = httpTesting.expectOne(`${service.baseUrl}/auth/logout`);
    expect(req.request.method).toBe('POST');
    req.flush({ message: 'Successfully logged out.' });
  });

  it('should clear session immediately on clearSession() call', () => {
    service.setSession('token-active', {
      id: 3,
      name: 'User 3',
      email: 'user3@example.com',
    });
    expect(service.isAuthenticated()).toBe(true);

    service.clearSession();

    expect(service.isAuthenticated()).toBe(false);
    expect(service.token()).toBeNull();
    expect(service.currentUser()).toBeNull();
  });
});

describe('authInterceptor', () => {
  let httpClient: HttpClient;
  let httpTesting: HttpTestingController;
  let authService: AuthService;

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
        provideRouter([{ path: 'login', component: class {} }]),
        AuthService,
      ],
    });

    httpClient = TestBed.inject(HttpClient);
    httpTesting = TestBed.inject(HttpTestingController);
    authService = TestBed.inject(AuthService);
  });

  afterEach(() => {
    httpTesting.verify();
    localStorage.clear();
  });

  it('should inject Authorization Bearer header when token is present', () => {
    authService.setSession('sample-auth-token-123', {
      id: 1,
      name: 'Tester',
      email: 'tester@example.com',
    });

    httpClient.get('/api/imports').subscribe();

    const req = httpTesting.expectOne('/api/imports');
    expect(req.request.headers.has('Authorization')).toBe(true);
    expect(req.request.headers.get('Authorization')).toBe('Bearer sample-auth-token-123');
    req.flush({ data: [] });
  });

  it('should NOT inject Authorization header when token is null', () => {
    authService.clearSession();

    httpClient.get('/api/imports').subscribe();

    const req = httpTesting.expectOne('/api/imports');
    expect(req.request.headers.has('Authorization')).toBe(false);
    req.flush({ data: [] });
  });

  it('should clear session when response returns 401 Unauthorized', () => {
    authService.setSession('invalid-token', {
      id: 1,
      name: 'Tester',
      email: 'tester@example.com',
    });
    expect(authService.isAuthenticated()).toBe(true);

    httpClient.get('/api/imports').subscribe({
      next: () => expect.fail('should have failed with 401'),
      error: (err) => {
        expect(err.status).toBe(401);
        expect(authService.isAuthenticated()).toBe(false);
      },
    });

    const req = httpTesting.expectOne('/api/imports');
    req.flush('Unauthorized', { status: 401, statusText: 'Unauthorized' });
  });
});
