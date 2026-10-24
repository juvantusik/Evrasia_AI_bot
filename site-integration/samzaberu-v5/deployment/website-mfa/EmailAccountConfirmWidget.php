<?php
declare(strict_types=1);

namespace Evrasia\WebMfa;

/**
 * Independent account-inline current-email verification UI.
 *
 * NEVER render unless the host application has successfully installed the
 * authenticated POST endpoint + server-side OTP checks + active mail event.
 *
 * This module contains no PII, user-owned attributes, inline PHP expressions,
 * authentication secrets, or user-controlled JavaScript interpolation.
 * It appends an inline BUTTON next to an existing badge inside <p> and
 * constructs the dialog at <body> on click, so it cannot break account layout.
 */
final class EmailAccountConfirmWidget
{
    public const URL = '/local/ajax/ev-email-ownership.php';

    public static function render(bool $alreadyVerified, bool $operational): string
    {
        if ($alreadyVerified || !$operational) {
            return '';
        }

        $button = <<<'HTML'
<span class="ev-email-confirm-inline" style="display:inline-flex;align-items:center;margin-left:10px;vertical-align:middle">
<button type="button" class="ev-email-confirm-trigger" aria-haspopup="dialog"
 style="background:#fff;border:1px solid #64b1b5;color:#277b82;border-radius:7px;padding:5px 12px;font:inherit;font-size:14px;font-weight:600;line-height:1.4;cursor:pointer">
Подтвердить почту
</button></span>
HTML;
        // Bitrix may insert account HTML through AJAX or move inline scripts.
        // Register via the native page asset collector (works when template
        // output is buffered), AND render an external same-origin script tag
        // for regular first-load HTML. The JS itself binds a once-per-page
        // document-level delegated click listener; duplicates are harmless.
        // No user PII/secret/OTP is serialized into JS or an HTML attribute.
        $scriptUrl='/local/ajax/ev-email-confirm-widget.js?v=20261024c';
        try {
            if (class_exists(\Bitrix\Main\Page\Asset::class)) {
                \Bitrix\Main\Page\Asset::getInstance()->addJs($scriptUrl);
            }
        } catch (\Throwable) {
            // Keep the same-origin external <script> fallback.
        }

        return $button
            . "\n<script defer src=\""
            . htmlspecialchars($scriptUrl, ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8')
            . "\"></script>";
    }
}
