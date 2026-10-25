<?php

declare(strict_types=1);

namespace Mata\Services\Advisory\Report;

use Com\Tecnick\Pdf\Tcpdf;

/** Page chrome of AdvisoryReportPdfWriter: background, header and footer on every page. */
final class AdvisoryReportPdfDocument extends Tcpdf
{
    public function __construct(
        private string $branding,
        private string $subtitle,
        private string $footer,
        string $unit,
        bool $isunicode,
        bool $subsetfont,
        bool $compress,
    ) {
        parent::__construct($unit, $isunicode, $subsetfont, $compress);
    }

    public function defaultPageContent(int $pid = -1): string
    {
        $page = $this->page->getPage($pid);
        $width = (float) $page['width'];
        $height = (float) $page['height'];
        $fill = static fn (string $color): array => ['all' => ['fillColor' => $color, 'lineWidth' => 0]];

        $out = $this->graph->getRect(0, 0, $width, $height, 'F', $fill('#0b0d12'));
        $out .= $this->graph->getRect(0, 0, $width, 14, 'F', $fill('#0e1015'));
        $out .= $this->graph->getRect(0, 14, $width, 0.3, 'F', $fill('#2f6f7d'));

        $font = $this->font->insert($this->pon, 'dejavusans', 'B', 9);
        $out .= $font['out'];
        $out .= $this->color->getPdfColor('#e8eaf0', false);
        $out .= $this->getTextCell(strtoupper($this->branding), 16, 5.2, 0, 0, 0, 0, 'T', 'L');
        $this->font->popLastFont();

        $font = $this->font->insert($this->pon, 'dejavusans', '', 7.5);
        $out .= $font['out'];
        $out .= $this->color->getPdfColor('#8b93a5', false);
        $out .= $this->getTextCell($this->subtitle, $width - 116, 5.7, 100, 0, 0, 0, 'T', 'R');
        $this->font->popLastFont();

        $font = $this->font->insert($this->pon, 'dejavusans', '', 6.5);
        $out .= $font['out'];
        $out .= $this->color->getPdfColor('#5a6172', false);
        $out .= $this->getTextCell($this->footer, 16, $height - 10, $width - 50, 0, 0, 0, 'T', 'L');
        $out .= $this->getTextCell('Page ' . ($pid + 1), $width - 40, $height - 10, 24, 0, 0, 0, 'T', 'R');
        $this->font->popLastFont();

        return $out;
    }
}
