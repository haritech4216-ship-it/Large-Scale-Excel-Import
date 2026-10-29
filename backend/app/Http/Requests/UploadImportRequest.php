<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UploadImportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $maxKb = (int) config('excel_import.max_upload_size_kb', 102400);

        return [
            'file' => [
                'required',
                'file',
                'max:'.$maxKb,
                function ($attribute, $value, $fail) {
                    if (! $value->isValid()) {
                        $fail('The uploaded file was corrupted during transfer.');

                        return;
                    }

                    $extension = strtolower($value->getClientOriginalExtension());
                    if ($extension !== 'xlsx') {
                        $fail('Only .xlsx files are supported.');

                        return;
                    }

                    // Check mime type or ZIP magic bytes (PK\x03\x04)
                    $mime = $value->getMimeType();
                    $allowedMimes = [
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/zip',
                        'application/octet-stream',
                        'application/x-zip-compressed',
                    ];

                    if (! in_array($mime, $allowedMimes, true)) {
                        $fail('The uploaded file content does not match the .xlsx format.');

                        return;
                    }

                    // Check magic bytes for ZIP (all modern .xlsx files are ZIP archives)
                    $handle = fopen($value->getRealPath(), 'rb');
                    if ($handle) {
                        $header = fread($handle, 4);
                        fclose($handle);
                        if ($header !== "PK\x03\x04") {
                            $fail('The uploaded file content does not have a valid Excel/ZIP signature.');
                        }
                    }
                },
            ],
        ];
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Please provide an .xlsx file to upload.',
            'file.file' => 'The uploaded item must be a valid file.',
            'file.max' => 'The file size exceeds the allowed limit.',
        ];
    }

    /**
     * Handle failed validation to return structured JSON.
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
