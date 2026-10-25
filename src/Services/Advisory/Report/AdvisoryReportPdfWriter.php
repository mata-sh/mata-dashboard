<?php

declare(strict_types=1);

namespace Mata\Services\Advisory\Report;

use RuntimeException;

/**
 * Lays out report HTML on dark A4 pages with tc-lib-pdf: every page gets the panel
 * background, the brand header and a footer with the page number.
 *
 * The DejaVu fonts are converted once and shipped in resources/fonts/pdf/, so PDF output
 * needs no font build step at install time.
 */
final class AdvisoryReportPdfWriter
{
    public const FONT_DIR = 'resources/fonts/pdf';

    private const MARGIN_X = 16.0;
    private const CONTENT_TOP = 22.0;
    private const CONTENT_BOTTOM = 16.0;

    public function __construct(private string $fontPath) {}

    public function write(string $html, string $title, string $branding, string $subtitle, string $footer): string
    {
        // tc-lib-pdf reads its font directory from this constant only.
        if (!defined('K_PATH_FONTS')) {
            define('K_PATH_FONTS', $this->fontPath);
        } elseif (realpath((string) constant('K_PATH_FONTS')) !== realpath($this->fontPath)) {
            throw new RuntimeException('K_PATH_FONTS already points to another font directory.');
        }

        $pdf = new AdvisoryReportPdfDocument($branding, $subtitle, $footer, unit: 'mm', isunicode: true, subsetfont: true, compress: true);
        $pdf->setCreator($branding);
        $pdf->setAuthor($branding);
        $pdf->setTitle($title);
        $pdf->setSubject('Security advisories');
        $pdf->enableDefaultPageContent();

        $font = $pdf->font->insert($pdf->pon, 'dejavusans', '', 9);
        $page = $pdf->addPage([
            'format' => 'A4',
            'orientation' => 'P',
            'margin' => [
                'PL' => self::MARGIN_X, 'PR' => self::MARGIN_X,
                'PT' => 8.0, 'HB' => 16.0, 'CT' => self::CONTENT_TOP,
                'CB' => self::CONTENT_BOTTOM, 'FT' => 12.0, 'PB' => 8.0,
            ],
        ]);
        $pdf->page->addContent($font['out']);
        $pdf->addHTMLCell($html, self::MARGIN_X, self::CONTENT_TOP, (float) $page['width'] - 2 * self::MARGIN_X);

        return $pdf->getOutPDFString();
    }
}
