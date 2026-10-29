import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter, Router } from '@angular/router';
import { of, throwError } from 'rxjs';
import { LoginComponent } from './login.component';
import { AuthService } from '../../services/auth.service';

describe('LoginComponent', () => {
  let component: LoginComponent;
  let fixture: ComponentFixture<LoginComponent>;
  let authService: AuthService;
  let router: Router;

  beforeEach(async () => {
    localStorage.clear();
    await TestBed.configureTestingModule({
      imports: [LoginComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        AuthService,
      ],
    }).compileComponents();

    authService = TestBed.inject(AuthService);
    router = TestBed.inject(Router);

    fixture = TestBed.createComponent(LoginComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  afterEach(() => {
    localStorage.clear();
  });

  it('should create the login component', () => {
    expect(component).toBeTruthy();
  });

  it('should render the login card with empty fields initially', () => {
    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.querySelector('.section-title')?.textContent).toContain('Sign In to Excel Importer');
    expect(component.loginEmail()).toBe('');
    expect(component.loginPassword()).toBe('');
  });

  it('should redirect to /imports in ngOnInit if already authenticated', () => {
    const navigateSpy = vi.spyOn(router, 'navigate');
    authService.setSession('token-123', { id: 1, name: 'Admin', email: 'admin@example.com' });

    component.ngOnInit();
    expect(navigateSpy).toHaveBeenCalledWith(['/imports']);
  });

  it('should set error if email or password is empty onLogin', () => {
    component.loginEmail.set('');
    component.loginPassword.set('');

    component.onLogin();

    expect(component.loginError()).toBe('Please provide both email and password.');
  });

  it('should call auth.login and navigate to /imports on successful login', () => {
    const navigateSpy = vi.spyOn(router, 'navigate').mockResolvedValue(true);
    vi.spyOn(authService, 'login').mockReturnValue(
      of({
        token: 'token-xyz',
        user: { id: 1, name: 'Admin', email: 'admin@example.com' },
        message: 'Login successful.',
      })
    );

    component.loginEmail.set('admin@example.com');
    component.loginPassword.set('Password123!');

    component.onLogin();

    expect(authService.login).toHaveBeenCalledWith('admin@example.com', 'Password123!');
    expect(navigateSpy).toHaveBeenCalledWith(['/imports']);
    expect(component.isLoggingIn()).toBe(false);
  });

  it('should display error message on login failure', () => {
    vi.spyOn(authService, 'login').mockReturnValue(
      throwError(() => ({ error: { message: 'Invalid email or password.' } }))
    );

    component.loginEmail.set('wrong@example.com');
    component.loginPassword.set('badpass');

    component.onLogin();

    expect(component.loginError()).toBe('Invalid email or password.');
    expect(component.isLoggingIn()).toBe(false);
  });
});
