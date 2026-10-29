import { Injectable, signal } from '@angular/core';
import { Subject } from 'rxjs';

@Injectable({
  providedIn: 'root',
})
export class ImportModalService {
  readonly isOpen = signal(false);
  readonly activeImportId = signal<number | null>(null);

  /**
   * Notification stream for components (e.g. ImportsComponent) to reload history
   * when an import is created or the modal dialog is closed.
   */
  readonly refreshHistory$ = new Subject<void>();

  openUpload(): void {
    this.activeImportId.set(null);
    this.isOpen.set(true);
  }

  openDetail(importId: number): void {
    this.activeImportId.set(importId);
    this.isOpen.set(true);
  }

  close(): void {
    this.isOpen.set(false);
    this.activeImportId.set(null);
    this.refreshHistory$.next();
  }

  notifyImportCreated(newId?: number): void {
    this.refreshHistory$.next();
  }
}
