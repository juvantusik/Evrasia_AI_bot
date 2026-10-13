# POSIX AWK. Classifies fixed CONFIRMED source anchors; never emits PHP source.
function emit(tag, value) { printf "%s=%s\n", tag, value }
function has_y(s) { return s ~ /["\047]y["\047]/ }
function has_n(s) { return s ~ /["\047]n["\047]/ }
{
  if (NR < 860 || NR > 1140) next
  low=tolower($0)

  if (NR==869) {
    if (low ~ /userphoneauthtable[[:space:]]*::[[:space:]]*getlist/) a869++
    else failed++
  }
  if (NR==876) {
    if (low ~ /userphoneauthtable[[:space:]]*::[[:space:]]*add/) a876++
    else failed++
  }
  if (NR==880) {
    if (low ~ /userphoneauthtable[[:space:]]*::[[:space:]]*getrowbyid/) {
      a880++
      emit("PHONE_ROW_READ_LINE_880", "YES")
      if (low ~ /[$]phoneuser[[:space:]]*=[[:space:]]*.*userphoneauthtable/) {
        emit("ROW_READ_ASSIGNED_TO_PHONEUSER_SAME_LINE", "YES")
      } else emit("ROW_READ_ASSIGNED_TO_PHONEUSER_SAME_LINE", "NOT_PROVEN")
    } else failed++
  }
  if (NR==891) {
    if (low ~ /[$]phoneuser[[:space:]]*\[[[:space:]]*["\047]confirmed["\047][[:space:]]*\][[:space:]]*=/) {
      a891++
      value=low
      sub(/^.*\][[:space:]]*=/, "", value)
      if (value ~ /^[[:space:]]*["\047]y["\047]/) emit("PHONEUSER_CONFIRMED_ASSIGNMENT_VALUE", "LITERAL_Y")
      else if (value ~ /^[[:space:]]*["\047]n["\047]/) emit("PHONEUSER_CONFIRMED_ASSIGNMENT_VALUE", "LITERAL_N")
      else emit("PHONEUSER_CONFIRMED_ASSIGNMENT_VALUE", "OTHER_OR_MULTILINE")
    } else failed++
  }
  if (NR==1012) {
    if (index(low,"confirmed")>0) {
      a1012++
      if (index(low,"phone_auth.confirmed")>0 ||
          index(low,"phoneauth.confirmed")>0)
        emit("CONFIRM_1012_FIELD_KIND", "PHONE_AUTH_RELATION")
      else if (low ~ /\[[[:space:]]*["\047]confirmed["\047][[:space:]]*\]/)
        emit("CONFIRM_1012_FIELD_KIND", "PHP_ARRAY_SUBSCRIPT")
      else if (low ~ /["\047]confirmed["\047][[:space:]]*=>/)
        emit("CONFIRM_1012_FIELD_KIND", "PHP_ARRAY_KEY")
      else emit("CONFIRM_1012_FIELD_KIND", "OTHER_REFERENCE")
      emit("CONFIRM_1012_HAS_Y_LITERAL",has_y(low)?"YES":"NO")
      emit("CONFIRM_1012_HAS_N_LITERAL",has_n(low)?"YES":"NO")
    } else failed++
  }

  if (NR>=892 && NR<=938 && index(low,"$phoneuser")>0) directPhoneUserAfter++
  if (NR>=892 && NR<=938) {
    if (low ~ /userphoneauthtable[[:space:]]*::[[:space:]]*(update|delete|add|set|save)[[:space:]]*\(/) requestWriter++
    if (low ~ /->[[:space:]]*(update|save|add|set|delete)[[:space:]]*\(/)
      printf "REQUEST_AFTER_891_INSTANCE_MUTATOR_LINE=%d\n", NR
  }
  if (NR>=954 && NR<=1140) {
    if (low ~ /userphoneauthtable[[:space:]]*::[[:space:]]*(update|delete|add|set|save)[[:space:]]*\(/) confirmWriter++
    if (low ~ /->[[:space:]]*(update|save|add|set|delete)[[:space:]]*\(/)
      printf "CONFIRM_INSTANCE_MUTATOR_LINE=%d\n", NR
    if (low ~ /cuser[[:space:]]*::[[:space:]]*[a-z_][a-z0-9_]*[[:space:]]*\(/) {
      if (NR>=985 && NR<=1100) printf "CONFIRM_CUSER_STATIC_CALL_LINE=%d\n", NR
    }
  }
}
END {
  if (failed || a869!=1 || a876!=1 || a880!=1 || a891!=1 || a1012!=1) {
    emit("SOURCE_ANCHORS_VALID","NO")
    exit 2
  }
  emit("SOURCE_ANCHORS_VALID","YES")
  emit("PHONEUSER_DIRECT_REFS_AFTER_891",directPhoneUserAfter+0)
  emit("REQUEST_AFTER_891_NATIVE_PHONE_TABLE_MUTATOR_CALLS",requestWriter+0)
  emit("CONFIRM_NATIVE_PHONE_TABLE_MUTATOR_CALLS",confirmWriter+0)
  emit("STATIC_SOURCE_ONLY","YES")
  emit("ACTUAL_DB_PERSISTENCE_PROVEN","NO")
}
