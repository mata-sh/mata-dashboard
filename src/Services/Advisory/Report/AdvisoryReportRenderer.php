<?php

declare(strict_types=1);

namespace Mata\Services\Advisory\Report;

use InvalidArgumentException;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

/**
 * Renders an AdvisoryReport as JSON, Markdown, HTML (email layout) or PDF. Uses its own Twig
 * environment: the Markdown filters cannot be added to the web app's after it rendered.
 */
final class AdvisoryReportRenderer
{
    public const FORMATS = ['pdf', 'md', 'html', 'json'];

    private Environment $twig;

    public function __construct(
        string $templatesPath,
        private AdvisoryReportPresenter $presenter,
        private AdvisoryReportPdfWriter $pdfWriter,
    ) {
        $this->twig = new Environment(new FilesystemLoader($templatesPath), ['autoescape' => 'html', 'strict_variables' => true]);
        $this->twig->addFilter(new TwigFilter('md', self::markdownInline(...)));
        $this->twig->addFilter(new TwigFilter('md_code', static fn (string $text): string => str_replace('`', "'", $text)));
    }

    public function render(AdvisoryReport $report, string $format): string
    {
        return match ($format) {
            'json' => $this->json($report),
            'md' => $this->markdown($report),
            'html' => $this->html($report),
            'pdf' => $this->pdf($report),
            default => throw new InvalidArgumentException("Unknown report format {$format}; use " . implode(', ', self::FORMATS) . '.'),
        };
    }

    public function json(AdvisoryReport $report): string
    {
        return json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    public function markdown(AdvisoryReport $report): string
    {
        $markdown = $this->twig->render('reports/advisories.md.twig', $this->presenter->present($report));

        // Template control lines leave runs of blank lines behind; Markdown needs at most one.
        return trim(preg_replace("/\n{3,}/", "\n\n", $markdown) ?? $markdown) . "\n";
    }

    public function html(AdvisoryReport $report): string
    {
        return $this->twig->render('reports/advisories.html.twig', $this->presenter->present($report));
    }

    public function pdf(AdvisoryReport $report): string
    {
        $data = $this->presenter->present($report);

        return $this->pdfWriter->write(
            $this->twig->render('reports/advisories.pdf.twig', $data),
            title: $data['title'],
            branding: $data['branding'],
            accent: $data['worst']['fill'],
            footer: "{$data['branding']}://advisories · {$data['period']['since']} – {$data['period']['until']} · generated {$data['generated_at']}",
        );
    }

    public static function fileName(AdvisoryReport $report, string $format): string
    {
        return sprintf('security-advisories-%s-%s.%s', $report->query->since->format('Ymd'), $report->query->until->format('Ymd'), $format);
    }

    public static function contentType(string $format): string
    {
        return match ($format) {
            'pdf' => 'application/pdf',
            'md' => 'text/markdown; charset=utf-8',
            'html' => 'text/html; charset=utf-8',
            default => 'application/json',
        };
    }

    /** Escapes text for inline Markdown, table cells included; newlines become spaces. */
    private static function markdownInline(?string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', (string) $text) ?? '';

        return preg_replace('/([\\\`*_\[\]<>|#])/', '\\\$1', $text) ?? $text;
    }
}
