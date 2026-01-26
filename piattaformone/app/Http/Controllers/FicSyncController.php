<?php

namespace App\Http\Controllers;

use App\Models\FicAccount;
use App\Models\FicClient;
use App\Models\FicEvent;
use App\Models\FicInvoice;
use App\Models\FicQuote;
use App\Models\FicSupplier;
use App\Services\FicApiService;
use App\Services\FicCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Controller for FIC synchronization and dashboard APIs.
 *
 * Provides endpoints for:
 * - Initial sync from FIC API
 * - Events feed for dashboard
 * - Metrics/analytics for dashboard
 */
class FicSyncController extends Controller
{
    private FicCacheService $cacheService;

    public function __construct(FicCacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * Show the synced data page with initial clients data.
     *
     * @return InertiaResponse
     */
    public function index(Request $request): InertiaResponse
    {
        try {
            // Get initial clients data (first page, 25 per page)
            // Try cache first
            $cached = $this->cacheService->get('clients', 1, 25);

            if ($cached !== null) {
                $clientsData = $cached['data'];
                $clientsMeta = $cached['meta'];
            } else {
                // Fetch from database if not cached - filter by current team
                $account = FicAccount::forTeam($request->user()->current_team_id)
                    ->where('status', 'active')
                    ->first();

                if ($account) {
                    $clients = FicClient::where('fic_account_id', $account->id)
                        ->orderBy('updated_at', 'desc')
                        ->paginate(25);

                    $clientsData = $clients->items();
                    $clientsMeta = [
                        'current_page' => $clients->currentPage(),
                        'last_page' => $clients->lastPage(),
                        'per_page' => $clients->perPage(),
                        'total' => $clients->total(),
                    ];

                    // Store in cache
                    $this->cacheService->put('clients', 1, 25, $clientsData, $clientsMeta);
                } else {
                    $clientsData = [];
                    $clientsMeta = ['total' => 0, 'current_page' => 1, 'last_page' => 1, 'per_page' => 25];
                }
            }

            return Inertia::render('Fic/SyncedData', [
                'initialClients' => $clientsData,
                'initialClientsMeta' => $clientsMeta,
            ]);
        } catch (\Exception $e) {
            Log::error('FIC Sync: Error loading synced data page', [
                'error' => $e->getMessage(),
            ]);

            // Return page with empty data on error
            return Inertia::render('Fic/SyncedData', [
                'initialClients' => [],
                'initialClientsMeta' => ['total' => 0, 'current_page' => 1, 'last_page' => 1, 'per_page' => 25],
            ]);
        }
    }

    /**
     * Perform initial sync from FIC API.
     *
     * Fetches clients, quotes, and invoices from FIC and upserts them into local database.
     * Returns statistics about the sync operation.
     *
     * @return JsonResponse
     */
    public function initialSync(Request $request): JsonResponse
    {
        try {
            // Get the active FIC account for the current team
            $account = FicAccount::forTeam($request->user()->current_team_id)
                ->where('status', 'active')
                ->first();

            if (!$account) {
                return response()->json([
                    'error' => 'No FIC account found for this team. Please connect an account first.',
                ], 404);
            }

            $apiService = new FicApiService($account);

            $stats = [
                'clients' => ['created' => 0, 'updated' => 0],
                'suppliers' => ['created' => 0, 'updated' => 0],
                'quotes' => ['created' => 0, 'updated' => 0],
                'invoices' => ['created' => 0, 'updated' => 0],
            ];

            // Sync clients
            try {
                $clientsData = $apiService->fetchClientsList(['per_page' => 100]);
                // fetchClientsList returns the data array directly, not wrapped in ['data']
                $clients = is_array($clientsData) ? $clientsData : [];

                foreach ($clients as $clientData) {
                    $client = FicClient::updateOrCreate(
                        [
                            'fic_account_id' => $account->id,
                            'fic_client_id' => $clientData['id'],
                        ],
                        [
                            'name' => $clientData['name'] ?? null,
                            'code' => $clientData['code'] ?? null,
                            'vat_number' => $clientData['vat_number'] ?? null,
                            'fic_created_at' => $this->extractFicCreatedAt($clientData),
                            'fic_updated_at' => $this->extractFicUpdatedAt($clientData),
                            'raw' => $clientData,
                        ]
                    );

                    if ($client->wasRecentlyCreated) {
                        $stats['clients']['created']++;
                    } else {
                        $stats['clients']['updated']++;
                    }
                }
            } catch (\Exception $e) {
                Log::error('FIC Sync: Error syncing clients', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Sync suppliers
            try {
                $suppliersData = $apiService->fetchSuppliersList(['per_page' => 100]);
                // fetchSuppliersList returns the data array directly, not wrapped in ['data']
                $suppliers = is_array($suppliersData) ? $suppliersData : [];

                foreach ($suppliers as $supplierData) {
                    $supplier = FicSupplier::updateOrCreate(
                        [
                            'fic_account_id' => $account->id,
                            'fic_supplier_id' => $supplierData['id'],
                        ],
                        [
                            'name' => $supplierData['name'] ?? null,
                            'code' => $supplierData['code'] ?? null,
                            'vat_number' => $supplierData['vat_number'] ?? null,
                            'fic_created_at' => $this->extractFicCreatedAt($supplierData),
                            'fic_updated_at' => $this->extractFicUpdatedAt($supplierData),
                            'raw' => $supplierData,
                        ]
                    );

                    if ($supplier->wasRecentlyCreated) {
                        $stats['suppliers']['created']++;
                    } else {
                        $stats['suppliers']['updated']++;
                    }
                }
            } catch (\Exception $e) {
                Log::error('FIC Sync: Error syncing suppliers', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Sync quotes
            try {
                $quotesData = $apiService->fetchQuotesList(['per_page' => 100]);
                // fetchQuotesList returns pagination structure with 'data' key when using SDK
                $quotes = $quotesData['data'] ?? (is_array($quotesData) ? $quotesData : []);

                foreach ($quotes as $quoteData) {
                    $quote = FicQuote::updateOrCreate(
                        [
                            'fic_account_id' => $account->id,
                            'fic_quote_id' => $quoteData['id'],
                        ],
                        [
                            'number' => $quoteData['number'] ?? null,
                            'status' => $quoteData['status'] ?? null,
                            'total_gross' => $quoteData['amount_net'] 
                                ?? $quoteData['total'] 
                                ?? $quoteData['total_gross'] 
                                ?? null,
                            'fic_date' => $this->extractFicDate($quoteData),
                            'fic_created_at' => isset($quoteData['created_at']) 
                                ? Carbon::parse($quoteData['created_at']) 
                                : null,
                            'raw' => $quoteData,
                        ]
                    );

                    if ($quote->wasRecentlyCreated) {
                        $stats['quotes']['created']++;
                    } else {
                        $stats['quotes']['updated']++;
                    }
                }
            } catch (\Exception $e) {
                Log::error('FIC Sync: Error syncing quotes', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Sync invoices
            try {
                $invoicesData = $apiService->fetchInvoicesList(['per_page' => 100]);
                // fetchInvoicesList returns the data array directly, not wrapped in ['data']
                $invoices = is_array($invoicesData) ? $invoicesData : [];

                foreach ($invoices as $invoiceData) {
                    $invoice = FicInvoice::updateOrCreate(
                        [
                            'fic_account_id' => $account->id,
                            'fic_invoice_id' => $invoiceData['id'],
                        ],
                        [
                            'number' => $invoiceData['number'] ?? null,
                            'status' => $invoiceData['status'] ?? null,
                            'total_gross' => $invoiceData['amount_net'] 
                                ?? $invoiceData['total'] 
                                ?? $invoiceData['total_gross'] 
                                ?? null,
                            'fic_date' => $this->extractFicDate($invoiceData),
                            'fic_created_at' => isset($invoiceData['created_at']) 
                                ? Carbon::parse($invoiceData['created_at']) 
                                : null,
                            'raw' => $invoiceData,
                        ]
                    );

                    if ($invoice->wasRecentlyCreated) {
                        $stats['invoices']['created']++;
                    } else {
                        $stats['invoices']['updated']++;
                    }
                }
            } catch (\Exception $e) {
                Log::error('FIC Sync: Error syncing invoices', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Update last_sync_at timestamp
            $account->update(['last_sync_at' => now()]);

            // Invalidate all cache after sync
            $this->cacheService->invalidateAll();

            return response()->json([
                'success' => true,
                'message' => 'Initial sync completed',
                'stats' => $stats,
            ]);
        } catch (\Exception $e) {
            Log::error('FIC Sync: Error during initial sync', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Sync failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get events feed for dashboard.
     *
     * Returns a normalized feed of recent events from clients, quotes, and invoices.
     * If fic_events table exists, uses that; otherwise merges data from resource tables.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function events(Request $request): JsonResponse
    {
        try {
            $limit = (int) ($request->input('limit', 100));
            $limit = min(max($limit, 1), 200); // Clamp between 1 and 200

            // Get the active FIC account for the current team
            $account = FicAccount::forTeam($request->user()->current_team_id)
                ->where('status', 'active')
                ->first();

            if (!$account) {
                return response()->json([
                    'events' => [],
                ]);
            }

            $events = [];

            // Try to use fic_events table if it exists and has data
            if (DB::getSchemaBuilder()->hasTable('fic_events')) {
                $ficEvents = FicEvent::where('fic_account_id', $account->id)
                    ->orderBy('occurred_at', 'desc')
                    ->limit($limit)
                    ->get();

                foreach ($ficEvents as $event) {
                    $events[] = [
                        'type' => $event->resource_type,
                        'fic_id' => $event->fic_resource_id,
                        'occurred_at' => $event->occurred_at?->toIso8601String(),
                        'event_type' => $event->event_type,
                        'description' => $this->getEventDescription($event),
                        'status' => $event->status ?? 'processed',
                        'ce_id' => $event->payload['ce_id'] ?? null,
                        'object_details' => null, // Will be populated from payload if needed
                    ];
                }
            }

            // If we don't have enough events from fic_events, supplement with resource tables
            if (count($events) < $limit) {
                $remaining = $limit - count($events);

                // Get recent clients
                $clients = FicClient::where('fic_account_id', $account->id)
                    ->whereNotNull('fic_created_at')
                    ->orderBy('fic_created_at', 'desc')
                    ->limit($remaining)
                    ->get();

                foreach ($clients as $client) {
                    $events[] = [
                        'type' => 'client',
                        'fic_id' => $client->fic_client_id,
                        'occurred_at' => $client->fic_created_at?->toIso8601String(),
                        'event_type' => 'it.fattureincloud.webhooks.entities.clients.create',
                        'description' => "Cliente creato: {$client->name}",
                    ];
                }

                // Get recent quotes
                $quotes = FicQuote::where('fic_account_id', $account->id)
                    ->whereNotNull('fic_created_at')
                    ->orderBy('fic_created_at', 'desc')
                    ->limit($remaining)
                    ->get();

                foreach ($quotes as $quote) {
                    $events[] = [
                        'type' => 'quote',
                        'fic_id' => $quote->fic_quote_id,
                        'occurred_at' => $quote->fic_created_at?->toIso8601String(),
                        'event_type' => 'it.fattureincloud.webhooks.issued_documents.quotes.create',
                        'description' => "Preventivo creato: {$quote->number}",
                    ];
                }

                // Get recent invoices
                $invoices = FicInvoice::where('fic_account_id', $account->id)
                    ->whereNotNull('fic_created_at')
                    ->orderBy('fic_created_at', 'desc')
                    ->limit($remaining)
                    ->get();

                foreach ($invoices as $invoice) {
                    $events[] = [
                        'type' => 'invoice',
                        'fic_id' => $invoice->fic_invoice_id,
                        'occurred_at' => $invoice->fic_created_at?->toIso8601String(),
                        'event_type' => 'it.fattureincloud.webhooks.issued_documents.invoices.create',
                        'description' => "Fattura creata: {$invoice->number}",
                    ];
                }

                // Sort all events by occurred_at descending and limit
                usort($events, function ($a, $b) {
                    $timeA = $a['occurred_at'] ? Carbon::parse($a['occurred_at']) : Carbon::createFromTimestamp(0);
                    $timeB = $b['occurred_at'] ? Carbon::parse($b['occurred_at']) : Carbon::createFromTimestamp(0);
                    return $timeB->gt($timeA) ? 1 : -1;
                });

                $events = array_slice($events, 0, $limit);
            }

            return response()->json([
                'events' => $events,
            ]);
        } catch (\Exception $e) {
            Log::error('FIC Sync: Error fetching events', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to fetch events: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get metrics/analytics for dashboard.
     *
     * Returns monthly distribution for the last 12 months and last month KPIs.
     *
     * @return JsonResponse
     */
    public function metrics(Request $request): JsonResponse
    {
        try {
            // Get the active FIC account for the current team
            $account = FicAccount::forTeam($request->user()->current_team_id)
                ->where('status', 'active')
                ->first();

            if (!$account) {
                return response()->json([
                    'series' => [
                        'clients' => [],
                        'quotes' => [],
                        'invoices' => [],
                    ],
                    'lastMonth' => [
                        'clients' => 0,
                        'invoices' => 0,
                    ],
                ]);
            }

            // Calculate last 12 months
            $months = [];
            for ($i = 11; $i >= 0; $i--) {
                $date = now()->subMonths($i);
                $months[] = [
                    'year' => $date->year,
                    'month' => $date->month,
                    'label' => $date->format('Y-m'),
                ];
            }

            // Initialize series data
            $series = [
                'clients' => array_fill(0, 12, 0),
                'quotes' => array_fill(0, 12, 0),
                'invoices' => array_fill(0, 12, 0),
            ];

            // Get clients grouped by year-month (PostgreSQL compatible)
            $clientsData = FicClient::where('fic_account_id', $account->id)
                ->whereNotNull('fic_created_at')
                ->selectRaw('EXTRACT(YEAR FROM fic_created_at)::integer as year, EXTRACT(MONTH FROM fic_created_at)::integer as month, COUNT(*) as count')
                ->groupBy(DB::raw('EXTRACT(YEAR FROM fic_created_at)'), DB::raw('EXTRACT(MONTH FROM fic_created_at)'))
                ->get();

            foreach ($clientsData as $data) {
                $index = $this->getMonthIndex($data->year, $data->month, $months);
                if ($index !== null) {
                    $series['clients'][$index] = (int) $data->count;
                }
            }

            // Get quotes grouped by year-month (PostgreSQL compatible)
            // Use fic_date with fallback to fic_created_at (same logic as analyticsDate() method)
            $quotesData = FicQuote::where('fic_account_id', $account->id)
                ->where(function ($query) {
                    $query->whereNotNull('fic_date')
                        ->orWhereNotNull(DB::raw("raw->>'fic_date'"))
                        ->orWhereNotNull('fic_created_at');
                })
                ->selectRaw("EXTRACT(YEAR FROM COALESCE(fic_date, CAST(raw->>'fic_date' AS DATE), fic_created_at::date))::integer as year, EXTRACT(MONTH FROM COALESCE(fic_date, CAST(raw->>'fic_date' AS DATE), fic_created_at::date))::integer as month, COUNT(*) as count")
                ->groupBy(DB::raw("EXTRACT(YEAR FROM COALESCE(fic_date, CAST(raw->>'fic_date' AS DATE), fic_created_at::date))"), DB::raw("EXTRACT(MONTH FROM COALESCE(fic_date, CAST(raw->>'fic_date' AS DATE), fic_created_at::date))"))
                ->get();

            foreach ($quotesData as $data) {
                $index = $this->getMonthIndex($data->year, $data->month, $months);
                if ($index !== null) {
                    $series['quotes'][$index] = (int) $data->count;
                }
            }

            // Get invoices grouped by year-month (PostgreSQL compatible)
            // Use fic_date with fallback to raw->>'fic_date' then fic_created_at (same logic as analyticsDate() method)
            $invoicesData = FicInvoice::where('fic_account_id', $account->id)
                ->where(function ($query) {
                    $query->whereNotNull('fic_date')
                        ->orWhereNotNull(DB::raw("raw->>'fic_date'"))
                        ->orWhereNotNull('fic_created_at');
                })
                ->selectRaw("EXTRACT(YEAR FROM COALESCE(fic_date, CAST(raw->>'fic_date' AS DATE), fic_created_at::date))::integer as year, EXTRACT(MONTH FROM COALESCE(fic_date, CAST(raw->>'fic_date' AS DATE), fic_created_at::date))::integer as month, COUNT(*) as count")
                ->groupBy(DB::raw("EXTRACT(YEAR FROM COALESCE(fic_date, CAST(raw->>'fic_date' AS DATE), fic_created_at::date))"), DB::raw("EXTRACT(MONTH FROM COALESCE(fic_date, CAST(raw->>'fic_date' AS DATE), fic_created_at::date))"))
                ->get();

            foreach ($invoicesData as $data) {
                $index = $this->getMonthIndex($data->year, $data->month, $months);
                if ($index !== null) {
                    $series['invoices'][$index] = (int) $data->count;
                }
            }

            // Calculate last month KPIs
            $lastMonthStart = now()->subMonth()->startOfMonth();
            $lastMonthEnd = now()->subMonth()->endOfMonth();

            $lastMonthClients = FicClient::where('fic_account_id', $account->id)
                ->whereBetween('fic_created_at', [$lastMonthStart, $lastMonthEnd])
                ->count();

            // Use fic_date with fallback to raw->>'fic_date' then fic_created_at (same logic as analyticsDate() method)
            $lastMonthInvoices = FicInvoice::where('fic_account_id', $account->id)
                ->whereRaw('COALESCE(fic_date, CAST(raw->>\'fic_date\' AS DATE), fic_created_at::date) BETWEEN ? AND ?', [$lastMonthStart, $lastMonthEnd])
                ->count();

            return response()->json([
                'series' => [
                    'clients' => array_map(function ($count, $month) {
                        return [
                            'month' => $month['label'],
                            'count' => $count,
                        ];
                    }, $series['clients'], $months),
                    'quotes' => array_map(function ($count, $month) {
                        return [
                            'month' => $month['label'],
                            'count' => $count,
                        ];
                    }, $series['quotes'], $months),
                    'invoices' => array_map(function ($count, $month) {
                        return [
                            'month' => $month['label'],
                            'count' => $count,
                        ];
                    }, $series['invoices'], $months),
                ],
                'lastMonth' => [
                    'clients' => $lastMonthClients,
                    'invoices' => $lastMonthInvoices,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('FIC Sync: Error fetching metrics', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to fetch metrics: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get event description from FicEvent.
     *
     * @param FicEvent $event
     * @return string
     */
    private function getEventDescription(FicEvent $event): string
    {
        $payload = $event->payload ?? [];
        
        switch ($event->resource_type) {
            case 'client':
                $name = $payload['name'] ?? 'N/A';
                return "Cliente creato: {$name}";
            case 'quote':
                $number = $payload['number'] ?? 'N/A';
                return "Preventivo creato: {$number}";
            case 'invoice':
                $number = $payload['number'] ?? 'N/A';
                return "Fattura creata: {$number}";
            default:
                return "Evento {$event->resource_type}";
        }
    }

    /**
     * Get the index of a month in the months array.
     *
     * @param int $year
     * @param int $month
     * @param array $months
     * @return int|null
     */
    private function getMonthIndex(int $year, int $month, array $months): ?int
    {
        foreach ($months as $index => $monthData) {
            if ($monthData['year'] == $year && $monthData['month'] == $month) {
                return $index;
            }
        }
        return null;
    }

    /**
     * Get list of synced clients.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function clients(Request $request): JsonResponse
    {
        try {
            $perPage = (int) ($request->input('per_page', 25));
            $perPage = min(max($perPage, 1), 100);
            $page = (int) ($request->input('page', 1));

            // Try to get from cache first
            $cached = $this->cacheService->get('clients', $page, $perPage);
            if ($cached !== null) {
                return response()->json($cached);
            }

            // Get the active FIC account for the current team
            $account = FicAccount::forTeam($request->user()->current_team_id)
                ->where('status', 'active')
                ->first();

            if (!$account) {
                return response()->json([
                    'data' => [],
                    'meta' => ['total' => 0],
                ]);
            }

            $clients = FicClient::where('fic_account_id', $account->id)
                ->orderBy('updated_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            $response = [
                'data' => $clients->items(),
                'meta' => [
                    'current_page' => $clients->currentPage(),
                    'last_page' => $clients->lastPage(),
                    'per_page' => $clients->perPage(),
                    'total' => $clients->total(),
                ],
            ];

            // Store in cache
            $this->cacheService->put('clients', $page, $perPage, $response['data'], $response['meta']);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('FIC Sync: Error fetching clients', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to fetch clients: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get list of synced quotes.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function quotes(Request $request): JsonResponse
    {
        try {
            $perPage = (int) ($request->input('per_page', 25));
            $perPage = min(max($perPage, 1), 100);
            $page = (int) ($request->input('page', 1));

            // Try to get from cache first
            $cached = $this->cacheService->get('quotes', $page, $perPage);
            if ($cached !== null) {
                return response()->json($cached);
            }

            // Get the active FIC account for the current team
            $account = FicAccount::forTeam($request->user()->current_team_id)
                ->where('status', 'active')
                ->first();

            if (!$account) {
                return response()->json([
                    'data' => [],
                    'meta' => ['total' => 0],
                ]);
            }

            $quotes = FicQuote::where('fic_account_id', $account->id)
                ->orderBy('updated_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            $response = [
                'data' => $quotes->items(),
                'meta' => [
                    'current_page' => $quotes->currentPage(),
                    'last_page' => $quotes->lastPage(),
                    'per_page' => $quotes->perPage(),
                    'total' => $quotes->total(),
                ],
            ];

            // Store in cache
            $this->cacheService->put('quotes', $page, $perPage, $response['data'], $response['meta']);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('FIC Sync: Error fetching quotes', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to fetch quotes: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get list of synced suppliers.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function suppliers(Request $request): JsonResponse
    {
        try {
            $perPage = (int) ($request->input('per_page', 25));
            $perPage = min(max($perPage, 1), 100);
            $page = (int) ($request->input('page', 1));

            // Try to get from cache first
            $cached = $this->cacheService->get('suppliers', $page, $perPage);
            if ($cached !== null) {
                return response()->json($cached);
            }

            // Get the active FIC account for the current team
            $account = FicAccount::forTeam($request->user()->current_team_id)
                ->where('status', 'active')
                ->first();

            if (!$account) {
                return response()->json([
                    'data' => [],
                    'meta' => ['total' => 0],
                ]);
            }

            $suppliers = FicSupplier::where('fic_account_id', $account->id)
                ->orderBy('updated_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            $response = [
                'data' => $suppliers->items(),
                'meta' => [
                    'current_page' => $suppliers->currentPage(),
                    'last_page' => $suppliers->lastPage(),
                    'per_page' => $suppliers->perPage(),
                    'total' => $suppliers->total(),
                ],
            ];

            // Store in cache
            $this->cacheService->put('suppliers', $page, $perPage, $response['data'], $response['meta']);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('FIC Sync: Error fetching suppliers', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to fetch suppliers: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get list of synced invoices.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function invoices(Request $request): JsonResponse
    {
        try {
            $perPage = (int) ($request->input('per_page', 25));
            $perPage = min(max($perPage, 1), 100);
            $page = (int) ($request->input('page', 1));

            // Try to get from cache first
            $cached = $this->cacheService->get('invoices', $page, $perPage);
            if ($cached !== null) {
                return response()->json($cached);
            }

            // Get the active FIC account for the current team
            $account = FicAccount::forTeam($request->user()->current_team_id)
                ->where('status', 'active')
                ->first();

            if (!$account) {
                return response()->json([
                    'data' => [],
                    'meta' => ['total' => 0],
                ]);
            }

            $invoices = FicInvoice::where('fic_account_id', $account->id)
                ->orderBy('updated_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            $response = [
                'data' => $invoices->items(),
                'meta' => [
                    'current_page' => $invoices->currentPage(),
                    'last_page' => $invoices->lastPage(),
                    'per_page' => $invoices->perPage(),
                    'total' => $invoices->total(),
                ],
            ];

            // Store in cache
            $this->cacheService->put('invoices', $page, $perPage, $response['data'], $response['meta']);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('FIC Sync: Error fetching invoices', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to fetch invoices: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Extract fic_date from data array, checking both direct 'date' field and 'raw' array.
     *
     * @param  array  $data  The data array (may contain 'date' directly or 'fic_date' in 'raw')
     * @return \Illuminate\Support\Carbon|null
     */
    private function extractFicDate(array $data): ?Carbon
    {
        // Try direct 'date' field first (from API response)
        if (isset($data['date']) && !empty($data['date'])) {
            try {
                return Carbon::parse($data['date']);
            } catch (\Exception $e) {
                // Invalid date format, continue to check raw
            }
        }

        // Try from raw array - check for 'fic_date' (as stored in raw JSON)
        if (isset($data['raw']['fic_date']) && !empty($data['raw']['fic_date'])) {
            try {
                return Carbon::parse($data['raw']['fic_date']);
            } catch (\Exception $e) {
                // Invalid date format
            }
        }

        // Also try 'date' in raw (in case it's stored as 'date' in raw)
        if (isset($data['raw']['date']) && !empty($data['raw']['date'])) {
            try {
                return Carbon::parse($data['raw']['date']);
            } catch (\Exception $e) {
                // Invalid date format
            }
        }

        // If data itself is the raw response (when passed directly from API)
        // Check if it's already the raw structure
        if (isset($data['raw']) && is_array($data['raw'])) {
            // Try fic_date first
            if (isset($data['raw']['fic_date']) && !empty($data['raw']['fic_date'])) {
                try {
                    return Carbon::parse($data['raw']['fic_date']);
                } catch (\Exception $e) {
                    // Invalid date format
                }
            }
            // Then try date
            if (isset($data['raw']['date']) && !empty($data['raw']['date'])) {
                try {
                    return Carbon::parse($data['raw']['date']);
                } catch (\Exception $e) {
                    // Invalid date format
                }
            }
        }

        return null;
    }

    /**
     * Extract fic_created_at from data array, checking both direct 'created_at' field and 'raw' array.
     *
     * @param  array  $data  The data array (may contain 'created_at' directly or 'fic_created_at' in 'raw')
     * @return \Illuminate\Support\Carbon|null
     */
    private function extractFicCreatedAt(array $data): ?Carbon
    {
        // Try direct 'created_at' field first (from API response)
        if (isset($data['created_at']) && !empty($data['created_at'])) {
            try {
                return Carbon::parse($data['created_at']);
            } catch (\Exception $e) {
                // Invalid date format, continue to check raw
            }
        }

        // Try from raw array - check for 'fic_created_at' (as stored in raw JSON)
        if (isset($data['raw']['fic_created_at']) && !empty($data['raw']['fic_created_at'])) {
            try {
                return Carbon::parse($data['raw']['fic_created_at']);
            } catch (\Exception $e) {
                // Invalid date format
            }
        }

        // Also try 'created_at' in raw (in case it's stored as 'created_at' in raw)
        if (isset($data['raw']['created_at']) && !empty($data['raw']['created_at'])) {
            try {
                return Carbon::parse($data['raw']['created_at']);
            } catch (\Exception $e) {
                // Invalid date format
            }
        }

        return null;
    }

    /**
     * Extract fic_updated_at from data array, checking both direct 'updated_at' field and 'raw' array.
     *
     * @param  array  $data  The data array (may contain 'updated_at' directly or 'fic_updated_at' in 'raw')
     * @return \Illuminate\Support\Carbon|null
     */
    private function extractFicUpdatedAt(array $data): ?Carbon
    {
        // Try direct 'updated_at' field first (from API response)
        if (isset($data['updated_at']) && !empty($data['updated_at'])) {
            try {
                return Carbon::parse($data['updated_at']);
            } catch (\Exception $e) {
                // Invalid date format, continue to check raw
            }
        }

        // Try from raw array - check for 'fic_updated_at' (as stored in raw JSON)
        if (isset($data['raw']['fic_updated_at']) && !empty($data['raw']['fic_updated_at'])) {
            try {
                return Carbon::parse($data['raw']['fic_updated_at']);
            } catch (\Exception $e) {
                // Invalid date format
            }
        }

        // Also try 'updated_at' in raw (in case it's stored as 'updated_at' in raw)
        if (isset($data['raw']['updated_at']) && !empty($data['raw']['updated_at'])) {
            try {
                return Carbon::parse($data['raw']['updated_at']);
            } catch (\Exception $e) {
                // Invalid date format
            }
        }

        return null;
    }
}
