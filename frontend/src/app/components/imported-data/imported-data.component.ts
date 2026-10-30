import { Component, OnInit, inject, signal, computed } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ImportApiService } from '../../services/import-api.service';
import { ImportedDataResponse } from '../../models/import.model';

@Component({
  selector: 'app-imported-data',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './imported-data.component.html',
  styleUrls: ['./imported-data.component.css'],
})
export class ImportedDataComponent implements OnInit {
  private readonly apiService = inject(ImportApiService);

  // Table state
  readonly importedData = signal<ImportedDataResponse | null>(null);
  readonly isLoading = signal<boolean>(false);
  readonly searchQuery = signal<string>('');
  readonly errorMessage = signal<string | null>(null);
  readonly successMessage = signal<string | null>(null);
  readonly currentPage = signal<number>(1);
  readonly pageSize = signal<number>(15);

  // Delete All Dialog State
  readonly isDeleteDialogOpen = signal<boolean>(false);
  readonly confirmationInput = signal<string>('');
  readonly isDeleting = signal<boolean>(false);
  readonly deleteError = signal<string | null>(null);

  // Computed state
  readonly totalEligibleCount = computed(
    () => this.importedData()?.total_eligible_count ?? 0
  );

  readonly isConfirmButtonEnabled = computed(
    () =>
      this.confirmationInput().trim() === 'DELETE ALL' &&
      !this.isDeleting() &&
      this.totalEligibleCount() > 0
  );

  ngOnInit(): void {
    this.loadData();
  }

  loadData(page: number = this.currentPage()): void {
    this.isLoading.set(true);
    this.errorMessage.set(null);
    this.currentPage.set(page);

    this.apiService
      .getImportedData(page, this.pageSize(), this.searchQuery())
      .subscribe({
        next: (data) => {
          this.importedData.set(data);
          this.isLoading.set(false);
        },
        error: (err) => {
          this.isLoading.set(false);
          this.errorMessage.set(
            err.error?.message ||
              'Unable to load imported customer records from server.'
          );
        },
      });
  }

  onSearchChange(term: string): void {
    this.searchQuery.set(term);
    this.loadData(1);
  }

  clearSearch(): void {
    this.searchQuery.set('');
    this.loadData(1);
  }

  openDeleteDialog(): void {
    this.confirmationInput.set('');
    this.deleteError.set(null);
    this.isDeleteDialogOpen.set(true);
  }

  closeDeleteDialog(): void {
    if (!this.isDeleting()) {
      this.isDeleteDialogOpen.set(false);
      this.confirmationInput.set('');
      this.deleteError.set(null);
    }
  }

  confirmDeleteAll(): void {
    if (!this.isConfirmButtonEnabled()) {
      return;
    }

    this.isDeleting.set(true);
    this.deleteError.set(null);

    this.apiService.deleteAllImportedData('DELETE ALL').subscribe({
      next: (res) => {
        this.isDeleting.set(false);
        this.isDeleteDialogOpen.set(false);
        this.confirmationInput.set('');
        this.successMessage.set(res.message);

        // Refresh the table and record count after successful deletion
        this.loadData(1);
      },
      error: (err) => {
        this.isDeleting.set(false);
        this.deleteError.set(
          err.error?.message ||
            'Failed to execute delete operation. Please try again.'
        );
      },
    });
  }
}
