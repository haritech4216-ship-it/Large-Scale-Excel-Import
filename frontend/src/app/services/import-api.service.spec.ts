import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { ImportApiService } from './import-api.service';
import {
  ImportDetail,
  ImportItem,
  ImportRowError,
  PaginatedResponse,
  UploadResponse,
} from '../models/import.model';

describe('ImportApiService', () => {
  let service: ImportApiService;
  let httpTesting: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        ImportApiService,
      ],
    });

    service = TestBed.inject(ImportApiService);
    httpTesting = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpTesting.verify();
  });

  it('should be created', () => {
    expect(service).toBeTruthy();
  });

  it('should post file with reportProgress and observe events in upload()', () => {
    const fakeFile = new File(['dummy content'], 'customers.xlsx', {
      type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    });

    const mockResponse: UploadResponse = {
      id: 42,
      filename: 'customers.xlsx',
      status: 'Queued',
      message: 'File uploaded and import queued successfully.',
    };

    let responseResult: UploadResponse | undefined;

    service.upload(fakeFile).subscribe((event: any) => {
      if (event.body) {
        responseResult = event.body;
      }
    });

    const req = httpTesting.expectOne(`${service.baseUrl}/imports`);
    expect(req.request.method).toBe('POST');
    expect(req.request.reportProgress).toBe(true);
    expect(req.request.body instanceof FormData).toBe(true);

    req.flush(mockResponse);
    expect(responseResult).toEqual(mockResponse);
  });

  it('should retrieve single import detail by id', () => {
    const mockDetail: ImportDetail = {
      id: 1,
      filename: 'data.xlsx',
      file_path: 'imports/uuid.xlsx',
      status: 'Processing',
      total_rows: 500,
      processed_rows: 250,
      saved_rows: 240,
      rejected_rows: 10,
      progress_percentage: 50.0,
      started_at: '2026-10-29T12:00:00.000Z',
      completed_at: null,
      duration_seconds: null,
      failure_reason: null,
      created_at: '2026-10-29T11:59:00.000Z',
      updated_at: '2026-10-29T12:00:05.000Z',
    };

    service.getImport(1).subscribe((data) => {
      expect(data).toEqual(mockDetail);
      expect(data.progress_percentage).toBe(50.0);
    });

    const req = httpTesting.expectOne(`${service.baseUrl}/imports/1`);
    expect(req.request.method).toBe('GET');
    req.flush(mockDetail);
  });

  it('should retrieve paginated imports history', () => {
    const mockResponse: PaginatedResponse<ImportItem> = {
      current_page: 2,
      data: [
        {
          id: 1,
          filename: 'customers.xlsx',
          file_path: 'p',
          status: 'Completed',
          total_rows: 100,
          processed_rows: 100,
          saved_rows: 100,
          rejected_rows: 0,
          started_at: '2026-10-29T12:00:00.000Z',
          completed_at: '2026-10-29T12:00:05.000Z',
          duration_seconds: 5.0,
          failure_reason: null,
          created_at: '2026-10-29T11:59:00.000Z',
          updated_at: '2026-10-29T12:00:05.000Z',
        },
      ],
      from: 16,
      last_page: 3,
      per_page: 15,
      to: 16,
      total: 35,
    };

    service.getImports(2, 15).subscribe((res) => {
      expect(res.current_page).toBe(2);
      expect(res.data.length).toBe(1);
      expect(res.total).toBe(35);
    });

    const req = httpTesting.expectOne(`${service.baseUrl}/imports?page=2&per_page=15`);
    expect(req.request.method).toBe('GET');
    req.flush(mockResponse);
  });

  it('should retrieve paginated import errors', () => {
    const mockErrors: PaginatedResponse<ImportRowError> = {
      current_page: 1,
      data: [
        {
          id: 10,
          import_id: 1,
          excel_row: 5,
          customer_id: 'CUST_ERR',
          rejection_reason: 'Invalid email address.',
          created_at: '2026-10-29T12:00:01.000Z',
        },
      ],
      from: 1,
      last_page: 1,
      per_page: 25,
      to: 1,
      total: 1,
    };

    service.getImportErrors(1, 1, 25).subscribe((res) => {
      expect(res.data.length).toBe(1);
      expect(res.data[0].excel_row).toBe(5);
    });

    const req = httpTesting.expectOne(`${service.baseUrl}/imports/1/errors?page=1&per_page=25`);
    expect(req.request.method).toBe('GET');
    req.flush(mockErrors);
  });

  it('should stream errors csv download as a Blob', () => {
    const fakeBlob = new Blob(['Excel Row,Customer ID,Rejection Reason\n2,CUST_1,Error'], {
      type: 'text/csv',
    });

    service.downloadErrorsCsv(1).subscribe((blob) => {
      expect(blob).toBeTruthy();
      expect(blob.size).toBeGreaterThan(0);
    });

    const req = httpTesting.expectOne(`${service.baseUrl}/imports/1/errors.csv`);
    expect(req.request.method).toBe('GET');
    expect(req.request.responseType).toBe('blob');
    req.flush(fakeBlob);
  });

  it('should stream sample template download as an xlsx Blob', () => {
    const fakeBlob = new Blob(['PK\x03\x04fake-xlsx-content'], {
      type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    });

    service.downloadSampleTemplate().subscribe((blob) => {
      expect(blob).toBeTruthy();
      expect(blob.size).toBeGreaterThan(0);
    });

    const req = httpTesting.expectOne(`${service.baseUrl}/imports/sample-template`);
    expect(req.request.method).toBe('GET');
    expect(req.request.responseType).toBe('blob');
    req.flush(fakeBlob);
  });
});
