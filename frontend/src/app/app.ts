import { Component, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router, RouterOutlet } from '@angular/router';
import { AuthService } from './services/auth.service';
import { ImportModalService } from './services/import-modal.service';
import { ImportModalComponent } from './components/import-modal/import-modal.component';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [CommonModule, RouterOutlet, ImportModalComponent],
  templateUrl: './app.html',
  styleUrl: './app.css',
})
export class App {
  private readonly router = inject(Router);
  readonly auth = inject(AuthService);
  readonly modalService = inject(ImportModalService);

  protected readonly title = signal('Excel Import Engine');

  // Modal signals bound to ImportModalService
  readonly isModalOpen = this.modalService.isOpen;
  readonly activeModalImportId = this.modalService.activeImportId;

  onLogout(): void {
    this.auth.logout().subscribe({
      next: () => {
        this.router.navigate(['/login']);
      },
      error: () => {
        this.router.navigate(['/login']);
      },
    });
  }

  openUploadModal(): void {
    this.modalService.openUpload();
  }

  openDetailModal(importId: number): void {
    this.modalService.openDetail(importId);
  }

  onModalClosed(): void {
    this.modalService.close();
  }

  onImportCreated(newId: number): void {
    this.modalService.notifyImportCreated(newId);
  }
}
