# POSIX AWK: source-only CONFIRMED field-array lineage, no original PHP lines emitted.
  function emit(line, tag, detail) {
    if (detail == "") printf "LINE=%d TAG=%s\n", line, tag
    else printf "LINE=%d TAG=%s IDENT=%s\n", line, tag, detail
    tags++
  }
  function lhs_var(s, x) {
    x=tolower(s)
    if (match(x, /^[[:space:]]*[$][a-z_][a-z0-9_]*[[:space:]]*=/)) {
      x=substr(x,RSTART,RLENGTH)
      sub(/=.*/, "", x)
      gsub(/[[:space:]]/, "", x)
      if (length(x)<=48) return x
    }
    return ""
  }
  function extract_field_var(s, f) {
    f=tolower(s)
    if (match(f, /[$][a-z_][a-z0-9_]*[[:space:]]*\[[[:space:]]*["\047]confirmed["\047][[:space:]]*\]/)) {
      f=substr(f,RSTART,RLENGTH)
      sub(/\[.*/, "", f)
      gsub(/[[:space:]]/, "", f)
      if (length(f)<=48) return f
    }
    return ""
  }
  function direct_methods(line, src, rest, call) {
    rest=tolower(src)
    while (match(rest, /(userphoneauthtable|cuser|usertable)[[:space:]]*::[[:space:]]*[a-z_][a-z0-9_]*[[:space:]]*\(/)) {
      call=substr(rest,RSTART,RLENGTH)
      gsub(/[[:space:]]/, "", call)
      sub(/\($/, "", call)
      if (length(call)<=72) emit(line, "CLASS_METHOD_REFERENCE", call)
      rest=substr(rest,RSTART+RLENGTH)
      direct_calls++
    }
  }
  {
    if (NR<840 || NR>1040) next
    src=$0
    low=tolower(src)
    snippet[NR]=low

    # Assert these four already observed anchor categories, not PHP values.
    if (NR==869 || NR==876 || NR==880) {
      if (low !~ /userphoneauthtable[[:space:]]*::/) wrong++
      else phoneanchors++
    }
    if (NR==891) {
      if (low !~ /\[[[:space:]]*["\047]confirmed["\047][[:space:]]*\][[:space:]]*=/) wrong++
      else {
        confirmed_var=extract_field_var(low)
        confirmed_anchor++
        emit(NR, "CONFIRMED_ARRAY_WRITE_RECEIVER", confirmed_var=="" ? "UNRESOLVED" : confirmed_var)
      }
    }
    if (NR==1012) {
      if (index(low,"confirmed")==0) wrong++
      else {
        confirm_ref++
        var_1012=extract_field_var(low)
        emit(NR,"CONFIRM_METHOD_CONFIRMED_RECEIVER",var_1012==""?"UNRESOLVED":var_1012)
      }
    }
  }
  END {
    if (wrong>0 || phoneanchors!=3 || confirmed_anchor!=1 || confirm_ref!=1) {
      printf "SOURCE_ANCHORS_VALID=NO\n"
      exit 2
    }
    printf "SOURCE_ANCHORS_VALID=YES\n"
    printf "CONFIRMED_ARRAY_VARIABLE=%s\n", confirmed_var==""?"UNRESOLVED":confirmed_var

    # Print fixed classifications for precisely known V4 source ranges;
    # no original source line, function argument or field value is emitted.
    for (n=869;n<=938;n++) {
      s=snippet[n]
      if (s=="") continue

      v=lhs_var(s)
      if (v!="") emit(n,"PLAIN_ASSIGNMENT_RECEIVER",v)
      if (index(s,"confirmed")>0) emit(n,"CONFIRMED_NAME_REFERENCE","")
      if (index(s,"phone_number")>0) emit(n,"PHONE_NUMBER_NAME_REFERENCE","")

      direct_methods(n,s)
      if (s ~ /->[[:space:]]*(update|save|add|set|delete)[[:space:]]*\(/)
        emit(n,"INSTANCE_MUTATOR_METHOD_REFERENCE","")
      if (s ~ /[^a-z_0-9]generatephonecode[[:space:]]*\(/)
        emit(n,"PHONE_CODE_GENERATION_REFERENCE","")

      # Once the receiver is resolved, track all lexical uses after its
      # assignment. Lexical proximity != an actual DB write.
      if (confirmed_var!="" && n>891 && index(s,confirmed_var)>0) {
        count_references++
        if (count_references<=40) emit(n,"CONFIRMED_RECEIVER_LEXICAL_REUSE","")
      }
    }
    for (n=992;n<=1034;n++) {
      s=snippet[n]
      if (s=="") continue
      if (index(s,"confirmed")>0) emit(n,"CONFIRM_METHOD_CONFIRMED_NAME_REFERENCE","")
      if (s ~ /->[[:space:]]*(update|save|add)[[:space:]]*\(/)
        emit(n,"CONFIRM_METHOD_INSTANCE_MUTATOR","")
      direct_methods(n,s)
    }
    printf "DIRECT_CLASS_METHOD_CALL_MARKERS=%d\n", direct_calls
    printf "CONFIRMED_RECEIVER_REUSES_AFTER_891=%d\n", count_references
    printf "STATIC_METADATA_TAGS=%d\n", tags
  }
