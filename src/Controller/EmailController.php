<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\JsonResponse;
use App\Core\RequestLogger;

/**
 * Virtual Email Service Controller — stubs for the real email/notifier service.
 *
 * The CMS backend sends emails through an HTTP email service. In the local stack,
 * these calls are routed to this stub which logs the email details (including OTP
 * codes) and returns 200 OK.
 *
 * To retrieve OTP codes: check service-virtualization container logs:
 *   docker logs service-virtualization-local --tail 20
 *
 * Routes (base path from API_URL_EMAIL_SERVICE = /api/stub/email):
 *   POST /api/stub/email/email/verification-otp   -- OTP verification email
 *   POST /api/stub/email/cms/reset-password        -- Password recovery email
 *   POST /api/stub/email/cms/confirm-password      -- Password confirmation email
 */
final class EmailController
{
    /**
     * POST /api/stub/email/email/verification-otp
     *
     * CMS backend sends: { to: "user@company.com", code: "1234" }
     * The `code` is the OTP token the user must enter to verify their email.
     */
    public static function sendOtp(array $body): never
    {
        $to = $body['to'] ?? 'unknown';
        $code = $body['code'] ?? 'unknown';

        // Log prominently so developers can find the OTP in container logs
        error_log("╔══════════════════════════════════════════════════╗");
        error_log("║  VIRTUAL EMAIL: OTP Verification                ║");
        error_log("║  To:   {$to}");
        error_log("║  Code: {$code}");
        error_log("╚══════════════════════════════════════════════════╝");

        $startTime = defined('REQUEST_START_TIME') ? REQUEST_START_TIME : microtime(true);
        $durationMs = (int)((microtime(true) - $startTime) * 1000);

        RequestLogger::log(
            null,
            'POST',
            '/api/stub/email/email/verification-otp',
            null,
            $body,
            200,
            ['success' => true, 'virtual' => true, 'otp_code' => $code],
            $durationMs
        );

        JsonResponse::send([
            'success' => true,
            'virtual' => true,
            'message' => "OTP [{$code}] logged for [{$to}]",
        ], 200);
    }

    /**
     * POST /api/stub/email/cms/reset-password
     *
     * CMS backend sends: { email: "user@company.com", url: "https://..." }
     */
    public static function resetPassword(array $body): never
    {
        $email = $body['email'] ?? 'unknown';
        $url = $body['url'] ?? 'none';

        error_log("╔══════════════════════════════════════════════════╗");
        error_log("║  VIRTUAL EMAIL: Password Recovery               ║");
        error_log("║  To:  {$email}");
        error_log("║  URL: {$url}");
        error_log("╚══════════════════════════════════════════════════╝");

        $startTime = defined('REQUEST_START_TIME') ? REQUEST_START_TIME : microtime(true);
        $durationMs = (int)((microtime(true) - $startTime) * 1000);

        RequestLogger::log(
            null,
            'POST',
            '/api/stub/email/cms/reset-password',
            null,
            $body,
            200,
            ['success' => true, 'virtual' => true],
            $durationMs
        );

        JsonResponse::send([
            'success' => true,
            'virtual' => true,
            'message' => "Recovery email logged for [{$email}]",
        ], 200);
    }

    /**
     * POST /api/stub/email/cms/confirm-password
     *
     * CMS backend sends: { email: "user@company.com", url: "https://..." }
     */
    public static function confirmPassword(array $body): never
    {
        $email = $body['email'] ?? 'unknown';
        $url = $body['url'] ?? 'none';

        error_log("╔══════════════════════════════════════════════════╗");
        error_log("║  VIRTUAL EMAIL: Password Confirmation           ║");
        error_log("║  To:  {$email}");
        error_log("║  URL: {$url}");
        error_log("╚══════════════════════════════════════════════════╝");

        $startTime = defined('REQUEST_START_TIME') ? REQUEST_START_TIME : microtime(true);
        $durationMs = (int)((microtime(true) - $startTime) * 1000);

        RequestLogger::log(
            null,
            'POST',
            '/api/stub/email/cms/confirm-password',
            null,
            $body,
            200,
            ['success' => true, 'virtual' => true],
            $durationMs
        );

        JsonResponse::send([
            'success' => true,
            'virtual' => true,
            'message' => "Confirmation email logged for [{$email}]",
        ], 200);
    }
}
