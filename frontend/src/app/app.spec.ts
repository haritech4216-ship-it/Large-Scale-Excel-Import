import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { of } from 'rxjs';
import { App } from './app';
import { ImportApiService } from './services/import-api.service';
import { PaginatedResponse, ImportItem } from './models/import.model';

describe('App', () => {
  let component: App;
  let fixture: ComponentFixture<App>;
  let apiService: ImportApiService;

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
    await TestBed.configureTestingModule({
      imports: [App],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        ImportApiService,
      ],
    }).compileComponents();

    apiService = TestBed.inject(ImportApiService);
    vi.spyOn(apiService, 'getImports').mockReturnValue(of(mockImports));

    fixture = TestBed.createComponent(App);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create the app', () => {
    expect(component).toBeTruthy();
  });

  it('should render the brand title', () => {
    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.querySelector('.brand-title')?.textContent).toContain('Excel Import Engine');
  });

  it('should load and render import history records', () => {
    expect(component.importsData()).toEqual(mockImports);
    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.querySelector('.history-row')?.textContent).toContain('batch_alpha.xlsx');
  });

  it('should open modal in upload mode when clicking + Upload Spreadsheet', () => {
    component.openUploadModal();
    expect(component.isModalOpen()).toBe(true);
    expect(component.activeModalImportId()).toBeNull();
  });

  it('should open modal in detail mode when clicking an import item', () => {
    component.openDetailModal(1);
    expect(component.isModalOpen()).toBe(true);
    expect(component.activeModalImportId()).toBe(1);
  });

  it('should close modal and reload history on modal closed event', () => {
    component.openUploadModal();
    const loadSpy = vi.spyOn(component, 'loadHistory');

    component.onModalClosed();

    expect(component.isModalOpen()).toBe(false);
    expect(component.activeModalImportId()).toBeNull();
    expect(loadSpy).toHaveBeenCalled();
  });
});
