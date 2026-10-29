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
});
