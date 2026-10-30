import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter, Router } from '@angular/router';
import { of, throwError } from 'rxjs';
import { ImportsComponent } from './imports.component';
import { ImportApiService } from '../../services/import-api.service';
import { AuthService } from '../../services/auth.service';
import { ImportModalService } from '../../services/import-modal.service';
import { PaginatedResponse, ImportItem } from '../../models/import.model';

describe('ImportsComponent', () => {
  let component: ImportsComponent;
  let fixture: ComponentFixture<ImportsComponent>;
  let apiService: ImportApiService;
  let authService: AuthService;
  let modalService: ImportModalService;
  let router: Router;

  const mockImports: PaginatedResponse<ImportItem> = {
    current_page: 1,
    data: [
      {
        id: 1,
        filename: 'batch_alpha.xlsx',
        file_path: 'imports/uuid.xlsx',
        status: 'Completed',
        total_rows: 500,
        processed_rows: 500,
        saved_rows: 500,
        rejected_rows: 0,
        started_at: '2026-10-29T12:00:00.000Z',
        completed_at: '2026-10-29T12:00:05.000Z',
        duration_seconds: 5.2,
        failure_reason: null,
        created_at: '2026-10-29T11:59:00.000Z',
        updated_at: '2026-10-29T12:00:05.000Z',
      },
    ],
    from: 1,
    last_page: 1,
    per_page: 15,
    to: 1,
    total: 1,
  };

  beforeEach(async () => {
    localStorage.clear();
    await TestBed.configureTestingModule({
      imports: [ImportsComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        ImportApiService,
        AuthService,
        ImportModalService,
      ],
    }).compileComponents();

    authService = TestBed.inject(AuthService);
    apiService = TestBed.inject(ImportApiService);
    modalService = TestBed.inject(ImportModalService);
    router = TestBed.inject(Router);

    authService.setSession('mock-token-xyz', {
      id: 1,
      name: 'Admin',
      email: 'admin@example.com',
    });

    vi.spyOn(apiService, 'getImports').mockReturnValue(of(mockImports));

    fixture = TestBed.createComponent(ImportsComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  afterEach(() => {
    localStorage.clear();
  });

  it('should create the imports component', () => {
    expect(component).toBeTruthy();
  });

  it('should redirect to /login in ngOnInit if unauthenticated', () => {
    authService.clearSession();
    const navigateSpy = vi.spyOn(router, 'navigate');

    component.ngOnInit();
    expect(navigateSpy).toHaveBeenCalledWith(['/login']);
  });

  it('should load and render import history records when authenticated', () => {
    expect(component.importsData()).toEqual(mockImports);
    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.querySelector('.history-row')?.textContent).toContain('batch_alpha.xlsx');
  });

  it('should trigger modalService.openUpload on openUploadModal', () => {
    const uploadSpy = vi.spyOn(modalService, 'openUpload');
    component.openUploadModal();
    expect(uploadSpy).toHaveBeenCalled();
  });

  it('should trigger modalService.openDetail on openDetailModal', () => {
    const detailSpy = vi.spyOn(modalService, 'openDetail');
    component.openDetailModal(1);
    expect(detailSpy).toHaveBeenCalledWith(1);
  });

  it('should reload history when modalService emits refreshHistory$', () => {
    const loadSpy = vi.spyOn(component, 'loadHistory');
    modalService.refreshHistory$.next();
    expect(loadSpy).toHaveBeenCalled();
  });

  it('should clear session and redirect to /login on 401 response', () => {
    const navigateSpy = vi.spyOn(router, 'navigate');
    vi.spyOn(apiService, 'getImports').mockReturnValue(
      throwError(() => ({ status: 401 }))
    );

    component.loadHistory(1);

    expect(authService.isAuthenticated()).toBe(false);
    expect(component.importsData()).toBeNull();
    expect(navigateSpy).toHaveBeenCalledWith(['/login']);
  });

  it('should map status to corresponding CSS class', () => {
    expect(component.getStatusClass('Completed')).toBe('status-completed');
    expect(component.getStatusClass('Completed with Errors')).toBe('status-warning');
    expect(component.getStatusClass('Processing')).toBe('status-processing');
    expect(component.getStatusClass('Queued')).toBe('status-queued');
    expect(component.getStatusClass('Failed')).toBe('status-failed');
    expect(component.getStatusClass('Unknown')).toBe('status-default');
  });

  it('should display started_at and completed_at timestamps in history table', () => {
    const compiled = fixture.nativeElement as HTMLElement;
    const historyRow = compiled.querySelector('.history-row');
    const timestampCells = historyRow?.querySelectorAll('.timestamp-cell');
    expect(timestampCells?.length).toBe(3); // created_at, started_at, completed_at
    // started_at cell should have formatted time, not fallback dash
    expect(timestampCells?.[1]?.textContent?.trim()).not.toBe('—');
    // completed_at cell should have formatted time, not fallback dash
    expect(timestampCells?.[2]?.textContent?.trim()).not.toBe('—');
  });

  it('should display fallback dash for null timestamps and duration', () => {
    const mockWithNulls: PaginatedResponse<ImportItem> = {
      current_page: 1,
      data: [
        {
          id: 2,
          filename: 'queued_batch.xlsx',
          file_path: 'imports/uuid2.xlsx',
          status: 'Queued',
          total_rows: 100,
          processed_rows: 0,
          saved_rows: 0,
          rejected_rows: 0,
          started_at: null,
          completed_at: null,
          duration_seconds: null,
          failure_reason: null,
          created_at: '2026-10-29T12:10:00.000Z',
          updated_at: '2026-10-29T12:10:00.000Z',
        },
      ],
      from: 1,
      last_page: 1,
      per_page: 15,
      to: 1,
      total: 1,
    };

    component.importsData.set(mockWithNulls);
    fixture.detectChanges();

    const compiled = fixture.nativeElement as HTMLElement;
    const historyRow = compiled.querySelector('.history-row');
    const timestampCells = historyRow?.querySelectorAll('.timestamp-cell');
    expect(timestampCells?.[1]?.textContent?.trim()).toBe('—');
    expect(timestampCells?.[2]?.textContent?.trim()).toBe('—');
    const durationCell = historyRow?.querySelector('.duration-cell');
    expect(durationCell?.textContent?.trim()).toBe('—');
  });

  it('should display processing duration correctly when present', () => {
    const compiled = fixture.nativeElement as HTMLElement;
    const historyRow = compiled.querySelector('.history-row');
    const durationCell = historyRow?.querySelector('.duration-cell');
    expect(durationCell?.textContent?.trim()).toBe('5.2s');
  });
});
