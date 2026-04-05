<?php
declare(strict_types=1);

require_once __DIR__ . '/ImageFormat.php';
require_once __DIR__ . '/functions.php';

// ---------------------------------------------------------------------------
// ConversionResult — value object returned by Converter::run()
// ---------------------------------------------------------------------------
final class ConversionResult
{
    public string $downloadFile  = '';
    public string $downloadUrl   = '';
    public string $downloadLabel = '';
    public int    $successCount  = 0;
    public int    $outputBytes   = 0;
    public bool   $isZip         = false;

    /** @var list<array{name: string, error: string}> */
    public array $failures = [];

    public function hasDownload(): bool
    {
        return $this->downloadFile !== '';
    }
}

// ---------------------------------------------------------------------------
// Converter — orchestrates multi-file upload, per-file conversion, ZIP
// packaging, and cleanup.  Failures are collected per-file so partial
// results can be returned to the caller rather than aborting the whole batch.
// ---------------------------------------------------------------------------
final class Converter
{
    private readonly string $hexToken;

    /** @var list<string> Absolute paths of temporary files to clean up after run(). */
    private array $tempFiles = [];

    /** @param array{resize_width?:int, resize_height?:int, resize_mode?:string, quality?:int} $options */
    public function __construct(private readonly array $options = [])
    {
        $this->hexToken = bin2hex(random_bytes(16));
    }

    // -------------------------------------------------------------------------
    // run() — main entry point
    //
    // @param list<array{name:string, tmp:string, error:int, size:int}> $files
    //        Normalised entries from $_FILES['images'] (one entry per file).
    // @param array<int, string> $fileFormats
    //        Per-file target format keyed by the same numeric index.
    // @param string             $globalFormat
    //        Fallback format used when a per-file entry is absent.
    // -------------------------------------------------------------------------
    public function run(array $files, array $fileFormats, string $globalFormat): ConversionResult
    {
        $result   = new ConversionResult();
        $isSingle = count($files) === 1;

        $zip     = null;
        $zipPath = null;

        if (!$isSingle) {
            $zip     = new ZipArchive();
            $zipPath = ZIP_DIR . $this->hexToken . '.zip';
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Failed to create ZIP archive.');
            }
        }

        $usedNames = [];

        foreach ($files as $key => $fileInfo) {
            $fileName  = $fileInfo['name'];
            $fileTmp   = $fileInfo['tmp'];
            $fileError = $fileInfo['error'];
            $fileSize  = $fileInfo['size'];

            logMessage("FILE[$key] intake", 'info', [
                'name'  => $fileName,
                'error' => $fileError,
                'size'  => $fileSize,
            ]);

            // ── Upload-error codes ────────────────────────────────────────────
            if ($fileError !== UPLOAD_ERR_OK) {
                $result->failures[] = [
                    'name'  => $fileName,
                    'error' => $this->uploadErrorText($fileError),
                ];
                continue;
            }

            if ($fileSize > MAX_FILE_SIZE) {
                $result->failures[] = ['name' => $fileName, 'error' => 'Exceeds 20 MB limit'];
                continue;
            }

            if (!is_uploaded_file($fileTmp)) {
                $result->failures[] = ['name' => $fileName, 'error' => 'Security check failed'];
                continue;
            }

            // ── MIME / image validation (SVG-aware) ───────────────────────────
            $finfo    = new finfo(FILEINFO_MIME_TYPE);
            $mime     = $finfo->file($fileTmp);
            $isSvg    = ($mime === 'image/svg+xml');

            if (!$isSvg && getimagesize($fileTmp) === false) {
                $result->failures[] = ['name' => $fileName, 'error' => 'Not a valid image'];
                continue;
            }

            // ── Extension validation ──────────────────────────────────────────
            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if (!in_array($fileExt, ImageFormat::values(), true)) {
                $result->failures[] = ['name' => $fileName, 'error' => 'Unsupported file type'];
                continue;
            }

            // ── Target format resolution & validation ─────────────────────────
            $targetRaw = $fileFormats[$key] ?? $globalFormat;
            $fmtObj    = ImageFormat::tryFrom($targetRaw);
            if ($fmtObj === null || !$fmtObj->isTargetSupported()) {
                $result->failures[] = ['name' => $fileName, 'error' => 'Invalid target format'];
                continue;
            }

            // ── Deduplicate base names ────────────────────────────────────────
            $baseName = pathinfo($fileName, PATHINFO_FILENAME);
            if (in_array($baseName, $usedNames, true)) {
                $baseName .= '_' . $key;
            }
            $usedNames[] = $baseName;

            $uploadPath    = UPLOAD_DIR    . $baseName . '.' . $fileExt;
            $convertedName = $baseName . '.' . $fmtObj->value;
            $convertedPath = CONVERTED_DIR . $convertedName;

            if (!move_uploaded_file($fileTmp, $uploadPath)) {
                $result->failures[] = ['name' => $fileName, 'error' => 'Failed to save upload'];
                continue;
            }
            $this->tempFiles[] = $uploadPath;

            // ── Convert ───────────────────────────────────────────────────────
            try {
                convertImage($uploadPath, $convertedPath, $fmtObj, $this->options);
            } catch (Exception $e) {
                $result->failures[] = ['name' => $fileName, 'error' => 'Conversion failed'];
                logMessage("FILE[$key] convertImage exception", 'error', ['msg' => $e->getMessage()]);
                continue;
            }

            // ── Package result ────────────────────────────────────────────────
            if ($isSingle) {
                $dlFileName = $this->hexToken . '.' . $fmtObj->value;
                $dlPath     = ZIP_DIR . $dlFileName;
                if (!rename($convertedPath, $dlPath)) {
                    $result->failures[] = ['name' => $fileName, 'error' => 'Failed to prepare download'];
                } else {
                    $result->successCount++;
                    $result->outputBytes   = (int)filesize($dlPath);
                    $result->downloadFile  = $dlFileName;
                    $result->downloadUrl   = 'download.php?file=' . urlencode($dlFileName);
                    $result->downloadLabel = 'Download File';
                    $result->isZip         = false;
                }
            } else {
                $this->tempFiles[] = $convertedPath;
                if ($zip !== null && $zip->addFile($convertedPath, $convertedName) === false) {
                    $result->failures[] = ['name' => $fileName, 'error' => 'Failed to add to archive'];
                } else {
                    $result->successCount++;
                }
            }
        }

        // ── Finalise ZIP ──────────────────────────────────────────────────────
        if (!$isSingle && $zip !== null) {
            $zip->close();
            if ($result->successCount > 0 && $zipPath !== null) {
                $zipFile = $this->hexToken . '.zip';
                $result->outputBytes   = (int)filesize($zipPath);
                $result->downloadFile  = $zipFile;
                $result->downloadUrl   = 'download.php?file=' . urlencode($zipFile);
                $result->downloadLabel = 'Download ZIP (' . $result->successCount . ' file'
                    . ($result->successCount !== 1 ? 's' : '') . ')';
                $result->isZip         = true;
            } else {
                // Nothing converted — delete the empty archive
                if ($zipPath !== null && file_exists($zipPath)) {
                    unlink($zipPath);
                }
            }
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // cleanup() — delete all temporary upload / converted files.
    // Call after the response has been sent or the download URL has been recorded.
    // -------------------------------------------------------------------------
    public function cleanup(): void
    {
        cleanupFiles($this->tempFiles);
        $this->tempFiles = [];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function uploadErrorText(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File too large',
            UPLOAD_ERR_PARTIAL                        => 'Partial upload',
            UPLOAD_ERR_NO_FILE                        => 'No file received',
            UPLOAD_ERR_NO_TMP_DIR                     => 'No temp directory',
            UPLOAD_ERR_CANT_WRITE                     => 'Cannot write to disk',
            UPLOAD_ERR_EXTENSION                      => 'Upload blocked by extension',
            default                                   => "Upload error (code $code)",
        };
    }
}
