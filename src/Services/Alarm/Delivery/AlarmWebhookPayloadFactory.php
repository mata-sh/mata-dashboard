<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Delivery;

use JsonException;
use Mata\Config\Monitoring\Section\WebhookChannel;
use Mata\Services\Alarm\Delivery\Dto\AlarmDeliveryView;
use RuntimeException;
use Twig\Environment;
use Twig\Error\Error as TwigError;
use Twig\Loader\LoaderInterface;

/** Renders `templates/webhooks/<template>.json.twig` into a validated JSON payload. */
final class AlarmWebhookPayloadFactory
{
    private Environment $twig;

    public function __construct(LoaderInterface $templates, private AlarmTemplateDataFactory $templateData)
    {
        // HTML autoescaping would corrupt JSON (`&quot;`), so templates json_encode every value instead.
        // Strict variables turn a missing param such as chat_id into a failed delivery, not an empty field.
        $this->twig = new Environment($templates, [
            'autoescape' => false,
            'strict_variables' => true,
        ]);
    }

    /**
     * @return array<string,mixed>
     *
     * @throws RuntimeException when rendering fails or the template does not produce a JSON object
     */
    public function create(AlarmDeliveryView $view, WebhookChannel $channel): array
    {
        $template = "webhooks/{$channel->template}.json.twig";
        $data = $this->templateData->create($view) + [
            'channel' => ['name' => $channel->name, 'params' => $channel->params],
        ];

        try {
            $json = $this->twig->render($template, $data);
        } catch (TwigError $exception) {
            throw new RuntimeException("Unable to render webhook template {$template}: {$exception->getRawMessage()}", 0, $exception);
        }

        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Webhook template {$template} produced invalid JSON: {$exception->getMessage()}", 0, $exception);
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new RuntimeException("Webhook template {$template} must produce a JSON object.");
        }

        return $payload;
    }
}
