<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class StoreSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt,json', 'max:2048'],
        ];
    }
    public function messages(): array
    {
        return [
            'file.required' => 'Файл не был загружен',
            'file.mimes' => 'Поддерживаются только файлы .csv, .txt, .json',
            'file.max' => 'Размер файла не должен превышать 2 МБ',
        ];
    }
}
