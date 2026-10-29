import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpEvent, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import {
  ImportDetail,
  ImportItem,
  ImportRowError,
  PaginatedResponse,
  UploadResponse,
} from '../models/import.model';

@Injectable({
  providedIn: 'root',
})
export class ImportApiService {
  private readonly http = inject(HttpClient);

  readonly baseUrl =
    typeof window !== 'undefined' &&
    (window.location.port === '4200' || window.location.hostname === 'localhost')
      ? 'http://127.0.0.1:8000/api'
      : '/api';

  upload(file: File): Observable<HttpEvent<UploadResponse>> {
    const formData = new FormData();
    formData.append('file', file, file.name);

    return this.http.post<UploadResponse>(`${this.baseUrl}/imports`, formData, {
      reportProgress: true,
      observe: 'events',
    });
  }

  getImport(id: number): Observable<ImportDetail> {
    return this.http.get<ImportDetail>(`${this.baseUrl}/imports/${id}`);
  }

  getImports(page = 1, perPage = 15): Observable<PaginatedResponse<ImportItem>> {
    const params = new HttpParams()
      .set('page', page.toString())
      .set('per_page', perPage.toString());

    return this.http.get<PaginatedResponse<ImportItem>>(`${this.baseUrl}/imports`, {
      params,
    });
  }

  getImportErrors(
    id: number,
    page = 1,
    perPage = 25
  ): Observable<PaginatedResponse<ImportRowError>> {
    const params = new HttpParams()
      .set('page', page.toString())
      .set('per_page', perPage.toString());

    return this.http.get<PaginatedResponse<ImportRowError>>(
      `${this.baseUrl}/imports/${id}/errors`,
      { params }
    );
  }

  downloadErrorsCsv(id: number): Observable<Blob> {
    return this.http.get(`${this.baseUrl}/imports/${id}/errors.csv`, {
      responseType: 'blob',
    });
  }

  downloadSampleTemplate(): Observable<Blob> {
    return this.http.get(`${this.baseUrl}/imports/sample-template`, {
      responseType: 'blob',
    });
  }
}
