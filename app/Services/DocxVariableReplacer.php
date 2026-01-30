<?php

namespace App\Services;

use PhpOffice\PhpWord\TemplateProcessor;
use ZipArchive;

class DocxVariableReplacer
{
    /**
     * Replace variables in a DOCX template with FIC data.
     *
     * @param  string  $templatePath  Path to the DOCX template file
     * @param  array  $data  Array of FIC data (invoice, client, quote, supplier, actions)
     * @return string Path to the compiled DOCX file
     */
    public function replaceVariables(string $templatePath, array $data): string
    {
        // Ensure the template file exists and is readable
        if (! file_exists($templatePath) || ! is_readable($templatePath)) {
            throw new \RuntimeException("Template file not found or not readable: {$templatePath}");
        }

        $templateProcessor = new TemplateProcessor($templatePath);

        // Handle actions table rows if actions data is provided
        if (isset($data['actions']) && !empty($data['actions'])) {
            $actions = $data['actions'];

            // Clone the table row for each action
            $templateProcessor->cloneRow('action.category', count($actions));

            // Replace values for each action row
            foreach ($actions as $index => $action) {
                $rowNum = $index + 1;

                $templateProcessor->setValue("action.category#{$rowNum}", $this->formatValue($action->category));
                $templateProcessor->setValue("action.name#{$rowNum}", $this->formatValue($action->name));
                $templateProcessor->setValue("action.description#{$rowNum}", $this->formatValue($action->description));
                $templateProcessor->setValue("action.gross#{$rowNum}", $this->formatValue($action->gross ? '€ ' . number_format($action->gross, 2, ',', '.') : ''));
                $templateProcessor->setValue("action.created_at#{$rowNum}", $this->formatValue($action->created_at ? $action->created_at->format('d/m/Y') : ''));
            }
        }

        // Flatten the data structure for variable replacement
        $variables = $this->flattenData($data);

        // Replace all variables in the template
        foreach ($variables as $key => $value) {
            $templateProcessor->setValue($key, $this->formatValue($value));
        }

        // Save the compiled document
        $outputPath = storage_path('app/temp/compiled_'.uniqid().'.docx');
        $templateProcessor->saveAs($outputPath);

        return $outputPath;
    }

    /**
     * Flatten the FIC data structure into variables.
     */
    private function flattenData(array $data): array
    {
        $variables = [];

        // Process invoice data
        if (isset($data['invoice'])) {
            $invoice = $data['invoice'];
            $variables['invoice.number'] = $invoice->number ?? '';
            $variables['invoice.status'] = $invoice->status ?? '';
            $variables['invoice.total_gross'] = $invoice->total_gross ? number_format($invoice->total_gross, 2, ',', '.') : '';
            $variables['invoice.fic_date'] = $invoice->fic_date ? $invoice->fic_date->format('d/m/Y') : '';
            $variables['invoice.fic_created_at'] = $invoice->fic_created_at ? $invoice->fic_created_at->format('d/m/Y H:i') : '';

            // Add raw data fields
            if (is_array($invoice->raw)) {
                foreach ($invoice->raw as $key => $value) {
                    $variables["invoice.raw.{$key}"] = $this->formatRawValue($value);
                }
            }
        }

        // Process client data
        if (isset($data['client'])) {
            $client = $data['client'];
            $variables['client.name'] = $client->name ?? '';
            $variables['client.code'] = $client->code ?? '';
            $variables['client.vat_number'] = $client->vat_number ?? '';
            $variables['client.fic_created_at'] = $client->fic_created_at ? $client->fic_created_at->format('d/m/Y H:i') : '';

            // Add raw data fields
            if (is_array($client->raw)) {
                foreach ($client->raw as $key => $value) {
                    $variables["client.raw.{$key}"] = $this->formatRawValue($value);
                }
            }
        }

        // Process quote data
        if (isset($data['quote'])) {
            $quote = $data['quote'];
            $variables['quote.number'] = $quote->number ?? '';
            $variables['quote.status'] = $quote->status ?? '';
            $variables['quote.total_gross'] = $quote->total_gross ? number_format($quote->total_gross, 2, ',', '.') : '';
            $variables['quote.fic_date'] = $quote->fic_date ? $quote->fic_date->format('d/m/Y') : '';
            $variables['quote.fic_created_at'] = $quote->fic_created_at ? $quote->fic_created_at->format('d/m/Y H:i') : '';

            // Add raw data fields
            if (is_array($quote->raw)) {
                foreach ($quote->raw as $key => $value) {
                    $variables["quote.raw.{$key}"] = $this->formatRawValue($value);
                }
            }
        }

        // Process supplier data
        if (isset($data['supplier'])) {
            $supplier = $data['supplier'];
            $variables['supplier.name'] = $supplier->name ?? '';
            $variables['supplier.code'] = $supplier->code ?? '';
            $variables['supplier.vat_number'] = $supplier->vat_number ?? '';
            $variables['supplier.fic_created_at'] = $supplier->fic_created_at ? $supplier->fic_created_at->format('d/m/Y H:i') : '';

            // Add raw data fields
            if (is_array($supplier->raw)) {
                foreach ($supplier->raw as $key => $value) {
                    $variables["supplier.raw.{$key}"] = $this->formatRawValue($value);
                }
            }
        }

        // Add current date/time
        $variables['current_date'] = now()->format('d/m/Y');
        $variables['current_time'] = now()->format('H:i');
        $variables['current_datetime'] = now()->format('d/m/Y H:i');

        return $variables;
    }

    /**
     * Format a raw value for display.
     */
    private function formatRawValue(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if (is_bool($value)) {
            return $value ? 'Sì' : 'No';
        }

        if ($value === null) {
            return '';
        }

        return (string) $value;
    }

    /**
     * Format a value for template replacement.
     */
    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return (string) $value;
    }

    /**
     * Extract variables from a DOCX template.
     *
     * @param  string  $templatePath  Path to the DOCX template file
     * @return array Array of found variables
     */
    public function extractVariables(string $templatePath): array
    {
        // Ensure the template file exists and is readable
        if (! file_exists($templatePath) || ! is_readable($templatePath)) {
            throw new \RuntimeException("Template file not found or not readable: {$templatePath}");
        }

        $variables = [];
        $zip = new ZipArchive;

        if ($zip->open($templatePath) === true) {
            // Read the main document XML
            $documentXml = $zip->getFromName('word/document.xml');

            if ($documentXml !== false) {
                // Extract variables using regex pattern for ${variable} syntax
                preg_match_all('/\$\{([^}]+)\}/', $documentXml, $matches);

                if (! empty($matches[1])) {
                    $variables = array_unique($matches[1]);
                    sort($variables);
                }
            }

            $zip->close();
        } else {
            throw new \RuntimeException("Failed to open DOCX file: {$templatePath}");
        }

        return $variables;
    }

    /**
     * Flatten data for a single resource to show available fields.
     */
    public function flattenDataForResource(array $data): array
    {
        $flattened = [];

        // Process invoice data
        if (isset($data['invoice'])) {
            $invoice = $data['invoice'];
            $flattened['invoice.number'] = $invoice->number ?? '';
            $flattened['invoice.status'] = $invoice->status ?? '';
            $flattened['invoice.total_gross'] = $invoice->total_gross ? number_format($invoice->total_gross, 2, ',', '.') : '';
            $flattened['invoice.fic_date'] = $invoice->fic_date ? $invoice->fic_date->format('d/m/Y') : '';
            $flattened['invoice.fic_created_at'] = $invoice->fic_created_at ? $invoice->fic_created_at->format('d/m/Y H:i') : '';

            if (is_array($invoice->raw)) {
                foreach ($invoice->raw as $key => $value) {
                    $flattened["invoice.raw.{$key}"] = $this->formatRawValue($value);
                }
            }
        }

        // Process client data
        if (isset($data['client'])) {
            $client = $data['client'];
            $flattened['client.name'] = $client->name ?? '';
            $flattened['client.code'] = $client->code ?? '';
            $flattened['client.vat_number'] = $client->vat_number ?? '';
            $flattened['client.fic_created_at'] = $client->fic_created_at ? $client->fic_created_at->format('d/m/Y H:i') : '';

            if (is_array($client->raw)) {
                foreach ($client->raw as $key => $value) {
                    $flattened["client.raw.{$key}"] = $this->formatRawValue($value);
                }
            }
        }

        // Process quote data
        if (isset($data['quote'])) {
            $quote = $data['quote'];
            $flattened['quote.number'] = $quote->number ?? '';
            $flattened['quote.status'] = $quote->status ?? '';
            $flattened['quote.total_gross'] = $quote->total_gross ? number_format($quote->total_gross, 2, ',', '.') : '';
            $flattened['quote.fic_date'] = $quote->fic_date ? $quote->fic_date->format('d/m/Y') : '';
            $flattened['quote.fic_created_at'] = $quote->fic_created_at ? $quote->fic_created_at->format('d/m/Y H:i') : '';

            if (is_array($quote->raw)) {
                foreach ($quote->raw as $key => $value) {
                    $flattened["quote.raw.{$key}"] = $this->formatRawValue($value);
                }
            }
        }

        // Process supplier data
        if (isset($data['supplier'])) {
            $supplier = $data['supplier'];
            $flattened['supplier.name'] = $supplier->name ?? '';
            $flattened['supplier.code'] = $supplier->code ?? '';
            $flattened['supplier.vat_number'] = $supplier->vat_number ?? '';
            $flattened['supplier.fic_created_at'] = $supplier->fic_created_at ? $supplier->fic_created_at->format('d/m/Y H:i') : '';

            if (is_array($supplier->raw)) {
                foreach ($supplier->raw as $key => $value) {
                    $flattened["supplier.raw.{$key}"] = $this->formatRawValue($value);
                }
            }
        }

        // Add current date/time
        $flattened['current_date'] = now()->format('d/m/Y');
        $flattened['current_time'] = now()->format('H:i');
        $flattened['current_datetime'] = now()->format('d/m/Y H:i');

        return $flattened;
    }

    /**
     * Replace variables using a mapping array.
     *
     * @param  string  $templatePath  Path to the DOCX template file
     * @param  array  $variableMapping  Array mapping variable names to values ['variable' => 'value']
     * @param  array  $additionalData  Additional data like actions for table rows
     * @return string Path to the compiled DOCX file
     */
    public function replaceVariablesWithMapping(string $templatePath, array $variableMapping, array $additionalData = []): string
    {
        // Ensure the template file exists and is readable
        if (! file_exists($templatePath) || ! is_readable($templatePath)) {
            throw new \RuntimeException("Template file not found or not readable: {$templatePath}");
        }

        $templateProcessor = new TemplateProcessor($templatePath);

        // Handle actions table rows if actions data is provided
        if (isset($additionalData['actions']) && !empty($additionalData['actions'])) {
            $actions = $additionalData['actions'];

            // Clone the table row for each action
            $templateProcessor->cloneRow('action.category', count($actions));

            // Replace values for each action row
            foreach ($actions as $index => $action) {
                $rowNum = $index + 1;

                $templateProcessor->setValue("action.category#{$rowNum}", $this->formatValue($action->category));
                $templateProcessor->setValue("action.name#{$rowNum}", $this->formatValue($action->name));
                $templateProcessor->setValue("action.description#{$rowNum}", $this->formatValue($action->description));
                $templateProcessor->setValue("action.gross#{$rowNum}", $this->formatValue($action->gross ? '€ ' . number_format($action->gross, 2, ',', '.') : ''));
                $templateProcessor->setValue("action.created_at#{$rowNum}", $this->formatValue($action->created_at ? $action->created_at->format('d/m/Y') : ''));
            }
        }

        // Replace all variables in the template
        foreach ($variableMapping as $variable => $value) {
            // Convert null/empty to empty string explicitly
            // This ensures that mapped variables with empty values are cleared from the document
            $formattedValue = ($value === null || $value === '') ? '' : $this->formatValue($value);

            // Log for debugging
            \Log::debug('Replacing variable', [
                'variable' => $variable,
                'value' => $formattedValue,
                'is_empty' => $formattedValue === '',
            ]);

            // Always call setValue, even for empty values
            // This replaces the variable placeholder with the empty string
            $templateProcessor->setValue($variable, $formattedValue);
        }

        // Save the compiled document
        $outputPath = storage_path('app/temp/compiled_'.uniqid().'.docx');
        $templateProcessor->saveAs($outputPath);

        return $outputPath;
    }
}
