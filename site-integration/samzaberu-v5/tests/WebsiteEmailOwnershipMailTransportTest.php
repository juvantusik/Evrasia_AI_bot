<?php
declare(strict_types=1);
require_once __DIR__ . '/../deployment/website-mfa/EmailOwnershipMailTransport.php';

use Evrasia\WebMfa\EmailOwnershipMailTransport as Mail;

$passed = 0;
$test = static function (bool $ok, string $name) use (&$passed): void {
    if (!$ok) {
        throw new RuntimeException('assertion_' . $name);
    }
    $passed++;
};
try {
    $p=Mail::payload(' Guest@example.com ','000012','s1',54);
    $test(is_array($p),'valid_payload');
    $test($p['EVENT_NAME']===Mail::EVENT,'isolated_event_type');
    $test($p['MESSAGE_ID']===54 && $p['LID']==='s1','exact_template_and_site');
    $test($p['DUPLICATE']==='N','OTP_never_copied_to_global_Bitrix_all_bcc');
    $test($p['C_FIELDS']['VERIFY_CODE']==='000012','six_digits_with_leading_zero');
    $test($p['C_FIELDS']['EMAIL_TO']==='guest@example.com',
        'normalized_recipient');
    $test($p['C_FIELDS']['CODE_TTL_MINUTES']==='5','five_minute_mail_description');
    $test(count($p['C_FIELDS'])===3,'no_login_or_password_or_phone_fields');

    foreach ([
        ['','','s1',54],
        ['not-email','123456','s1',54],
        ['a@example.com','12345','s1',54],
        ['a@example.com','1234567','s1',54],
        ['a@example.com','00012x','s1',54],
        ['a@example.com','123456','',54],
        ['a@example.com','123456','s1;stuff',54],
        ['a@example.com','123456','s1',0],
        ['a@example.com','123456','s1',-3],
    ] as [$address,$code,$site,$template]) {
        $test(Mail::payload($address,$code,$site,$template)===null,
            'invalid_payload_rejected');
    }

    $received=[];
    $queued=Mail::submit('guest@example.com','123456','s1',54,
        static function(array $payload) use (&$received): bool {
            $received[]=$payload;
            return true;
        });
    $test($queued && count($received)===1,'queue_callback_called_once');
    $test($received[0]['EVENT_NAME']===Mail::EVENT,'no_unrelated_mail_event');
    $test(!Mail::submit('guest@example.com','123456','s1',54,
        static fn(array $payload): bool => false),
        'queue_failure_not_reported_as_delivery');
    $test(!Mail::submit('guest@example.com','123456','s1',54,
        static function(array $payload): bool {
            throw new RuntimeException('private SMTP password hidden');
        }), 'transport_exception_suppressed');
    $test(!Mail::submit('invalid','123456','s1',54,
        static function(array $payload): bool {
            throw new RuntimeException('callback_should_not_run');
        }), 'no_send_before_validating_recipient');
    $test(!Mail::bitrixSend(['EVENT_NAME'=>'OTHER_EVENT']),
        'unrelated_event_rejected_before_loading_Bitrix');

    echo 'PASS: mail event payload, recipient, code, template, safe queue failures '
        . $passed . " offline assertions\n";
    echo "MODE=OFFLINE_NO_BITRIX\nDATABASE_CONNECTION=NO\n";
    echo "EMAIL_SENT=NO\nSMS_SENT=NO\nPRODUCTION_WRITE=NO\n";
    echo "FINAL_STATUS=PASS\nFINAL_RC=0\n";
} catch (Throwable) {
    echo "FAIL: email ownership mail event offline contract\n";
    echo "FINAL_STATUS=FAIL\nFINAL_RC=1\n";
    exit(1);
}
