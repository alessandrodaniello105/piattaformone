# PDF Conversion Feature - Status

## Implementation Complete ✅

### What Was Built
- **PDF conversion from DOCX** using LibreOffice in Docker
- **Checkbox in UI**: "Includi PDF" - downloads ZIP with both DOCX + PDF
- Works in **both single and batch mode**
- Uses **Symfony Process** (safer than exec())
- **Performance logging** with milliseconds tracking

### Key Files Modified

#### Backend
- `app/Services/PdfConversionService.php` - NEW: Handles DOCX → PDF conversion
- `app/Http/Controllers/FicDocumentController.php` - Added `include_pdf` parameter to both modes
- `app/Services/DocxVariableReplacer.php` - Fixed: unmapped variables now cleared in batch mode
- `docker/apache/Dockerfile` - Added LibreOffice installation

#### Frontend
- `resources/js/Pages/Fic/GenerateDocument.vue` - Added PDF checkbox (Step 2 & 3)
- `resources/js/Pages/Fic/GenerateDocumentBatch.vue` - Added PDF checkbox (Step 2 & 3)

## TODO - Testing & Optimization

### 1. First Time Setup (REQUIRED)
```bash
# Rebuild Docker with LibreOffice
sail build --no-cache
sail down && sail up -d
```

### 2. Test Performance
- Upload a test DOCX file
- Enable "Includi PDF" checkbox
- Generate document
- Check logs for conversion time: `sail logs | grep "PDF Conversion"`
- **Decision point**: If time > 3-5 seconds per doc, consider optimization

### 3. Remove Timing Logs (After Testing)
In `app/Services/PdfConversionService.php`:
- Remove `// TODO: Remove timing logs...` section (lines with `$startTime`, `$elapsedMs`)
- Remove `elapsed_ms` from Log statements

### 4. Future Optimization (If Needed)
**Only if conversion time is too high:**
- Option A: Queue document generation (`ProcessDocumentJob`)
- Option B: Use Gotenberg (dedicated Docker service for conversions)
- Option C: Increase LibreOffice timeout (currently 60s)

## How It Works

### Single Mode
1. User uploads template
2. User checks "📄 Includi PDF"
3. System generates DOCX
4. System converts DOCX → PDF (LibreOffice)
5. System creates ZIP with both files
6. User downloads ZIP

### Batch Mode
1. User uploads template + selects multiple resources
2. User checks "📄 Includi PDF"
3. System generates DOCX for each resource
4. System converts each DOCX → PDF
5. System creates ZIP with all DOCX + PDF files
6. User downloads ZIP

## Notes
- Conversion timeout: 60 seconds per document
- LibreOffice runs in headless mode (no GUI)
- Failed PDF conversions are logged but don't stop batch processing
- Empty/unmapped variables now properly cleared in batch mode (consistency fix)

---

**Next Session**: Start with testing after Docker rebuild. Check logs for performance.
