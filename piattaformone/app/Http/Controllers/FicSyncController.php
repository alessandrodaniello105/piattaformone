<?php

namespace App\Http\Controllers;

use App\Models\FicAccount;
use App\Models\FicClient;
use App\Models\FicEvent;
use App\Models\FicInvoice;
use App\Models\FicQuote;
use App\Services\FicApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
    /**
     * Perform initial sync from FIC API.
     *
     * Fetches clients, quotes, and invoices from FIC and upserts them into local database.
     * Returns statistics about the sync operation.
     *
     * @return JsonResponse
     */
    public function initialSync(): JsonResponse
    {
        try {
            // Get the default/active FIC account (single account setup)
            $account = FicAccount::where('status', 'active')
                ->orWhereNull('status')
                ->first();

            if (!$account) {
                $account = FicAccount::first();
            }

            if (!$account) {
                return response()->json([
                    'error' => 'No FIC account found. Please connect an account first.',
                ], 404);
            }

            $apiService = new FicApiService($account);

            $stats = [
                'clients' => ['created' => 0, 'updated' => 0],
                'quotes' => ['created' => 0, 'updated' => 0],
                'invoices' => ['created' => 0, 'updated' => 0],
            ];

            // Sync clients
            try {
                $clientsData = $apiService->fetchClientsList(['per_page' => 100]);
                $clients = $clientsData['data'] ?? [];

                foreach ($clients as $clientData) {
                    $client = FicClient::updateOrCreate(
                        [
                            'fic_account_id' => $account->id,
                            'fic_client_id' => $clientData['id'],
                        ],
                        [
                            'name' => $clientData['name'] ?? null,
                            'code' => $clientData['code'] ?? null,
                            'fic_created_at' => isset($clientData['created_at']) 
                                ? Carbon::parse($clientData['created_at']) 
                                : null,
                            'fic_updated_at' => isset($clientData['updated_at']) 
                                ? Carbon::parse($clientData['updated_at']) 
                                : null,
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

            // Sync quotes
            try {
                $quotesData = $apiService->fetchQuotesList(['per_page' => 100]);
                $quotes = $quotesData['data'] ?? [];

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
                            'fic_date' => isset($quoteData['date']) 
                                ? Carbon::parse($quoteData['date']) 
                                : null,
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
                $invoices = $invoicesData['data'] ?? [];

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
                            'fic_date' => isset($invoiceData['date']) 
                                ? Carbon::parse($invoiceData['date']) 
                                : null,
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

            // Get the default/active FIC account
            $account = FicAccount::where('status', 'active')
                ->orWhereNull('status')
                ->first();

            if (!$account) {
                $account = FicAccount::first();
            }

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
    public function metrics(): JsonResponse
    {
        try {
            // Get the default/active FIC account
            $account = FicAccount::where('status', 'active')
                ->orWhereNull('status')
                ->first();

            if (!$account) {
                $account = FicAccount::first();
            }

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

            // Get clients grouped by year-month
            $clientsData = FicClient::where('fic_account_id', $account->id)
                ->whereNotNull('fic_created_at')
                ->selectRaw('YEAR(fic_created_at) as year, MONTH(fic_created_at) as month, COUNT(*) as count')
                ->groupBy('year', 'month')
                ->get();

            foreach ($clientsData as $data) {
                $index = $this->getMonthIndex($data->year, $data->month, $months);
                if ($index !== null) {
                    $series['clients'][$index] = (int) $data->count;
                }
            }

            // Get quotes grouped by year-month
            $quotesData = FicQuote::where('fic_account_id', $account->id)
                ->whereNotNull('fic_created_at')
                ->selectRaw('YEAR(fic_created_at) as year, MONTH(fic_created_at) as month, COUNT(*) as count')
                ->groupBy('year', 'month')
                ->get();

            foreach ($quotesData as $data) {
                $index = $this->getMonthIndex($data->year, $data->month, $months);
                if ($index !== null) {
                    $series['quotes'][$index] = (int) $data->count;
                }
            }

            // Get invoices grouped by year-month
            $invoicesData = FicInvoice::where('fic_account_id', $account->id)
                ->whereNotNull('fic_created_at')
                ->selectRaw('YEAR(fic_created_at) as year, MONTH(fic_created_at) as month, COUNT(*) as count')
                ->groupBy('year', 'month')
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

            $lastMonthInvoices = FicInvoice::where('fic_account_id', $account->id)
                ->whereBetween('fic_created_at', [$lastMonthStart, $lastMonthEnd])
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
}
