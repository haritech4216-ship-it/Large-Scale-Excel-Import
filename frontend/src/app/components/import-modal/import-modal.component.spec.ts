import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ImportModalComponent } from './import-modal.component';
import { ImportApiService } from '../../services/import-api.service';
import { ImportDetail } from '../../models/import.model';
import { of, throwError } from 'rxjs';

describe('ImportModalComponent', () => {
  let component: ImportModalComponent;
  let fixture: ComponentFixture<ImportModalComponent>;
  let apiService: ImportApiService;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ImportModalComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        ImportApiService,
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(ImportModalComponent);
    component = fixture.componentInstance;
    apiService = TestBed.inject(ImportApiService);
    fixture.detectChanges();
  });

  afterEach(() => {
    component.stopPolling();
  });

  it('should create the modal component', () => {
    expect(component).toBeTruthy();
  });

  it('should reject files with unsupported extensions', () => {
    const invalidFile = new File(['text'], 'sample.csv', { type: 'text/csv' });
    const event = {
      target: {
        files: [invalidFile],
      },
    } as unknown as Event;

    component.onFileSelected(event);

    expect(component.selectedFile()).toBeNull();
    expect(component.fileValidationError()).toContain('Only .xlsx files are supported');
  });

  it('should reject files exceeding 100 MB limit', () => {
    // 105 MB file
    const oversizedFile = new File([''], 'huge.xlsx', {
      type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    });
    Object.defineProperty(oversizedFile, 'size', { value: 110000000 });

    const event = {
      target: {
        files: [oversizedFile],
      },
    } as unknown as Event;

    component.onFileSelected(event);

    expect(component.selectedFile()).toBeNull();
    expect(component.fileValidationError()).toContain('exceeds the maximum allowed limit of 100 MB');
  });

  it('should accept valid .xlsx files under 100 MB', () => {
    const validFile = new File(['binary'], 'customers.xlsx', {
      type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    });
    Object.defineProperty(validFile, 'size', { value: 5000000 }); // 5 MB

    const event = {
      target: {
        files: [validFile],
      },
    } as unknown as Event;

    component.onFileSelected(event);

    expect(component.selectedFile()).toBe(validFile);
    expect(component.fileValidationError()).toBeNull();
  });

  it('should display import details and stop polling on terminal Completed status', () => {
    const mockDetail: ImportDetail = {
      id: 99,
      filename: 'customers.xlsx',
      file_path: 'imports/uuid.xlsx',
      status: 'Completed',
      total_rows: 1000,
      processed_rows: 1000,
      saved_rows: 1000,
      rejected_rows: 0,
      progress_percentage: 100.0,
      started_at: '2026-10-29T12:00:00.000Z',
      completed_at: '2026-10-29T12:00:10.000Z',
      duration_seconds: 10.0,
      failure_reason: null,
      created_at: '2026-10-29T11:59:00.000Z',
      updated_at: '2026-10-29T12:00:10.000Z',
    };

    vi.spyOn(apiService, 'getImport').mockReturnValue(of(mockDetail));
    const stopSpy = vi.spyOn(component, 'stopPolling');

    component.loadImportDetails(99);

    expect(component.importDetail()).toEqual(mockDetail);
    expect(component.importDetail()?.status).toBe('Completed');
    expect(stopSpy).toHaveBeenCalled();
  });

  it('should load error records if rejected_rows > 0', () => {
    const mockDetailWithErrors: ImportDetail = {
      id: 88,
      filename: 'customers.xlsx',
      file_path: 'imports/uuid.xlsx',
      status: 'Completed with Errors',
      total_rows: 100,
      processed_rows: 100,
      saved_rows: 95,
      rejected_rows: 5,
      progress_percentage: 100.0,
      started_at: '2026-10-29T12:00:00.000Z',
      completed_at: '2026-10-29T12:00:10.000Z',
      duration_seconds: 10.0,
      failure_reason: null,
      created_at: '2026-10-29T11:59:00.000Z',
      updated_at: '2026-10-29T12:00:10.000Z',
    };

    vi.spyOn(apiService, 'getImport').mockReturnValue(of(mockDetailWithErrors));
    const loadErrorsSpy = vi.spyOn(component, 'loadErrors');

    component.loadImportDetails(88);

    expect(component.importDetail()?.rejected_rows).toBe(5);
    expect(loadErrorsSpy).toHaveBeenCalledWith(88, 1);
  });

  it('should emit closed event on close()', () => {
    const closeSpy = vi.spyOn(component.closed, 'emit');

    component.close();

    expect(closeSpy).toHaveBeenCalled();
  });

  it('should correctly render three-stage progress data with valid, invalid, and duplicate counts', () => {
    const mockDetailWithStages: ImportDetail = {
      id: 77,
      filename: 'pipeline.xlsx',
      file_path: 'imports/pipeline.xlsx',
      status: 'Processing',
      total_rows: 5000,
      total_rows_known: true,
      processed_rows: 2500,
      validated_rows: 2500,
      valid_rows: 2400,
      invalid_rows: 60,
      duplicate_rows: 40,
      saved_rows: 2400,
      rejected_rows: 100,
      progress_percentage: 50.0,
      stages: {
        upload: { status: 'completed', percentage: 100.0 },
        validation: {
          status: 'processing',
          checked_rows: 2500,
          total_rows: 5000,
          valid_rows: 2400,
          invalid_rows: 60,
          duplicate_rows: 40,
          percentage: 50.0,
          indeterminate: false,
        },
        import: {
          status: 'processing',
          saved_rows: 2400,
          eligible_rows: 2400,
          percentage: 99.9,
          indeterminate: false,
        },
      },
      started_at: '2026-10-29T12:00:00.000Z',
      completed_at: null,
      duration_seconds: null,
      failure_reason: null,
      created_at: '2026-10-29T11:59:00.000Z',
      updated_at: '2026-10-29T12:00:10.000Z',
    };

    vi.spyOn(apiService, 'getImport').mockReturnValue(of(mockDetailWithStages));
    component.loadImportDetails(77);
    fixture.detectChanges();

    expect(component.getStageStatusClass('upload')).toBe('stage-completed');
    expect(component.getStageStatusClass('validation')).toBe('stage-active');
    expect(component.getStageStatusClass('import')).toBe('stage-active');
    expect(component.getValidationBadgeText()).toBe('50%');
    expect(component.getImportBadgeText()).toBe('99.9%');

    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.textContent).toContain('Stage 1');
    expect(compiled.textContent).toContain('Stage 2');
    expect(compiled.textContent).toContain('Stage 3');
    expect(compiled.textContent).toContain('2,500');
    expect(compiled.textContent).toContain('2,400');
  });

  it('should display indeterminate state when total rows is unknown', () => {
    const mockDetailUnknownTotal: ImportDetail = {
      id: 66,
      filename: 'streaming.xlsx',
      file_path: 'imports/streaming.xlsx',
      status: 'Processing',
      total_rows: 0,
      total_rows_known: false,
      processed_rows: 800,
      validated_rows: 800,
      valid_rows: 750,
      invalid_rows: 30,
      duplicate_rows: 20,
      saved_rows: 750,
      rejected_rows: 50,
      progress_percentage: 0.0,
      stages: {
        upload: { status: 'completed', percentage: 100.0 },
        validation: {
          status: 'processing',
          checked_rows: 800,
          total_rows: 0,
          valid_rows: 750,
          invalid_rows: 30,
          duplicate_rows: 20,
          percentage: 0.0,
          indeterminate: true,
        },
        import: {
          status: 'processing',
          saved_rows: 750,
          eligible_rows: 750,
          percentage: 0.0,
          indeterminate: false,
        },
      },
      started_at: '2026-10-29T12:00:00.000Z',
      completed_at: null,
      duration_seconds: null,
      failure_reason: null,
      created_at: '2026-10-29T11:59:00.000Z',
      updated_at: '2026-10-29T12:00:10.000Z',
    };

    vi.spyOn(apiService, 'getImport').mockReturnValue(of(mockDetailUnknownTotal));
    component.loadImportDetails(66);
    fixture.detectChanges();

    expect(component.getValidationBadgeText()).toBe('Scanning Rows...');
    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.querySelector('.fill-indeterminate')).toBeTruthy();
  });

  it('should render Download Sample Excel button in the upload view', () => {
    fixture.detectChanges();
    const compiled = fixture.nativeElement as HTMLElement;
    const downloadBtn = compiled.querySelector('.btn-download-template');
    expect(downloadBtn).toBeTruthy();
    expect(downloadBtn?.textContent).toContain('Download Sample Excel');
  });

  it('should call downloadSampleTemplate() and create download link on click', () => {
    const fakeBlob = new Blob(['sample-xlsx-content'], {
      type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    });
    vi.spyOn(apiService, 'downloadSampleTemplate').mockReturnValue(of(fakeBlob));
    const createUrlSpy = vi.spyOn(window.URL, 'createObjectURL').mockReturnValue('blob:mock-url');
    const revokeUrlSpy = vi.spyOn(window.URL, 'revokeObjectURL').mockImplementation(() => {});

    component.downloadSampleTemplate();

    expect(apiService.downloadSampleTemplate).toHaveBeenCalled();
    expect(createUrlSpy).toHaveBeenCalledWith(fakeBlob);
    expect(revokeUrlSpy).toHaveBeenCalledWith('blob:mock-url');
    expect(component.isDownloadingTemplate()).toBe(false);
    expect(component.templateDownloadError()).toBeNull();
  });

  it('should set templateDownloadError when sample template download fails', () => {
    vi.spyOn(apiService, 'downloadSampleTemplate').mockReturnValue(
      throwError(() => new Error('Network error'))
    );

    component.downloadSampleTemplate();

    expect(component.isDownloadingTemplate()).toBe(false);
    expect(component.templateDownloadError()).toBe('Failed to download sample template.');

    fixture.detectChanges();
    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.textContent).toContain('Failed to download sample template.');
  });
});
