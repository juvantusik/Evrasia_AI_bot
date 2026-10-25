<?php
declare(strict_types=1);

/**
 * Classifies only the two $DB->Query(...) statements inside the installed
 * CartApiService::orderCreatedEvent, without ever executing site code or
 * printing SQL, string literals, credentials or database records.
 */
function failPostSaveTrace(string $reason): never {
    fwrite(STDERR, 'FAIL: '.$reason."\n");
    exit(2);
}
function tt(array|string $t):string { return is_array($t) ? $t[1] : $t; }
function skipTokens(array $ts, int $i):int {
    while(isset($ts[$i]) && is_array($ts[$i])
        && in_array($ts[$i][0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)) $i++;
    return $i;
}
function methodOnlyTokens(string $source):array {
    $ts=token_get_all($source);
    $n=count($ts);
    for($i=0;$i<$n;$i++) {
        if(!is_array($ts[$i])||$ts[$i][0]!==T_FUNCTION)continue;
        $j=skipTokens($ts,$i+1);
        if(($ts[$j]??null)==='&')$j=skipTokens($ts,$j+1);
        if(!is_array($ts[$j]??null)||$ts[$j][0]!==T_STRING
            ||strcasecmp($ts[$j][1],'orderCreatedEvent')!==0)continue;
        $open=false;$depth=0;$body=[];
        for($k=$j+1;$k<$n;$k++) {
            $x=tt($ts[$k]);
            if($x==='{'){$open=true;$depth++;}
            if($open)$body[]=$ts[$k];
            if($x==='}'&&$open){$depth--;if($depth===0)return $body;}
        }
        failPostSaveTrace('METHOD_UNBOUNDED');
    }
    failPostSaveTrace('ORDER_CREATED_EVENT_NOT_FOUND');
}
function queryScope(array $tokens):array {
    $count=count($tokens);$results=[];
    for($i=0;$i<$count;$i++) {
        if(!is_array($tokens[$i])||$tokens[$i][0]!==T_VARIABLE
            ||$tokens[$i][1]!=='$DB')continue;
        $obj=skipTokens($tokens,$i+1);
        if(!is_array($tokens[$obj]??null)
            ||$tokens[$obj][0]!==T_OBJECT_OPERATOR)continue;
        $func=skipTokens($tokens,$obj+1);
        if(!is_array($tokens[$func]??null)
            ||$tokens[$func][0]!==T_STRING
            ||strcasecmp($tokens[$func][1],'Query')!==0)continue;
        $start=skipTokens($tokens,$func+1);
        if(($tokens[$start]??null)!=='(')failPostSaveTrace('QUERY_ARGUMENT_STRUCTURE');
        $depth=1;$sql='';$segments=0;$usesServiceId=false;$i2=$start+1;
        for(;$i2<$count;$i2++){
            $token=$tokens[$i2];$v=tt($token);
            if($v==='(')$depth++;
            if($v===')'){$depth--;if($depth===0)break;}
            if($depth===1 && is_array($token)
                && $token[0]===T_CONSTANT_ENCAPSED_STRING) {
                $sql.=substr($v,1,-1).' ';
                $segments++;
            }
            if($depth===1 && is_array($token)
                && $token[0]===T_VARIABLE && $v==='$this') {
                $usesServiceId=true;
            }
        }
        if($i2===$count)failPostSaveTrace('QUERY_ARGUMENT_UNBOUNDED');
        // Whitelist output; SQL statements and their literal contents NEVER
        // leave memory, including if they contain embedded secrets.
        $upper=strtoupper($sql);
        $operation=preg_match('/^\s*(UPDATE|DELETE|INSERT|REPLACE|SELECT)\b/',$upper,$m)===1
            ? $m[1] : 'OTHER';
        // Read only the SQL table IDENTIFIER after DELETE FROM or UPDATE.
        // Never return SQL, values, quoted arbitrary strings or expressions.
        $tableName='UNRECOGNIZED';
        if(($operation==='DELETE' && preg_match('/\bDELETE\s+FROM\s+`?([A-Z][A-Z0-9_]{0,62})`?/i',
                $upper,$tableMatch)===1)
            || ($operation==='UPDATE' && preg_match('/\bUPDATE\s+`?([A-Z][A-Z0-9_]{0,62})`?/i',
                $upper,$tableMatch)===1)) {
            $tableName=strtoupper($tableMatch[1]);
        }
        $targets=[];
        foreach(['B_SALE_BASKET','B_SALE_FUSER','B_SALE_ORDER',
            'B_SALE_ORDER_PROPS_VALUE','B_SALE_BASKET_PROPS'] as $name) {
            if(preg_match('/\b'.preg_quote($name,'/').'\b/',$upper)===1) {
                $targets[]=$name;
            }
        }
        $where=[];
        foreach(['FUSER_ID','ORDER_ID','ID','DELAY','SET','WHERE','NOTES'] as $name) {
            if(preg_match('/\b'.preg_quote($name,'/').'\b/',$upper)===1){
                $where[]=$name;
            }
        }
        $results[]=[
            'operation'=>$operation,
            'table_identifier'=>$tableName,
            'tables'=>$targets?:['UNRECOGNIZED'],
            'keywords'=>$where,
            'literal_segments'=>$segments,
            'uses_service_id'=>$usesServiceId
        ];
        $i=$i2;
    }
    return $results;
}
function printQueryScope(string $source):void {
    $queries=queryScope(methodOnlyTokens($source));
    if(count($queries)!==2)failPostSaveTrace('EXPECTED_TWO_DB_QUERIES');
    echo 'EVENT_METHOD=CartApiService::orderCreatedEvent',PHP_EOL;
    echo 'DIRECT_DB_QUERY_COUNT=',count($queries),PHP_EOL;
    foreach($queries as $i=>$q){
        $prefix='QUERY_'.($i+1).'_';
        echo $prefix,'OP=', $q['operation'],PHP_EOL;
        echo $prefix,'TARGET_IDENTIFIER=', $q['table_identifier'],PHP_EOL;
        echo $prefix,'WHITELIST_TABLES=',implode(',',$q['tables']),PHP_EOL;
        echo $prefix,'KNOWN_SQL_KEYWORDS=',implode(',',$q['keywords']),PHP_EOL;
        echo $prefix,'SQL_LITERAL_SEGMENTS=',$q['literal_segments'],PHP_EOL;
        echo $prefix,'USES_SERVICE_ID=',($q['uses_service_id']?'YES':'NO'),PHP_EOL;
    }
    echo 'SQL_LITERAL_VALUES_PRINTED=NO',PHP_EOL;
    echo 'SITE_PHP_EXECUTED=NO',PHP_EOL;
    echo 'DATABASE_CONNECTION=NO',PHP_EOL;
}
if($argc===2 && $argv[1]==='--self-test'){
    $sample=<<<'PHP'
<?php
class CartApiService {
  public function orderCreatedEvent(){
    global $DB;
    $DB->Query('UPDATE b_sale_basket SET DELAY=1 WHERE FUSER_ID=' . $this->id);
    $DB->Query('DELETE FROM b_sale_basket_props WHERE FUSER_ID=' . $this->id);
    $secret='customer-hidden-text';
  }
}
PHP;
    ob_start();printQueryScope($sample);$out=(string)ob_get_clean();
    if(!str_contains($out,'QUERY_1_OP=UPDATE')
        ||!str_contains($out,'QUERY_2_OP=DELETE')
        ||!str_contains($out,'QUERY_1_TARGET_IDENTIFIER=B_SALE_BASKET')
        ||!str_contains($out,'QUERY_2_TARGET_IDENTIFIER=B_SALE_BASKET_PROPS')
        ||!str_contains($out,'QUERY_1_WHITELIST_TABLES=B_SALE_BASKET')
        ||!str_contains($out,'QUERY_2_WHITELIST_TABLES=B_SALE_BASKET_PROPS')
        ||!str_contains($out,'QUERY_1_USES_SERVICE_ID=YES')
        ||str_contains($out,'customer-hidden-text')
        ||str_contains($out,'UPDATE b_sale_basket'))failPostSaveTrace('SELFTEST');
    echo "PASS: exact SQL identifier classifier and no-SQL-output redaction\n";
    exit(0);
}
if($argc!==3||$argv[1]!=='--source-only')failPostSaveTrace('ARGUMENTS');
$file=$argv[2];
if(!is_file($file)||is_link($file)||filesize($file)>1500000)
    failPostSaveTrace('SOURCE_FILE_INVALID');
$data=file_get_contents($file);
if(!is_string($data)||$data==='')failPostSaveTrace('SOURCE_UNREADABLE');
printQueryScope($data);
