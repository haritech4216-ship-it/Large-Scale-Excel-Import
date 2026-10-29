import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter, Router } from '@angular/router';
import { of } from 'rxjs';
import { App } from './app';
import { routes } from './app.routes';
import { AuthService } from './services/auth.service';
import { ImportModalService } from './services/import-modal.service';

describe('App', () => {
  let component: App;
  let fixture: ComponentFixture<App>;
  let authService: AuthService;
  let modalService: ImportModalService;
  let router: Router;

  beforeEach(async () => {
    localStorage.clear();
    await TestBed.configureTestingModule({
      imports: [App],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter(routes),
        AuthService,
        ImportModalService,
      ],
    }).compileComponents();

    authService = TestBed.inject(AuthService);
    modalService = TestBed.inject(ImportModalService);
    router = TestBed.inject(Router);

    authService.setSession('mock-token-xyz', {
      id: 1,
      name: 'Admin',
      email: 'admin@example.com',
    });

    fixture = TestBed.createComponent(App);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  afterEach(() => {
    localStorage.clear();
  });

  it('should create the app', () => {
    expect(component).toBeTruthy();
  });

  it('should render the brand title', () => {
    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.querySelector('.brand-title')?.textContent).toContain('Excel Import Engine');
  });

  it('should render user pill and upload button when authenticated', () => {
    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.querySelector('.user-pill')?.textContent).toContain('admin@example.com');
    expect(compiled.querySelector('.btn-primary')?.textContent).toContain('+ Upload Spreadsheet');
  });

  it('should open modal in upload mode when clicking + Upload Spreadsheet', () => {
    component.openUploadModal();
    expect(modalService.isOpen()).toBe(true);
    expect(modalService.activeImportId()).toBeNull();
  });

  it('should open modal in detail mode when calling openDetailModal', () => {
    component.openDetailModal(42);
    expect(modalService.isOpen()).toBe(true);
    expect(modalService.activeImportId()).toBe(42);
  });

  it('should close modal on modal closed event', () => {
    component.openUploadModal();
    expect(modalService.isOpen()).toBe(true);

    component.onModalClosed();
    expect(modalService.isOpen()).toBe(false);
    expect(modalService.activeImportId()).toBeNull();
  });

  it('should call auth.logout and navigate to /login on logout', () => {
    const navigateSpy = vi.spyOn(router, 'navigate').mockResolvedValue(true);
    vi.spyOn(authService, 'logout').mockImplementation(() => {
      authService.clearSession();
      return of({ message: 'Logged out.' });
    });

    component.onLogout();

    expect(authService.isAuthenticated()).toBe(false);
    expect(navigateSpy).toHaveBeenCalledWith(['/login']);
  });

  it('should not render user pill or upload button when unauthenticated', () => {
    authService.clearSession();
    fixture.detectChanges();

    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.querySelector('.user-pill')).toBeNull();
  });
});
