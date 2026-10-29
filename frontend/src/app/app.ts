import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ImportApiService } from './services/import-api.service';
import { ImportItem, PaginatedResponse } from './models/import.model';
import { ImportModalComponent } from './components/import-modal/import-modal.component';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [CommonModule, FormsModule, ImportModalComponent],
  templateUrl: './app.html',
  styleUrl: './app.css',
})
export class App implements OnInit {
  private readonly api = inject(ImportApiService);

  protected readonly title = signal('Excel Import Engine');

  // History state
  readonly importsData = signal<PaginatedResponse<ImportItem> | null>(null);
  readonly isLoadingHistory = signal(false);
  readonly historyError = signal<string | null>(null);
  readonly currentPage = signal(1);
  readonly perPage = signal(15);

  // Modal state
  readonly isModalOpen = signal(false);
  readonly activeModalImportId = signal<number | null>(null);

  ngOnInit(): void {
    this.loadHistory(1);
  }

  loadHistory(page = this.currentPage()): void {
    this.isLoadingHistory.set(true);
    this.historyError.set(null);

    this.api.getImports(page, this.perPage()).subscribe({
      next: (response) => {
        this.isLoadingHistory.set(false);
        this.importsData.set(response);
        this.currentPage.set(response.current_page);
      },
      error: () => {
        this.isLoadingHistory.set(false);
        this.historyError.set(
          'Failed to connect to backend API. Please verify the Laravel server is running on http://127.0.0.1:8000.'
        );
      },
    });
  }

  onPerPageChange(newPerPage: number): void {
    this.perPage.set(newPerPage);
    this.loadHistory(1);
  }

  openUploadModal(): void {
    this.activeModalImportId.set(null);
    this.isModalOpen.set(true);
  }

  openDetailModal(importId: number): void {
    this.activeModalImportId.set(importId);
    this.isModalOpen.set(true);
  }

  onModalClosed(): void {
    this.isModalOpen.set(false);
    this.activeModalImportId.set(null);
    this.loadHistory();
  }

  onImportCreated(newId: number): void {
    this.loadHistory(1);
  }

  getStatusClass(status: string): string {
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
