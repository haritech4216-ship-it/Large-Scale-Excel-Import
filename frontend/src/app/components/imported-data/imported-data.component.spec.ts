import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { of, throwError } from 'rxjs';
import { ImportedDataComponent } from './imported-data.component';
import { ImportApiService } from '../../services/import-api.service';
import { ImportedDataResponse } from '../../models/import.model';

describe('ImportedDataComponent', () => {
  let component: ImportedDataComponent;
  let fixture: ComponentFixture<ImportedDataComponent>;
  let apiService: ImportApiService;

  const mockData: ImportedDataResponse = {
    data: [
      {
        id: 1,
        customer_id: 'CUST-1001',
        first_name: 'James',
        last_name: 'Wilson',
        email: 'james.wilson@example.com',
        phone_number: '+1-202-555-0143',
        date_of_birth: '1985-04-12',
        gender: 'Male',
        profession: 'Software Engineer',
        company_name: 'Apex Innovations',
        country: 'United States',
        city: 'Austin',
        address: '100 Congress Ave',
        social_media_url: 'https://linkedin.com/in/james-wilson',
        registration_date: '2023-01-15',
        status: 'Active',
        import_id: 3,
        created_at: '2026-10-30T00:00:00Z',
        updated_at: '2026-10-30T00:00:00Z',
      },
      {
        id: 2,
        customer_id: 'CUST-1002',
        first_name: 'Emma',
        last_name: 'Taylor',
        email: 'emma.taylor@example.com',
        phone_number: '+44-20-7946-0912',
        date_of_birth: '1990-08-23',
        gender: 'Female',
        profession: 'Product Manager',
        company_name: 'Bright Horizons',
        country: 'United Kingdom',
        city: 'London',
        address: '25 Baker Street',
        social_media_url: null,
        registration_date: '2023-02-01',
        status: 'Active',
        import_id: 3,
        created_at: '2026-10-30T00:00:00Z',
        updated_at: '2026-10-30T00:00:00Z',
      },
    ],
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 2,
    from: 1,
    to: 2,
    total_eligible_count: 2,
  };

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ImportedDataComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        ImportApiService,
      ],
    }).compileComponents();

    apiService = TestBed.inject(ImportApiService);
    vi.spyOn(apiService, 'getImportedData').mockReturnValue(of(mockData));

    fixture = TestBed.createComponent(ImportedDataComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create the component and load initial imported data on init', () => {
    expect(component).toBeTruthy();
    expect(apiService.getImportedData).toHaveBeenCalledWith(1, 15, '');
    expect(component.importedData()?.data.length).toBe(2);
    expect(component.totalEligibleCount()).toBe(2);

    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.textContent).toContain('CUST-1001');
    expect(compiled.textContent).toContain('James Wilson');
    expect(compiled.textContent).toContain('CUST-1002');
  });

  it('should render the search input, refresh button, and Delete All button', () => {
    const compiled = fixture.nativeElement as HTMLElement;
    const searchInput = compiled.querySelector('.search-input');
    const refreshBtn = compiled.querySelector('.controls-toolbar button');
    const deleteAllBtn = compiled.querySelector('.btn-delete-all');

    expect(searchInput).toBeTruthy();
    expect(refreshBtn).toBeTruthy();
    expect(deleteAllBtn).toBeTruthy();
    expect(deleteAllBtn?.textContent).toContain('Delete All');
    expect(deleteAllBtn?.textContent).toContain('2');
  });

  it('should trigger search and reload data on search query change', () => {
    component.onSearchChange('Emma');
    expect(component.searchQuery()).toBe('Emma');
    expect(apiService.getImportedData).toHaveBeenCalledWith(1, 15, 'Emma');
  });

  it('should clear search and reload data', () => {
    component.searchQuery.set('test');
    component.clearSearch();
    expect(component.searchQuery()).toBe('');
    expect(apiService.getImportedData).toHaveBeenCalledWith(1, 15, '');
  });

  it('should open delete confirmation dialog on Delete All button click', () => {
    expect(component.isDeleteDialogOpen()).toBe(false);

    component.openDeleteDialog();
    expect(component.isDeleteDialogOpen()).toBe(true);
    expect(component.confirmationInput()).toBe('');

    fixture.detectChanges();
    const modal = fixture.nativeElement.querySelector('.delete-modal');
    expect(modal).toBeTruthy();
    expect(modal.textContent).toContain('Delete All Imported Data');
    expect(modal.textContent).toContain('2 imported customer records');
  });

  it('should disable final delete button until user types exactly DELETE ALL', () => {
    component.openDeleteDialog();
    expect(component.isConfirmButtonEnabled()).toBe(false);

    component.confirmationInput.set('delete all'); // lowercase
    expect(component.isConfirmButtonEnabled()).toBe(false);

    component.confirmationInput.set('DELETE');
    expect(component.isConfirmButtonEnabled()).toBe(false);

    component.confirmationInput.set('DELETE ALL');
    expect(component.isConfirmButtonEnabled()).toBe(true);
  });

  it('should cancel delete dialog leaving records unchanged', () => {
    const deleteSpy = vi.spyOn(apiService, 'deleteAllImportedData');
    component.openDeleteDialog();
    component.confirmationInput.set('DELETE ALL');

    component.closeDeleteDialog();

    expect(component.isDeleteDialogOpen()).toBe(false);
    expect(component.confirmationInput()).toBe('');
    expect(deleteSpy).not.toHaveBeenCalled();
    expect(component.totalEligibleCount()).toBe(2);
  });

  it('should execute delete, refresh table, and display success message on valid confirmation', () => {
    const deleteSpy = vi.spyOn(apiService, 'deleteAllImportedData').mockReturnValue(
      of({
        success: true,
        message: 'Successfully deleted 2 imported records.',
        deleted_count: 2,
      })
    );

    const emptyData: ImportedDataResponse = {
      data: [],
      current_page: 1,
      last_page: 1,
      per_page: 15,
      total: 0,
      from: null,
      to: null,
      total_eligible_count: 0,
    };
    const getSpy = vi.spyOn(apiService, 'getImportedData').mockReturnValue(of(emptyData));

    component.openDeleteDialog();
    component.confirmationInput.set('DELETE ALL');
    component.confirmDeleteAll();

    expect(deleteSpy).toHaveBeenCalledWith('DELETE ALL');
    expect(component.isDeleting()).toBe(false);
    expect(component.isDeleteDialogOpen()).toBe(false);
    expect(component.successMessage()).toBe('Successfully deleted 2 imported records.');
    expect(getSpy).toHaveBeenCalledWith(1, 15, '');
    expect(component.totalEligibleCount()).toBe(0);

    fixture.detectChanges();
    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.textContent).toContain('Successfully deleted 2 imported records.');
  });

  it('should display error message when delete operation fails', () => {
    vi.spyOn(apiService, 'deleteAllImportedData').mockReturnValue(
      throwError(() => ({
        error: { message: 'Database transaction error occurred.' },
      }))
    );

    component.openDeleteDialog();
    component.confirmationInput.set('DELETE ALL');
    component.confirmDeleteAll();

    expect(component.isDeleting()).toBe(false);
    expect(component.isDeleteDialogOpen()).toBe(true);
    expect(component.deleteError()).toBe('Database transaction error occurred.');

    fixture.detectChanges();
    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.textContent).toContain('Database transaction error occurred.');
  });

  it('should disable Delete All button when there are 0 eligible records', () => {
    const emptyData: ImportedDataResponse = {
      data: [],
      current_page: 1,
      last_page: 1,
      per_page: 15,
      total: 0,
      from: null,
      to: null,
      total_eligible_count: 0,
    };
    component.importedData.set(emptyData);
    fixture.detectChanges();

    expect(component.totalEligibleCount()).toBe(0);
    const deleteAllBtn = fixture.nativeElement.querySelector(
      '.btn-delete-all'
    ) as HTMLButtonElement;
    expect(deleteAllBtn.disabled).toBe(true);
  });
});
