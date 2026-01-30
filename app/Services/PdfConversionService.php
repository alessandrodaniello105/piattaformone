<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Service for converting DOCX files to PDF using LibreOffice.
 *
 * TODO: Future optimization - Queue document generation for scalability
 * Consider queueing when handling high volume (e.g., 100 users × 100 batch docs)
 */
class PdfConversionService
{
    /**
     * Convert a DOCX file to PDF using LibreOffice.
     *
     * @param  string  $docxPath  Path to the DOCX file
     * @param  string|null  $outputDir  Output directory (defaults to same as input)
     * @return string Path to the generated PDF file
     *
     * @throws \RuntimeException
     */
    public function convertDocxToPdf(string $docxPath, ?string $outputDir = null): string
    {
        // Verify the DOCX file exists
        if (! file_exists($docxPath)) {
            throw new \RuntimeException("DOCX file not found: {$docxPath}");
        }

        // Determine output directory
        $outputDir = $outputDir ?? dirname($docxPath);

        // Ensure output directory exists and is writable
        if (! is_dir($outputDir) || ! is_writable($outputDir)) {
            throw new \RuntimeException("Output directory not writable: {$outputDir}");
        }

        // Check if LibreOffice is available
        if (! $this->isLibreOfficeAvailable()) {
            throw new \RuntimeException('LibreOffice is not installed or not accessible. Please install LibreOffice in your Docker container.');
        }

        // Build the conversion command using Symfony Process
        // --headless: Run without GUI
        // --convert-to pdf: Convert to PDF format
        // --outdir: Output directory
        $process = new Process([
            'libreoffice',
            '--headless',
            '--convert-to',
            'pdf',
            '--outdir',
            $outputDir,
            $docxPath,
        ]);

        // Set timeout to 60 seconds (adjust based on document size)
        $process->setTimeout(60);

        Log::info('PDF Conversion: Executing command', [
            'command' => $process->getCommandLine(),
        ]);

        // TODO: Remove timing logs after performance testing
        // Measure conversion time for performance evaluation
        $startTime = microtime(true);

        // Execute the command
        try {
            $process->mustRun();
        } catch (ProcessFailedException $exception) {
            $elapsedMs = round((microtime(true) - $startTime) * 1000, 2);

            Log::error('PDF Conversion: Failed', [
                'exit_code' => $process->getExitCode(),
                'output' => $process->getOutput(),
                'error_output' => $process->getErrorOutput(),
                'docx_path' => $docxPath,
                'elapsed_ms' => $elapsedMs,
            ]);

            throw new \RuntimeException('Failed to convert DOCX to PDF: '.$exception->getMessage());
        }

        // Calculate elapsed time in milliseconds
        $elapsedMs = round((microtime(true) - $startTime) * 1000, 2);

        // Determine the PDF file path
        $pdfPath = $outputDir.'/'.pathinfo($docxPath, PATHINFO_FILENAME).'.pdf';

        // Verify the PDF was created
        if (! file_exists($pdfPath)) {
            Log::error('PDF Conversion: PDF file not found after conversion', [
                'expected_path' => $pdfPath,
                'output' => implode("\n", $output),
            ]);

            throw new \RuntimeException('PDF file was not created at expected location: '.$pdfPath);
        }

        Log::info('PDF Conversion: Success', [
            'docx_path' => $docxPath,
            'pdf_path' => $pdfPath,
            'pdf_size' => filesize($pdfPath),
            'elapsed_ms' => $elapsedMs,
        ]);

        return $pdfPath;
    }

    /**
     * Check if LibreOffice is available on the system.
     */
    public function isLibreOfficeAvailable(): bool
    {
        $process = new Process(['which', 'libreoffice']);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Get LibreOffice version information.
     */
    public function getLibreOfficeVersion(): ?string
    {
        if (! $this->isLibreOfficeAvailable()) {
            return null;
        }

        $process = new Process(['libreoffice', '--version']);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $output = trim($process->getOutput());

        return $output ?: null;
    }
}
