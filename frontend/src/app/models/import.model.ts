export type ImportStatus =
  | 'Queued'
  | 'Processing'
  | 'Completed'
  | 'Completed with Errors'
  | 'Failed';

export interface StageUpload {
  status: 'pending' | 'uploading' | 'completed' | 'failed';
  percentage: number;
}

export interface StageValidation {
  status: 'queued' | 'processing' | 'completed' | 'failed';
  checked_rows: number;
  total_rows: number;
  valid_rows: number;
  invalid_rows: number;
  duplicate_rows: number;
  percentage: number;
  indeterminate: boolean;
}

export interface StageImport {
  status: 'queued' | 'processing' | 'completed' | 'failed';
  saved_rows: number;
  eligible_rows: number;
  percentage: number;
  indeterminate: boolean;
}

export interface ImportStages {
  upload: StageUpload;
  validation: StageValidation;
  import: StageImport;
}

export interface ImportItem {
  id: number;
  filename: string;
  file_path: string;
  status: ImportStatus;
  total_rows: number;
  total_rows_known?: boolean;
  processed_rows: number;
  validated_rows?: number;
  valid_rows?: number;
  invalid_rows?: number;
  duplicate_rows?: number;
  saved_rows: number;
  rejected_rows: number;
  started_at: string | null;
  completed_at: string | null;
  duration_seconds: number | null;
  failure_reason: string | null;
  created_at: string;
  updated_at: string;
}

export interface ImportDetail extends ImportItem {
  progress_percentage: number;
  stages?: ImportStages;
}

export interface ImportRowError {
  id: number;
  import_id: number;
  excel_row: number;
  customer_id: string | null;
  rejection_reason: string;
  created_at: string;
}

export interface PaginatedResponse<T> {
  current_page: number;
  data: T[];
  first_page_url?: string;
  from: number | null;
  last_page: number;
  last_page_url?: string;
  next_page_url?: string | null;
  path?: string;
  per_page: number;
  prev_page_url?: string | null;
  to: number | null;
  total: number;
}

export interface UploadResponse {
  id: number;
  filename: string;
  status: ImportStatus;
  message: string;
}

export interface ImportedCustomer {
  id: number;
  customer_id: string;
  first_name: string;
  last_name: string;
  email: string;
  phone_number: string;
  date_of_birth: string;
  gender?: string | null;
  profession: string;
  company_name?: string | null;
  country: string;
  city: string;
  address?: string | null;
  social_media_url?: string | null;
  registration_date: string;
  status: string;
  import_id: number;
  created_at: string;
  updated_at: string;
}

export interface ImportedDataResponse {
  data: ImportedCustomer[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
  total_eligible_count: number;
}

export interface DeleteAllResponse {
  success: boolean;
  message: string;
  deleted_count: number;
}
