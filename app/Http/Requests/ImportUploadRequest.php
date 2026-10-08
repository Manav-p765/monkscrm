<?php

namespace App\Http\Requests;

use App\Support\Settings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;
use ZipArchive;

/**
 * Import upload: .csv or .xlsx only. Both the extension and the detected file type must match, and
 * Excel files containing macros (.xlsm, or an .xlsx with a VBA project inside) are refused.
 * Size limit comes from Settings (import_max_file_mb).
 */
class ImportUploadRequest extends FormRequest
{
    public const MIMES = [
        'csv' => ['text/csv', 'text/plain', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel', 'text/comma-separated-values'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    ];

    public function authorize(): bool
    {
        return $this->user()->can('import-leads');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['file' => ['required', 'file', 'max:'.((int) Settings::get('import_max_file_mb', 5) * 1024)]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose a CSV or Excel (.xlsx) file to import.',
            'file.max' => 'The file is too big. The limit is '.Settings::get('import_max_file_mb', 5).' MB: split it into smaller files.',
            'file.uploaded' => 'The file could not be uploaded. Check its size and try again.',
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $file = $this->file('file');
            if (! $file instanceof UploadedFile || $validator->errors()->has('file')) {
                return;
            }

            $ext = mb_strtolower($file->getClientOriginalExtension());
            if (! array_key_exists($ext, self::MIMES)) {
                $validator->errors()->add('file', $ext === 'xlsm' || $ext === 'xls' || $ext === 'xlsb'
                    ? 'That Excel format isn’t accepted (macro or old Excel files). Save it as .xlsx or .csv and try again.'
                    : 'Only .csv and .xlsx files can be imported.');

                return;
            }
            if (! in_array($file->getMimeType(), self::MIMES[$ext], true)) {
                $validator->errors()->add('file', "This file doesn't look like a real .{$ext} file. Export it again from Excel or Google Sheets.");

                return;
            }
            if ($ext === 'xlsx' && $this->hasMacros($file->getRealPath())) {
                $validator->errors()->add('file', 'This workbook contains macros, which aren’t accepted. Save it as a plain .xlsx (or .csv) and try again.');
            }
        }];
    }

    private function hasMacros(string $path): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return true; // not a readable workbook: treat as unsafe
        }
        $macro = $zip->locateName('xl/vbaProject.bin') !== false;
        $zip->close();

        return $macro;
    }
}
