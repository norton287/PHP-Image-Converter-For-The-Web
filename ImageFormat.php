<?php
declare(strict_types=1);

/**
 * ImageFormat — backed enum for every supported image/document format.
 *
 * Requires PHP 8.1+.  Replaces the ALLOWED_FORMATS string-array constant so that
 * format handling is exhaustively type-checked and all format-specific behaviour
 * (quality support, Imagick format string, UI label …) lives in one place.
 */
enum ImageFormat: string
{
    case JPG  = 'jpg';
    case JPEG = 'jpeg';   // alias for JPG; accepted as input, hidden from UI dropdowns
    case PNG  = 'png';
    case BMP  = 'bmp';
    case GIF  = 'gif';
    case ICO  = 'ico';
    case TIFF = 'tiff';
    case WEBP = 'webp';
    case PDF  = 'pdf';
    case SVG  = 'svg';    // input only — Imagick rasterises SVG on read

    // -------------------------------------------------------------------------
    // Collections
    // -------------------------------------------------------------------------

    /** All valid case values as a plain string array — for fast in_array() checks. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Format cases that should appear in the UI target-format dropdowns.
     * Excludes aliases (jpeg) and input-only formats (svg).
     *
     * @return list<self>
     */
    public static function targetOptions(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn(self $f) => $f->isTargetSupported() && $f !== self::JPEG
        ));
    }

    // -------------------------------------------------------------------------
    // Per-case behaviour
    // -------------------------------------------------------------------------

    /** Human-readable label used in <option> elements. */
    public function label(): string
    {
        return match ($this) {
            self::JPG, self::JPEG => 'JPG',
            self::PNG             => 'PNG',
            self::BMP             => 'BMP',
            self::GIF             => 'GIF',
            self::ICO             => 'ICO',
            self::TIFF            => 'TIFF',
            self::WEBP            => 'WEBP',
            self::PDF             => 'PDF',
            self::SVG             => 'SVG',
        };
    }

    /**
     * Whether this format supports lossy quality settings.
     * Only JPG/JPEG/WEBP benefit from setImageCompressionQuality().
     */
    public function supportsQuality(): bool
    {
        return match ($this) {
            self::JPG, self::JPEG, self::WEBP => true,
            default                           => false,
        };
    }

    /**
     * Whether a file in this format can be used as a *source* for conversion.
     * All current formats are readable by Imagick (SVG via rsvg delegate).
     */
    public function isSourceSupported(): bool
    {
        return true;
    }

    /**
     * Whether this format can be used as a conversion *target*.
     * SVG output is not reliably supported across Imagick builds, so it is
     * input-only.
     */
    public function isTargetSupported(): bool
    {
        return $this !== self::SVG;
    }

    /**
     * Canonical format identifier string to pass to Imagick::setImageFormat().
     * Imagick expects 'jpeg' not 'jpg'.
     */
    public function imagickFormat(): string
    {
        return match ($this) {
            self::JPG, self::JPEG => 'jpeg',
            default               => $this->value,
        };
    }

    /**
     * Returns true if this format uses SVG MIME types and requires
     * special handling (skipping getimagesize(), using finfo only).
     */
    public function isSvg(): bool
    {
        return $this === self::SVG;
    }
}
