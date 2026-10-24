<?php
declare(strict_types=1);

namespace Evrasia\WebMfa;

/**
 * Small accessible status icon placed immediately AFTER the account email.
 * Input is exclusively the server-side VerifiedEmailStatus::evaluate result.
 * It cannot set, mutate or infer email verification.
 * Not installed in Bitrix until the live account template insertion is pinned.
 */
final class EmailVerificationBadge
{
    /**
     * @param array{verified:bool,login_email_allowed:bool,icon:string,label:string,css_class:string} $policy
     */
    public static function render(array $policy): string
    {
        $verified = ($policy['verified'] ?? null) === true
            && ($policy['login_email_allowed'] ?? null) === true
            && ($policy['icon'] ?? null) === 'check-circle'
            && ($policy['css_class'] ?? null) === 'email-verified';

        $label = $verified
            ? 'Email подтверждён'
            : 'Email не подтверждён';

        $variant = $verified
            ? 'ev-email-proof--verified'
            : 'ev-email-proof--unverified';

        $color = $verified ? '#188451' : '#c84448';

        // The glyph paths are static. No user-controlled email, address,
        // HTML, title or injected attributes are emitted.
        $drawing = $verified
            ? '<path d="M5 12.5l4.1 4.1L19 7" />'
            : '<path d="M7 7l10 10M17 7L7 17" />';

        return '<span class="ev-email-proof ' . $variant . '"'
            . ' role="img" aria-label="' . $label . '"'
            . ' title="' . $label . '"'
            . ' style="display:inline-flex;vertical-align:middle;'
            . 'align-items:center;justify-content:center;'
            . 'margin-left:8px;flex:0 0 auto;color:' . $color . ';">'
            . '<svg xmlns="http://www.w3.org/2000/svg" '
            . 'width="18" height="18" viewBox="0 0 24 24"'
            . ' fill="none" stroke="currentColor" stroke-width="2.5"'
            . ' stroke-linecap="round" stroke-linejoin="round"'
            . ' aria-hidden="true">'
            . '<circle cx="12" cy="12" r="10" />'
            . $drawing
            . '</svg></span>';
    }
}
