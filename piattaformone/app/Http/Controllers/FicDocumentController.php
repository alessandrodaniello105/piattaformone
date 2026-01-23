<?php

namespace App\Http\Controllers;

use App\Models\FicClient;
use App\Models\FicInvoice;
use App\Models\FicQuote;
use App\Models\FicSupplier;
use App\Services\DocxVariableReplacer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

        $data = $this->getFicData($validated['type'], $validated['id']);

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
     * Compile a DOCX template with custom variable mapping.
     */
    public function compileWithMapping(Request $request): JsonResponse|BinaryFileResponse
    {
        try {
            $validated = $request->validate([
                'file_token' => 'required|string',
                'variable_mapping' => 'required|array',
                'variable_mapping.*' => 'nullable|string',
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

            // Replace variables using mapping
            $replacer = new DocxVariableReplacer;
            $compiledPath = $replacer->replaceVariablesWithMapping(
                $fullTempPath,
                $validated['variable_mapping']
            );

            // Clean up the uploaded template file
            Storage::disk('local')->delete($tempPath);

            // Return the compiled file as download
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
            $data = $this->getFicData($validated['data_type'], $validated['data_id']);

            if (! $data) {
                return response()->json([
                    'success' => false,
                    'error' => 'FIC data not found',
                ], 404);
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
                [$validated['data_type'] => $data]
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
     * Get FIC data by type and ID.
     */
    private function getFicData(string $type, int $id): FicInvoice|FicClient|FicQuote|FicSupplier|null
    {
        return match ($type) {
            'invoice' => FicInvoice::find($id),
            'client' => FicClient::find($id),
            'quote' => FicQuote::find($id),
            'supplier' => FicSupplier::find($id),
            default => null,
        };
    }

    /**
     * Get available FIC data for selection.
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

        $data = match ($type) {
            'invoice' => FicInvoice::paginate($perPage, ['*'], 'page', $page),
            'client' => FicClient::paginate($perPage, ['*'], 'page', $page),
            'quote' => FicQuote::paginate($perPage, ['*'], 'page', $page),
            'supplier' => FicSupplier::paginate($perPage, ['*'], 'page', $page),
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
