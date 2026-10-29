import { Component, OnDestroy, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import { Subscription } from 'rxjs';
import { ImportApiService } from '../../services/import-api.service';
import { AuthService } from '../../services/auth.service';
import { ImportModalService } from '../../services/import-modal.service';
import { ImportItem, PaginatedResponse } from '../../models/import.model';

@Component({
  selector: 'app-imports',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './imports.component.html',
  styleUrl: './imports.component.css',
})
export class ImportsComponent implements OnInit, OnDestroy {
  private readonly api = inject(ImportApiService);
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  readonly modalService = inject(ImportModalService);

  readonly importsData = signal<PaginatedResponse<ImportItem> | null>(null);
  readonly isLoadingHistory = signal(false);
  readonly historyError = signal<string | null>(null);
  readonly currentPage = signal(1);
  readonly perPage = signal(15);

  private refreshSub?: Subscription;

  ngOnInit(): void {
    if (!this.auth.isAuthenticated()) {
      this.router.navigate(['/login']);
      return;
    }

    this.loadHistory(1);

    this.refreshSub = this.modalService.refreshHistory$.subscribe(() => {
      this.loadHistory();
    });
  }

  ngOnDestroy(): void {
    this.refreshSub?.unsubscribe();
  }

  loadHistory(page = this.currentPage()): void {
    if (!this.auth.isAuthenticated()) {
      return;
    }

    this.isLoadingHistory.set(true);
    this.historyError.set(null);

    this.api.getImports(page, this.perPage()).subscribe({
      next: (response) => {
        this.isLoadingHistory.set(false);
        this.importsData.set(response);
        this.currentPage.set(response.current_page);
      },
      error: (err) => {
        this.isLoadingHistory.set(false);
        if (err?.status === 401) {
          this.auth.clearSession();
          this.importsData.set(null);
          this.router.navigate(['/login']);
        } else {
          this.historyError.set(
            'Failed to connect to backend API. Please verify the Laravel server is running on http://127.0.0.1:8000.'
          );
        }
      },
    });
  }

  openUploadModal(): void {
    this.modalService.openUpload();
  }

  openDetailModal(importId: number): void {
    this.modalService.openDetail(importId);
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
