export const IT_BLOCK_NAME_SUFFIX = " - блок ИТ";

export const shouldStripAntiFraudUnblockNameSuffix = (
  success: boolean,
  result: string,
): boolean => success && result === "unblocked";

export const buildAntiFraudUnblockAccountCacheUpdate = (
  bitrixUserId: number,
  active: boolean,
  blocked: boolean,
  reason: string | null,
  stripItBlockSuffix: boolean,
) => ({
  text: `UPDATE anti_fraud_accounts
     SET bitrix_active = $2,
         bitrix_blocked = $3,
         bitrix_block_reason = $4,
         display_name = CASE
           WHEN $5::boolean
            AND display_name IS NOT NULL
            AND right(display_name, char_length($6::text)) = $6::text
           THEN left(display_name, char_length(display_name) - char_length($6::text))
           ELSE display_name
         END,
         last_synced_at = now()
     WHERE bitrix_user_id = $1`,
  values: [
    bitrixUserId,
    active,
    blocked,
    reason,
    stripItBlockSuffix,
    IT_BLOCK_NAME_SUFFIX,
  ],
});
