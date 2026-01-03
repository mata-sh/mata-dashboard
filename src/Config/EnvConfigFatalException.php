<?php

declare(strict_types=1);

namespace Watcher\Config;

use RuntimeException;
use Throwable;

/**
 * Exception thrown when configuration initialization encounters an unrecoverable error
 * that should halt application startup.
 */
final class EnvConfigFatalException extends RuntimeException
{
    private string $userMessage;
    private array $context;
    private const HTML_ESCAPE_FLAGS = ENT_QUOTES | ENT_SUBSTITUTE;

    /**
     * @param string         $message     Technical message for logging
     * @param string         $userMessage User-friendly message for display
     * @param array          $context     Additional context for logging
     * @param int            $code        Exception code
     * @param null|Throwable $previous    Previous exception
     */
    public function __construct(
        string $message,
        string $userMessage,
        array $context = [],
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->userMessage = $userMessage;
        $this->context = $context;
    }

    /**
     * Get user-friendly error message suitable for display to end users.
     */
    public function getUserMessage(): string
    {
        return $this->userMessage;
    }

    /**
     * Get additional context for logging.
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Escape content for safe HTML rendering.
     */
    private static function escape(string $value): string
    {
        return htmlspecialchars($value, self::HTML_ESCAPE_FLAGS, 'UTF-8');
    }

    /**
     * Wrap content in a complete HTML document with embedded CSS.
     *
     * @param string $title   Page title
     * @param string $heading Main heading text
     * @param string $content HTML content to display
     * @param string $type    Error type ('warning', 'error', 'info')
     */
    private static function wrapInHtmlDocument(string $title, string $heading, string $content, string $type = 'error'): string
    {
        $colors = [
            'warning' => ['bg' => '#fff3cd', 'border' => '#ffc107', 'text' => '#856404', 'icon' => '⚠️'],
            'error' => ['bg' => '#f8d7da', 'border' => '#dc3545', 'text' => '#721c24', 'icon' => '❌'],
            'info' => ['bg' => '#d1ecf1', 'border' => '#17a2b8', 'text' => '#0c5460', 'icon' => 'ℹ️'],
        ];

        $color = $colors[$type] ?? $colors['error'];

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            line-height: 1.6;
        }

        .error-container {
            background: white;
            max-width: 700px;
            width: 100%;
            border-radius: 8px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }

        .error-header {
            background: {$color['bg']};
            border-left: 4px solid {$color['border']};
            padding: 30px;
            color: {$color['text']};
        }

        .error-header h1 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .error-header .icon {
            font-size: 48px;
            margin-bottom: 15px;
            display: block;
        }

        .error-content {
            padding: 30px;
            color: #333;
        }

        .error-content p {
            margin-bottom: 15px;
        }

        .error-content strong {
            display: block;
            margin: 20px 0 10px 0;
            color: #555;
            font-weight: 600;
        }

        .error-content ul {
            list-style: none;
            margin: 15px 0;
            padding-left: 0;
        }

        .error-content ul li {
            padding: 8px 12px;
            margin: 5px 0;
            background: #f8f9fa;
            border-left: 3px solid {$color['border']};
            border-radius: 3px;
            font-family: "Courier New", monospace;
            font-size: 14px;
        }

        .error-footer {
            background: #f8f9fa;
            padding: 20px 30px;
            border-top: 1px solid #dee2e6;
            font-size: 14px;
            color: #6c757d;
        }

        code {
            background: #f8f9fa;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: "Courier New", monospace;
            font-size: 13px;
            color: #e83e8c;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-header">
            <h1><span class="icon">{$color['icon']}</span> {$heading}</h1>
        </div>
        <div class="error-content">
            {$content}
        </div>
        <div class="error-footer">
            Check application logs for technical details.
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Create exception when config files were created and need user configuration.
     *
     * @param array<string> $createdFiles List of config files that were created
     */
    public static function setupRequired(array $createdFiles): self
    {
        $fileItems = array_map(
            fn ($file) => '<li>' . self::escape($file) . '</li>',
            $createdFiles
        );
        $fileList = implode("\n                ", $fileItems);

        $content = <<<HTML
            <p>Configuration files have been created successfully from templates.</p>
            <strong>Created files:</strong>
            <ul>
                {$fileList}
            </ul>
            <p>Please review and update these files with your actual configuration values, then restart the application.</p>
        HTML;

        return new self(
            message: 'Configuration files created from templates; user setup required',
            userMessage: self::wrapInHtmlDocument(
                title: 'Configuration Setup Required',
                heading: 'Configuration Setup Required',
                content: $content,
                type: 'warning'
            ),
            context: ['created_files' => $createdFiles]
        );
    }

    /**
     * Create exception when config file creation failed.
     *
     * @param array<string> $failedFiles List of files that could not be created
     * @param string        $exampleDir  Path to example template directory
     */
    public static function setupFailed(array $failedFiles, string $exampleDir): self
    {
        $fileItems = array_map(
            fn ($file) => '<li>' . self::escape($file) . '</li>',
            $failedFiles
        );
        $fileList = implode("\n                ", $fileItems);
        $exampleDirEscaped = self::escape($exampleDir);

        $content = <<<HTML
            <p>The application could not automatically create the required configuration files.</p>
            <strong>Missing files:</strong>
            <ul>
                {$fileList}
            </ul>
            <p>Please manually copy the template files from:</p>
            <p><code>{$exampleDirEscaped}</code></p>
            <p>to your configuration directory and update them with your settings.</p>
        HTML;

        return new self(
            message: 'Failed to create required configuration files',
            userMessage: self::wrapInHtmlDocument(
                title: 'Configuration Error',
                heading: 'Critical Configuration Error',
                content: $content,
                type: 'error'
            ),
            context: [
                'failed_files' => $failedFiles,
                'example_dir' => $exampleDir,
            ]
        );
    }

    /**
     * Create exception when config validation failed with critical errors.
     *
     * @param array<int, array{identifier: string, message: string}> $errors Critical validation errors
     */
    public static function validationFailed(array $errors): self
    {
        $errorItems = array_map(
            function ($error): string {
                $identifier = self::escape($error['identifier']);
                $message = self::escape($error['message']);

                return "<li><strong>[{$identifier}]</strong> {$message}</li>";
            },
            $errors
        );
        $errorList = implode("\n                ", $errorItems);

        $content = <<<HTML
            <p>Critical configuration errors were detected that prevent the application from starting.</p>
            <strong>Errors found:</strong>
            <ul>
                {$errorList}
            </ul>
            <p>Please check your configuration files and fix the errors listed above.</p>
        HTML;

        return new self(
            message: 'Configuration validation failed with critical errors',
            userMessage: self::wrapInHtmlDocument(
                title: 'Configuration Validation Failed',
                heading: 'Configuration Validation Failed',
                content: $content,
                type: 'error'
            ),
            context: ['validation_errors' => $errors]
        );
    }

    /**
     * Create exception when config loading failed.
     *
     * @param string         $message  Descriptive error message
     * @param null|Throwable $previous Original exception that caused the failure
     */
    public static function loadFailed(string $message, ?Throwable $previous = null): self
    {
        $messageEscaped = self::escape($message);

        $content = <<<HTML
            <p>The application failed to initialize due to a configuration loading error.</p>
            <strong>Error details:</strong>
            <p><code>{$messageEscaped}</code></p>
            <p>Please verify that your configuration files are valid and readable.</p>
        HTML;

        return new self(
            message: "Configuration loading failed: {$message}",
            userMessage: self::wrapInHtmlDocument(
                title: 'Application Initialization Failed',
                heading: 'Application Initialization Failed',
                content: $content,
                type: 'error'
            ),
            context: [
                'original_error' => $previous?->getMessage(),
                'original_exception' => $previous ? get_class($previous) : null,
            ],
            previous: $previous
        );
    }
}
