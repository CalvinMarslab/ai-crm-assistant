<?php

namespace App\Domain\Document\Services;

use App\Domain\Document\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentService
{
    /** Files are written here, outside the web root; nothing is publicly served. */
    public const DISK = 'local';

    public function store(
        UploadedFile $file,
        Model $subject,
        ?string $documentType = null,
        bool $isInternal = false,
    ): Document {
        // The stored name is generated, never derived from user input, so a
        // crafted filename cannot escape the directory or be executed.
        $path = $file->storeAs(
            'documents/'.$subject->getMorphClass().'/'.$subject->getKey(),
            Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: 'bin'),
            self::DISK,
        );

        return Document::create([
            'uploaded_by_user_id' => Auth::id(),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'document_type' => $documentType,
            // Kept for display only, stripped of any path components.
            'name' => basename($file->getClientOriginalName()),
            'storage_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'is_internal' => $isInternal,
        ]);
    }

    public function delete(Document $document): void
    {
        Storage::disk(self::DISK)->delete($document->storage_path);

        $document->delete();
    }

    public function exists(Document $document): bool
    {
        return Storage::disk(self::DISK)->exists($document->storage_path);
    }
}
