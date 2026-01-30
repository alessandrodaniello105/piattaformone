<?php

namespace App\Http\Controllers;

use App\Models\Action;
use App\Models\FicAccount;
use App\Models\FicClient;
use App\Models\FicInvoice;
use App\Models\FicQuote;
use App\Models\FicSupplier;
use App\Services\DocxVariableReplacer;
use App\Services\PdfConversionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class FicDocumentController extends Controller
{
    /**
     * Show the document generation page.
     */
    public function index(): InertiaResponse
    {
        return Inertia::render('Fic/GenerateDocument');
    }

    /**
     * Show the batch document generation page.
     */
    public function batch(): InertiaResponse
    {
        return Inertia::render('Fic/GenerateDocumentBatch');
    }

    /**
     * Extract variables from uploaded DOCX file.
     */
    public function extractVariables(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'file' => 'required|file|mimes:docx|max:10240',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        try {
            // Store the uploaded file temporarily
            $uploadedFile = $request->file('file');
            $tempPath = $uploadedFile->storeAs('temp', uniqid().'.docx', 'local');
            $fullTempPath = Storage::disk('local')->path($tempPath);

            // Verify the file exists and is readable
            if (! file_exists($fullTempPath) || ! is_readable($fullTempPath)) {
                Storage::disk('local')->delete($tempPath);

                return response()->json([
                    'success' => false,
                    'error' => 'Failed to store uploaded file or file not accessible',
                ], 500);
            }

            // Extract variables
            $replacer = new DocxVariableReplacer;
            $variables = $replacer->extractVariables($fullTempPath);

            // Keep the file for later compilation (store the path in session or return it)
            // For now, we'll return the temp path and the frontend will send it back
            // In production, you might want to store this in session or cache

            return response()->json([
                'success' => true,
                'variables' => $variables,
                'file_token' => basename($tempPath), // Return token to identify file later
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to extract variables: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get detailed data for a specific FIC resource.
     */
    public function getResourceData(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'type' => 'required|string|in:invoice,client,quote,supplier',
                'id' => 'required|integer',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        $data = $this->getFicData($request, $validated['type'], $validated['id']);

        if (! $data) {
            return response()->json([
                'success' => false,
                'error' => 'Resource not found',
            ], 404);
        }

        // Flatten the data to show all available fields
        $replacer = new DocxVariableReplacer;
        $flattened = $replacer->flattenDataForResource([$validated['type'] => $data]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $data->id,
                'type' => $validated['type'],
                'fields' => $flattened,
            ],
        ]);
    }

    /**
     * Compile multiple DOCX documents in batch mode and return as ZIP.
     */
    public function compileBatch(Request $request): JsonResponse|BinaryFileResponse
    {
        try {
            $validated = $request->validate([
                'file_token' => 'required|string',
                'resources' => 'required|array|min:1',
                'resources.*.type' => 'required|string|in:invoice,client,quote,supplier',
                'resources.*.id' => 'required|integer',
                'resources.*.action_start_date' => 'nullable|date',
                'resources.*.action_end_date' => 'nullable|date|after_or_equal:resources.*.action_start_date',
                'include_pdf' => 'nullable|boolean',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        try {
            // Reconstruct the file path from token
            $tempPath = 'temp/'.$validated['file_token'];
            $fullTempPath = Storage::disk('local')->path($tempPath);

            // Verify the template file exists
            if (! file_exists($fullTempPath) || ! is_readable($fullTempPath)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Template file not found. Please upload again.',
                ], 404);
            }

            $replacer = new DocxVariableReplacer;
            $pdfService = new PdfConversionService;
            $compiledFiles = [];
            $includePdf = $validated['include_pdf'] ?? false;

            // Compile a document for each resource
            foreach ($validated['resources'] as $resource) {
                $data = $this->getFicData($request, $resource['type'], $resource['id']);

                if (! $data) {
                    // Skip if resource not found
                    continue;
                }

                // Prepare data for replacement
                $replacementData = [$resource['type'] => $data];

                // Fetch actions if this is a client and date range is provided
                if ($resource['type'] === 'client' && isset($resource['action_start_date'])) {
                    $actions = $this->getActionsForClient(
                        $data->id,
                        $resource['action_start_date'],
                        $resource['action_end_date'] ?? null
                    );

                    // Add actions to replacement data
                    $replacementData['actions'] = $actions;
                }

                // Create a temporary copy of the template for this resource
                $resourceTempPath = 'temp/'.uniqid().'_'.$resource['type'].'_'.$resource['id'].'.docx';
                Storage::disk('local')->copy($tempPath, $resourceTempPath);
                $resourceFullPath = Storage::disk('local')->path($resourceTempPath);

                // Compile this document
                $compiledPath = $replacer->replaceVariables(
                    $resourceFullPath,
                    $replacementData
                );

                $baseFilename = $resource['type'].'_'.$resource['id'].'_'.date('Ymd_His');

                // Add DOCX to compiled files
                $compiledFiles[] = [
                    'path' => $compiledPath,
                    'filename' => $baseFilename.'.docx',
                ];

                // If include_pdf is true, convert to PDF and add it too
                if ($includePdf) {
                    try {
                        $pdfPath = $pdfService->convertDocxToPdf($compiledPath);
                        $compiledFiles[] = [
                            'path' => $pdfPath,
                            'filename' => $baseFilename.'.pdf',
                        ];
                    } catch (\Exception $e) {
                        // Log error but continue with other files
                        \Log::error('Failed to convert DOCX to PDF in batch', [
                            'resource_type' => $resource['type'],
                            'resource_id' => $resource['id'],
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Clean up the temp copy
                Storage::disk('local')->delete($resourceTempPath);
            }

            // Create ZIP archive
            $zipPath = storage_path('app/temp/batch_'.uniqid().'.zip');
            $zip = new ZipArchive;

            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                // Clean up compiled files
                foreach ($compiledFiles as $file) {
                    if (file_exists($file['path'])) {
                        unlink($file['path']);
                    }
                }

                return response()->json([
                    'success' => false,
                    'error' => 'Failed to create ZIP archive',
                ], 500);
            }

            // Add each compiled document to ZIP
            foreach ($compiledFiles as $file) {
                $zip->addFile($file['path'], $file['filename']);
            }

            $zip->close();

            // Clean up the uploaded template file
            Storage::disk('local')->delete($tempPath);

            // Clean up compiled files
            foreach ($compiledFiles as $file) {
                if (file_exists($file['path'])) {
                    unlink($file['path']);
                }
            }

            // Return the ZIP file as download
            $zipFilename = 'batch_documents_'.date('Y-m-d_His').'.zip';

            return response()->download($zipPath, $zipFilename)->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to compile batch documents: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Compile a DOCX template with custom variable mapping.
     */
    public function compileWithMapping(Request $request): JsonResponse|BinaryFileResponse
    {
        try {
            $validated = $request->validate([
                'file_token' => 'required|string',
                'variable_mapping' => 'required|array',
                'variable_mapping.*' => 'nullable|string',
                'client_id' => 'nullable|integer',
                'action_start_date' => 'nullable|date',
                'action_end_date' => 'nullable|date|after_or_equal:action_start_date',
                'include_pdf' => 'nullable|boolean',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        try {
            // Reconstruct the file path from token
            $tempPath = 'temp/'.$validated['file_token'];
            $fullTempPath = Storage::disk('local')->path($tempPath);

            // Verify the file exists
            if (! file_exists($fullTempPath) || ! is_readable($fullTempPath)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Template file not found. Please upload again.',
                ], 404);
            }

            // Prepare data for replacement
            $replacementData = [];

            // Fetch actions if client is specified with date range
            if (isset($validated['client_id']) && isset($validated['action_start_date'])) {
                $clientData = $this->getFicData($request, 'client', $validated['client_id']);

                if ($clientData) {
                    $actions = $this->getActionsForClient(
                        $clientData->id,
                        $validated['action_start_date'],
                        $validated['action_end_date'] ?? null
                    );

                    // Add actions to replacement data
                    $replacementData['actions'] = $actions;
                }
            }

            // Replace variables using mapping
            $replacer = new DocxVariableReplacer;
            $compiledPath = $replacer->replaceVariablesWithMapping(
                $fullTempPath,
                $validated['variable_mapping'],
                $replacementData
            );

            // Clean up the uploaded template file
            Storage::disk('local')->delete($tempPath);

            // If include_pdf is true, convert to PDF and return ZIP
            if ($validated['include_pdf'] ?? false) {
                $pdfService = new PdfConversionService;
                $pdfPath = $pdfService->convertDocxToPdf($compiledPath);

                // Create ZIP with both DOCX and PDF
                $zipPath = storage_path('app/temp/compiled_'.uniqid().'.zip');
                $zip = new ZipArchive;

                if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    // Clean up files
                    if (file_exists($compiledPath)) {
                        unlink($compiledPath);
                    }
                    if (file_exists($pdfPath)) {
                        unlink($pdfPath);
                    }

                    return response()->json([
                        'success' => false,
                        'error' => 'Failed to create ZIP archive',
                    ], 500);
                }

                $baseFilename = 'compiled_'.date('Y-m-d_His');
                $zip->addFile($compiledPath, $baseFilename.'.docx');
                $zip->addFile($pdfPath, $baseFilename.'.pdf');
                $zip->close();

                // Clean up individual files
                if (file_exists($compiledPath)) {
                    unlink($compiledPath);
                }
                if (file_exists($pdfPath)) {
                    unlink($pdfPath);
                }

                return response()->download($zipPath, $baseFilename.'.zip')->deleteFileAfterSend(true);
            }

            // Return the compiled DOCX file as download
            $filename = 'compiled_'.date('Y-m-d_His').'.docx';

            return response()->download($compiledPath, $filename)->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to compile document: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Compile a DOCX template with FIC data (legacy method, kept for backward compatibility).
     */
    public function compile(Request $request): JsonResponse|BinaryFileResponse
    {
        try {
            $validated = $request->validate([
                'file' => 'required|file|mimes:docx|max:10240',
                'data_type' => 'required|string|in:invoice,client,quote,supplier',
                'data_id' => 'required|integer',
                'action_start_date' => 'nullable|date',
                'action_end_date' => 'nullable|date|after_or_equal:action_start_date',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        try {
            // Get the FIC data based on type
            $data = $this->getFicData($request, $validated['data_type'], $validated['data_id']);

            if (! $data) {
                return response()->json([
                    'success' => false,
                    'error' => 'FIC data not found',
                ], 404);
            }

            // Prepare data for replacement
            $replacementData = [$validated['data_type'] => $data];

            // Fetch actions if this is a client and date range is provided
            if ($validated['data_type'] === 'client' && isset($validated['action_start_date'])) {
                $actions = $this->getActionsForClient(
                    $data->id,
                    $validated['action_start_date'],
                    $validated['action_end_date'] ?? null
                );

                // Add actions to replacement data
                $replacementData['actions'] = $actions;
            }

            // Store the uploaded file temporarily
            $uploadedFile = $request->file('file');
            $tempPath = $uploadedFile->storeAs('temp', uniqid().'.docx', 'local');

            // Get the absolute path using Storage facade (respects storage:link)
            $fullTempPath = Storage::disk('local')->path($tempPath);

            // Verify the file exists and is readable
            if (! file_exists($fullTempPath) || ! is_readable($fullTempPath)) {
                Storage::disk('local')->delete($tempPath);

                return response()->json([
                    'success' => false,
                    'error' => 'Failed to store uploaded file or file not accessible. Path: '.$fullTempPath,
                ], 500);
            }

            // Replace variables in the DOCX
            $replacer = new DocxVariableReplacer;
            $compiledPath = $replacer->replaceVariables(
                $fullTempPath,
                $replacementData
            );

            // Clean up the uploaded file
            Storage::disk('local')->delete($tempPath);

            // Return the compiled file as download
            $filename = 'compiled_'.$validated['data_type'].'_'.$validated['data_id'].'.docx';

            return response()->download($compiledPath, $filename)->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to compile document: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get FIC data by type and ID, filtered by current team.
     */
    private function getFicData(Request $request, string $type, int $id): FicInvoice|FicClient|FicQuote|FicSupplier|null
    {
        $teamId = $request->user()->current_team_id;

        // Get the active FIC account for the current team
        $account = FicAccount::forTeam($teamId)
            ->where('status', 'active')
            ->first();

        if (!$account) {
            return null;
        }

        // Query the resource filtered by fic_account_id to ensure team isolation
        return match ($type) {
            'invoice' => FicInvoice::where('fic_account_id', $account->id)->where('id', $id)->first(),
            'client' => FicClient::where('fic_account_id', $account->id)->where('id', $id)->first(),
            'quote' => FicQuote::where('fic_account_id', $account->id)->where('id', $id)->first(),
            'supplier' => FicSupplier::where('fic_account_id', $account->id)->where('id', $id)->first(),
            default => null,
        };
    }

    /**
     * Get actions for a client within a date range.
     *
     * @param  int  $clientId  The FIC client ID
     * @param  string|null  $startDate  Start date (inclusive)
     * @param  string|null  $endDate  End date (inclusive, defaults to now)
     * @return \Illuminate\Support\Collection
     */
    private function getActionsForClient(int $clientId, ?string $startDate = null, ?string $endDate = null)
    {
        $endDate = $endDate ?? now()->toDateString();

        return Action::forClient($clientId)
            ->inDateRange($startDate, $endDate)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Get available FIC data for selection, filtered by current team.
     */
    public function getData(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'type' => 'required|string|in:invoice,client,quote,supplier',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        $type = $validated['type'];
        $page = $validated['page'] ?? 1;
        $perPage = $validated['per_page'] ?? 25;
        $teamId = $request->user()->current_team_id;

        // Get the active FIC account for the current team
        $account = FicAccount::forTeam($teamId)
            ->where('status', 'active')
            ->first();

        if (!$account) {
            return response()->json([
                'success' => true,
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                ],
            ]);
        }

        // Query data filtered by fic_account_id to ensure team isolation
        $data = match ($type) {
            'invoice' => FicInvoice::where('fic_account_id', $account->id)->paginate($perPage, ['*'], 'page', $page),
            'client' => FicClient::where('fic_account_id', $account->id)->paginate($perPage, ['*'], 'page', $page),
            'quote' => FicQuote::where('fic_account_id', $account->id)->paginate($perPage, ['*'], 'page', $page),
            'supplier' => FicSupplier::where('fic_account_id', $account->id)->paginate($perPage, ['*'], 'page', $page),
            default => null,
        };

        if (! $data) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid data type',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => $data->items(),
            'meta' => [
                'current_page' => $data->currentPage(),
                'last_page' => $data->lastPage(),
                'per_page' => $data->perPage(),
                'total' => $data->total(),
            ],
        ]);
    }
}
