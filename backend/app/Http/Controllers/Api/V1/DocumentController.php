<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Company\Models\Company;
use App\Domain\Document\Models\Document;
use App\Domain\Document\Services\DocumentService;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Project\Models\Project;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    /** What a document may be attached to, matching the enforced morph map. */
    private const SUBJECTS = [
        'opportunity' => Opportunity::class,
        'project' => Project::class,
        'company' => Company::class,
    ];

    /**
     * Extensions accepted for upload. An allow-list rather than a block-list,
     * and deliberately excluding anything the server could be tricked into
     * executing.
     */
    private const ALLOWED = 'pdf,doc,docx,xls,xlsx,ppt,pptx,csv,txt,png,jpg,jpeg,webp,zip';

    public function __construct(private readonly DocumentService $documents) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Document::class);

        $validated = $request->validate([
            'subject_type' => ['required', Rule::in(array_keys(self::SUBJECTS))],
            'subject_id' => ['required', 'uuid'],
        ]);

        $subject = $this->resolveSubject($validated['subject_type'], $validated['subject_id']);

        // Document visibility follows the record it hangs off.
        $this->authorize('view', $subject);

        $documents = Document::query()
            ->where('subject_type', $validated['subject_type'])
            ->where('subject_id', $subject->getKey())
            ->when(
                ! $request->user()->canDo(\App\Domain\Identity\Enums\PermissionCode::OpportunityViewInternalNotes),
                fn ($q) => $q->where('is_internal', false),
            )
            ->with('uploader:id,uuid,name')
            ->orderByDesc('created_at')
            ->get();

        return DocumentResource::collection($documents);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Document::class);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:'.self::ALLOWED],
            'subject_type' => ['required', Rule::in(array_keys(self::SUBJECTS))],
            'subject_id' => ['required', 'uuid'],
            'document_type' => ['nullable', Rule::in(['proposal', 'quotation', 'contract', 'specification', 'other'])],
            'is_internal' => ['sometimes', 'boolean'],
        ]);

        $subject = $this->resolveSubject($validated['subject_type'], $validated['subject_id']);
        $this->authorize('view', $subject);

        $document = $this->documents->store(
            $request->file('file'),
            $subject,
            $validated['document_type'] ?? null,
            (bool) ($validated['is_internal'] ?? false),
        );

        return (new DocumentResource($document->load('uploader')))->response()->setStatusCode(201);
    }

    /**
     * Streamed through the application, never served from a public path, so
     * every download passes the same authorization as the record it belongs to.
     */
    public function download(Document $document): StreamedResponse
    {
        $this->authorize('view', $document);

        abort_unless($this->documents->exists($document), 404, 'The stored file is missing.');

        return \Illuminate\Support\Facades\Storage::disk(DocumentService::DISK)
            ->download($document->storage_path, $document->name);
    }

    public function destroy(Document $document): JsonResponse
    {
        $this->authorize('delete', $document);

        $this->documents->delete($document);

        return response()->json(null, 204);
    }

    private function resolveSubject(string $type, string $uuid): \Illuminate\Database\Eloquent\Model
    {
        $model = self::SUBJECTS[$type];

        // Organization-scoped models, so a foreign uuid resolves to nothing.
        return $model::where('uuid', $uuid)->firstOrFail();
    }
}
