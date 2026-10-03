<?php

namespace App\Helpers;

use App\Helpers\Sms\Fast2SmsDriver;
use App\Helpers\Sms\Msg91Driver;
use App\Helpers\Sms\SmsDriverInterface;
use App\Helpers\Sms\TwilioDriver;
use App\Helpers\Sms\TwoFactorDriver;
use App\Models\Setting;
use App\Models\SmsTemplate;
use App\Models\SmsTemplateTranslation;
use Illuminate\Support\Facades\Log;

/**
 * Central SMS sender. Renders a multilanguage SMS template and dispatches it
 * through the currently-enabled gateway (settings key `sms_gateway`). Future
 * gateways just register a driver in driver().
 */
class SmsHelper
{
    /** Available gateway drivers, keyed by the `sms_gateway` setting value. */
    private static array $drivers = [
        'twilio'    => TwilioDriver::class,
        'msg91'     => Msg91Driver::class,
        'fast2sms'  => Fast2SmsDriver::class,
        'twofactor' => TwoFactorDriver::class,
    ];

    /** The enabled gateway key (settings). Empty string = all gateways off. */
    public static function activeGateway(): string
    {
        return strtolower(trim((string) Setting::get_value('sms_gateway')));
    }

    /** Resolve the enabled gateway driver, or null when SMS is switched off. */
    public static function driver(): ?SmsDriverInterface
    {
        $class = self::$drivers[self::activeGateway()] ?? null;
        return $class ? new $class() : null;
    }

    /**
     * Render a template of the given type in the recipient's language and send it.
     *
     * @param string   $to           Phone (country code + number).
     * @param string   $type         sms_templates.type (e.g. 'customer_order_received', 'otp').
     * @param array    $placeholders ['order_id' => 123, 'customer_name' => '...'] — also MSG91 vars.
     * @param int|null $languageId   Recipient language; falls back to the default language.
     */
    public static function sendByTemplate(string $to, string $type, array $placeholders = [], ?int $languageId = null): bool
    {
        if ($to === '') {
            return false;
        }

        // All gateways off -> nothing to send through.
        $driver = self::driver();
        if (!$driver) {
            return false;
        }

        $template = SmsTemplate::where('type', $type)->first();
        if (!$template) {
            Log::warning("SMS template not found for type: {$type}");
            return false;
        }

        $defaultLangId = CommonHelper::getDefaultLanguageId();
        $languageId = $languageId ?: $defaultLangId;

        // Prefer the recipient's language, else the default language's translation.
        $translation = SmsTemplateTranslation::where('sms_template_id', $template->id)
            ->where('language_id', $languageId)->first()
            ?: SmsTemplateTranslation::where('sms_template_id', $template->id)
                ->where('language_id', $defaultLangId)->first();

        $message = $translation->message ?? $template->message ?? '';
        // DLT template id for the CURRENTLY-active gateway (MSG91/Fast2SMS differ).
        $gatewayIds = ($translation && is_array($translation->gateway_template_ids)) ? $translation->gateway_template_ids : [];
        $gatewayTemplateId = $gatewayIds[self::activeGateway()] ?? null;

        // App name is always available as a placeholder.
        $placeholders = array_merge(['app_name' => Setting::get_value('app_name') ?: ''], $placeholders);

        $rendered = self::render($message, $placeholders);

        return $driver->send($to, $rendered, [
            'template_id' => $gatewayTemplateId,
            'vars'        => $placeholders,
        ]);
    }

    /** Replace {{key}} tokens with placeholder values. */
    private static function render(string $message, array $placeholders): string
    {
        foreach ($placeholders as $key => $value) {
            $message = str_replace('{{' . $key . '}}', (string) $value, $message);
        }
        return $message;
    }
}
