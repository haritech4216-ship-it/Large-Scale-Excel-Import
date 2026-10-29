import {
  Component,
  EventEmitter,
  Input,
  OnDestroy,
  OnInit,
  Output,
  inject,
  signal,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { HttpEventType } from '@angular/common/http';
import { Subscription } from 'rxjs';
import { ImportApiService } from '../../services/import-api.service';
import {
  ImportDetail,
  ImportRowError,
  PaginatedResponse,
} from '../../models/import.model';

@Component({
  selector: 'app-import-modal',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './import-modal.component.html',
  styleUrl: './import-modal.component.css',
})
export class ImportModalComponent implements OnInit, OnDestroy {
  private readonly api = inject(ImportApiService);

  @Input() importId: number | null = null;
  @Output() closed = new EventEmitter<void>();
  @Output() importCreated = new EventEmitter<number>();

  // State signals
  readonly selectedFile = signal<File | null>(null);
  readonly fileValidationError = signal<string | null>(null);
  readonly isUploading = signal(false);
  readonly uploadProgress = signal<number>(0);
  readonly uploadError = signal<string | null>(null);

  readonly importDetail = signal<ImportDetail | null>(null);
  readonly isLoadingDetail = signal(false);
  readonly detailError = signal<string | null>(null);

  // Errors state
  readonly errorsPage = signal<number>(1);
  readonly errorsPerPage = signal<number>(10);
  readonly errorsData = signal<PaginatedResponse<ImportRowError> | null>(null);
  readonly isLoadingErrors = signal(false);
  readonly isDownloadingCsv = signal(false);
  readonly csvDownloadError = signal<string | null>(null);

  private pollSubscription?: Subscription;
  private isPollingActive = false;

  ngOnInit(): void {
    if (this.importId) {
      this.loadImportDetails(this.importId);
      this.startPolling(this.importId);
    }
  }

  ngOnDestroy(): void {
    this.stopPolling();
  }

  // --- Upload Handlers ---

  onFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    if (!input.files || input.files.length === 0) {
      return;
    }

    const file = input.files[0];
    this.validateAndSetFile(file);
  }

  onFileDrop(event: DragEvent): void {
    event.preventDefault();
    if (!event.dataTransfer || !event.dataTransfer.files.length) {
      return;
    }

    const file = event.dataTransfer.files[0];
    this.validateAndSetFile(file);
  }

  onDragOver(event: DragEvent): void {
    event.preventDefault();
  }

  private validateAndSetFile(file: File): void {
    this.fileValidationError.set(null);
    this.uploadError.set(null);

    // Validate extension
    const ext = file.name.split('.').pop()?.toLowerCase();
    if (ext !== 'xlsx') {
      this.selectedFile.set(null);
      this.fileValidationError.set('Only .xlsx files are supported.');
      return;
    }

    // Validate size (100 MB = 104,857,600 bytes)
    const maxBytes = 104857600;
    if (file.size > maxBytes) {
      this.selectedFile.set(null);
      this.fileValidationError.set(
        `File size (${this.formatBytes(file.size)}) exceeds the maximum allowed limit of 100 MB.`
      );
      return;
    }

    this.selectedFile.set(file);
  }

  startUpload(): void {
    const file = this.selectedFile();
    if (!file || this.isUploading()) {
      return;
    }

    this.isUploading.set(true);
    this.uploadProgress.set(0);
    this.uploadError.set(null);

    this.api.upload(file).subscribe({
      next: (event) => {
        if (event.type === HttpEventType.UploadProgress && event.total) {
          const progress = Math.round((100 * event.loaded) / event.total);
          this.uploadProgress.set(progress);
        } else if (event.type === HttpEventType.Response) {
          const response = event.body;
          if (response && response.id) {
            this.isUploading.set(false);
            this.importId = response.id;
            this.importCreated.emit(response.id);
            this.loadImportDetails(response.id);
            this.startPolling(response.id);
          }
        }
      },
      error: (err) => {
        this.isUploading.set(false);
        let errorMsg = 'Upload failed. Please check the file and try again.';
        if (err.error?.message) {
          errorMsg = err.error.message;
        }
        if (err.error?.errors?.file) {
          errorMsg = err.error.errors.file.join(' ');
        }
        this.uploadError.set(errorMsg);
      },
    });
  }

  // --- Import Status & Polling ---

  loadImportDetails(id: number): void {
    this.isLoadingDetail.set(true);
    this.detailError.set(null);

    this.api.getImport(id).subscribe({
      next: (detail) => {
        this.isLoadingDetail.set(false);
        this.importDetail.set(detail);

        // If completed with errors or has rejected rows, load error list
        if (detail.rejected_rows > 0) {
          this.loadErrors(id, this.errorsPage());
        }

        // Stop polling if terminal state reached
        if (this.isTerminalStatus(detail.status)) {
          this.stopPolling();
        }
      },
      error: (err) => {
        this.isLoadingDetail.set(false);
        this.detailError.set(
          err.status === 404
            ? `Import #${id} not found.`
            : 'Failed to load import status from server.'
        );
      },
    });
  }

  startPolling(id: number): void {
    this.stopPolling();

    // Poll every 1.5 seconds safely without overlapping requests
    this.pollSubscription = new Subscription();
    const intervalId = setInterval(() => {
      if (this.isPollingActive) {
        return;
      }

      this.isPollingActive = true;
      this.api.getImport(id).subscribe({
        next: (detail) => {
          this.isPollingActive = false;
          this.importDetail.set(detail);

          if (detail.rejected_rows > 0 && !this.errorsData()) {
            this.loadErrors(id, 1);
          }

          if (this.isTerminalStatus(detail.status)) {
            this.stopPolling();
            if (detail.rejected_rows > 0) {
              this.loadErrors(id, 1);
            }
          }
        },
        error: () => {
          this.isPollingActive = false;
        },
      });
    }, 1500);

    this.pollSubscription.add(() => clearInterval(intervalId));
  }

  stopPolling(): void {
    if (this.pollSubscription) {
      this.pollSubscription.unsubscribe();
      this.pollSubscription = undefined;
    }
    this.isPollingActive = false;
  }

  private isTerminalStatus(status: string): boolean {
    return (
      status === 'Completed' ||
      status === 'Completed with Errors' ||
      status === 'Failed'
    );
  }

  // --- Row Errors & CSV Download ---

  loadErrors(importId: number, page: number): void {
    this.isLoadingErrors.set(true);
    this.csvDownloadError.set(null);

    this.api.getImportErrors(importId, page, this.errorsPerPage()).subscribe({
      next: (data) => {
        this.isLoadingErrors.set(false);
        this.errorsData.set(data);
        this.errorsPage.set(data.current_page);
      },
      error: () => {
        this.isLoadingErrors.set(false);
      },
    });
  }

  onPageChange(newPage: number): void {
    if (!this.importId) return;
    this.loadErrors(this.importId, newPage);
  }

  downloadCsv(): void {
    if (!this.importId || this.isDownloadingCsv()) {
      return;
    }

    this.isDownloadingCsv.set(true);
    this.csvDownloadError.set(null);

    const targetId = this.importId;
    this.api.downloadErrorsCsv(targetId).subscribe({
      next: (blob) => {
        this.isDownloadingCsv.set(false);
        const url = window.URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = `import_${targetId}_errors.csv`;
        anchor.click();
        window.URL.revokeObjectURL(url);
      },
      error: () => {
        this.isDownloadingCsv.set(false);
        this.csvDownloadError.set('Failed to download error CSV report.');
      },
    });
  }

  close(): void {
    this.stopPolling();
    this.closed.emit();
  }

  // --- Utilities ---

  formatBytes(bytes: number): string {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return `${parseFloat((bytes / Math.pow(k, i)).toFixed(2))} ${sizes[i]}`;
  }

  getStatusClass(status?: string): string {
    switch (status) {
      case 'Completed':
        return 'status-completed';
      case 'Completed with Errors':
        return 'status-warning';
      case 'Processing':
        return 'status-processing';
      case 'Queued':
        return 'status-queued';
      case 'Failed':
        return 'status-failed';
      default:
        return 'status-default';
    }
  }
}
