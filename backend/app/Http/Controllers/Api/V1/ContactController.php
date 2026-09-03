<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Services\ActivityRecorder;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Models\Contact;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contact\StoreContactRequest;
use App\Http\Requests\Contact\UpdateContactRequest;
use App\Http\Resources\ContactResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ContactController extends Controller
{
    public function __construct(private readonly ActivityRecorder $activities) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Contact::class);

        $contacts = Contact::query()
            ->when($request->filled('search'), fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('name', 'like', '%'.$request->string('search').'%')
                ->orWhere('email', 'like', '%'.$request->string('search').'%')
                ->orWhere('phone', 'like', '%'.$request->string('search').'%')))
            ->when($request->filled('company_id'), fn (Builder $q) => $q->whereHas(
                'company',
                fn (Builder $inner) => $inner->where('uuid', $request->string('company_id'))
            ))
            ->with('company:id,uuid,name')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return ContactResource::collection($contacts);
    }

    public function store(StoreContactRequest $request): JsonResponse
    {
        $this->authorize('create', Contact::class);

        $data = $request->validated();
        $data['company_id'] = $this->resolveCompanyId($data['company_id'] ?? null);

        $contact = DB::transaction(function () use ($data) {
            $contact = Contact::create($data);
            $this->enforceSinglePrimary($contact);

            $this->activities->record(
                type: ActivityType::ContactCreated,
                subject: $contact,
                title: "Contact added: {$contact->name}",
            );

            return $contact;
        });

        return (new ContactResource($contact->load('company')))->response()->setStatusCode(201);
    }

    public function show(Contact $contact): ContactResource
    {
        $this->authorize('view', $contact);

        return new ContactResource($contact->load('company'));
    }

    public function update(UpdateContactRequest $request, Contact $contact): ContactResource
    {
        $this->authorize('update', $contact);

        $data = $request->validated();

        if (array_key_exists('company_id', $data)) {
            $data['company_id'] = $this->resolveCompanyId($data['company_id']);
        }

        DB::transaction(function () use ($contact, $data) {
            $contact->update($data);
            $this->enforceSinglePrimary($contact);
        });

        return new ContactResource($contact->fresh('company'));
    }

    public function destroy(Contact $contact): JsonResponse
    {
        $this->authorize('delete', $contact);

        $contact->delete();

        return response()->json(null, 204);
    }

    private function resolveCompanyId(?string $uuid): ?int
    {
        return $uuid === null ? null : Company::whereUuid($uuid)->value('id');
    }

    /** A company has at most one primary contact. */
    private function enforceSinglePrimary(Contact $contact): void
    {
        if (! $contact->is_primary || $contact->company_id === null) {
            return;
        }

        Contact::where('company_id', $contact->company_id)
            ->whereKeyNot($contact->id)
            ->update(['is_primary' => false]);
    }
}
