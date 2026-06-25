<?php

namespace App\Http\Controllers\Cabinet;

use App\Http\Controllers\Controller;
use App\Models\TenantDocument;
use App\Support\CabinetAssistanceMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentsController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(CabinetAssistanceMode::canViewFinance($request), 403);

        $tenant = $request->user()->tenant;

        $documents = TenantDocument::query()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('document_date')
            ->orderByDesc('created_at')
            ->get();

        return view('cabinet.documents.index', [
            'tenant' => $tenant,
            'documents' => $documents,
        ]);
    }

    public function download(Request $request, int $documentId): StreamedResponse
    {
        abort_unless(CabinetAssistanceMode::canViewFinance($request), 403);

        $tenant = $request->user()->tenant;

        $document = TenantDocument::query()
            ->where('tenant_id', $tenant->id)
            ->whereKey($documentId)
            ->firstOrFail();

        return Storage::disk('public')->download($document->file_path);
    }
}
