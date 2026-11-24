<?php
/** Framework-free contract/security smoke tests. Real WP/WooCommerce tests remain required. */
declare(strict_types=1);
$GLOBALS['mbwc_options']=[];
$GLOBALS['mbwc_http_calls']=[];
define('DB_NAME', 'mbwc_test');
class StubDb {
    public string $prefix='wp_';
    public string $last_error='';
    public function insert(string $table,array $values,array $format=[]):bool{return true;}
    public function prepare(string $query,mixed ...$args):string{return $query;}
    public function get_var(string $query):mixed {
        if (str_contains($query,'GET_LOCK')) {return $GLOBALS['mbwc_busy'] ?? 1;}
        if (str_contains($query,'mappings')) {return $GLOBALS['mbwc_mapping_exists'] ?? null;}
        return 1;
    }
}
$GLOBALS['wpdb']=new StubDb();
class WP_Error {
    public function __construct(private string $code='',private string $message='',private mixed $data=null) {}
    public function get_error_code(): string {return $this->code;}
    public function get_error_message(): string{return $this->message;}
    public function get_error_data(): mixed{return $this->data;}
}
function is_wp_error(mixed $x): bool {return $x instanceof WP_Error;}
function get_option(string $key,mixed $default=null): mixed{return $GLOBALS['mbwc_options'][$key]??$default;}
function update_option(string $key,mixed $value,bool $autoload=false):bool {
    if ($key===($GLOBALS['mbwc_fail_option']??null)) {return false;}
    if (array_key_exists($key,$GLOBALS['mbwc_options']) && $GLOBALS['mbwc_options'][$key]===$value) {return false;}
    $GLOBALS['mbwc_options'][$key]=$value;
    return true;
}
function delete_option(string $key):bool{unset($GLOBALS['mbwc_options'][$key]);return true;}
function wp_salt(string $scheme='auth'): string{return 'testing-only-'.$scheme;}
function wp_parse_url(string $url,int $component=-1):array|string|int|false|null{return $component===-1?parse_url($url):parse_url($url,$component);}
function rest_url():string{return 'https://example.org/wp-json/';}
function wp_html_excerpt(string $s,int $n):string{return substr($s,0,$n);}
function wp_json_encode(mixed $value,int $flags=0):string|false{return json_encode($value,$flags);}
function add_query_arg(array $query,string $url):string{return $url.'?'.http_build_query($query);}
function wp_safe_remote_request(string $url,array $args):array {
    $GLOBALS['mbwc_http_calls'][]=[$url,$args];
    if (isset($GLOBALS['mbwc_http_response'])) {return $GLOBALS['mbwc_http_response'];}
    return ['response'=>['code'=>200], 'body'=>'{"status":"ok","data":{"phone_number":"09123456789","access_token":"TEST_SECRET"}}'];
}
function wp_remote_retrieve_response_code(array $r):int{return $r['response']['code'];}
function wp_remote_retrieve_body(array $r):string{return $r['body'];}
function fail(string $test):never{fwrite(STDERR,"FAIL: $test\n");exit(1);}
function ok(bool $condition,string $test):void{if(!$condition){fail($test);}echo "PASS: $test\n";}
function raises(callable $fn,string $test):void{try{$fn();}catch(\Throwable $e){echo "PASS: $test\n";return;}fail($test);}
$base=dirname(__DIR__).'/src/';
foreach (['Infrastructure/Database','Infrastructure/Secrets','Infrastructure/Accounts','Infrastructure/Redaction','Infrastructure/Logger','Infrastructure/Http','Domain/Money','Providers/Torob/Jwt','Providers/Torob/Feed','Providers/Torob/Client','Providers/Digikala/Client','Providers/Digikala/OpenApiCatalog'] as $file) { require $base.$file.'.php'; }
use MarketplaceBridge\WooCommerce\Infrastructure\Secrets;
use MarketplaceBridge\WooCommerce\Infrastructure\Accounts;
use MarketplaceBridge\WooCommerce\Infrastructure\Redaction;
use MarketplaceBridge\WooCommerce\Infrastructure\Http;
use MarketplaceBridge\WooCommerce\Domain\Money;
use MarketplaceBridge\WooCommerce\Providers\Torob\Jwt;
use MarketplaceBridge\WooCommerce\Providers\Torob\Feed;
use MarketplaceBridge\WooCommerce\Providers\Torob\Client as TorobClient;
use MarketplaceBridge\WooCommerce\Providers\Digikala\Client as DigikalaClient;
use MarketplaceBridge\WooCommerce\Providers\Digikala\OpenApiCatalog;

ok(Secrets::set('digikala','access_token','TEST_SECRET'),'encrypted token saved');
ok(Secrets::get('digikala','access_token')==='TEST_SECRET','secret roundtrip');
ok(!str_contains((string)get_option('mbwc_secret_digikala_access_token'),'TEST_SECRET'),'secret encrypted at rest');
ok(!Secrets::set('other','access_token','secret'),'unknown provider forbidden');
ok(Money::toToman('99995','IRR')===10000,'exact Rial half-up conversion');
ok(Money::toToman('12999.00000','IRR')===1300,'exact decimal-zero Rial conversion');
ok(Money::toToman('12999','IRT')===12999,'Toman amount unchanged');
raises(fn()=>Money::toToman('1.3','IRR'),'fractional currency rejected');
raises(fn()=>Money::toToman('-10','IRR'),'negative amount rejected');
raises(fn()=>Money::toToman('1e20','IRR'),'exponential notation rejected');
raises(fn()=>Money::toToman('999999999999999999999999999','IRR'),'large money overflow rejected');
ok(!Jwt::verify('abc.def.ghi','1'),'fake signature rejected');
ok(!Jwt::verify('abc.def.ghi','2'),'unsupported JWT version rejected');
ok(Feed::unique(123)==='wc_123','stable Torob product identity');
ok(Accounts::save('digikala','seller_two','Second seller','ABC012','TEST_SECOND')===true,'additional seller created');
ok(Accounts::token('digikala','seller_two')==='TEST_SECOND','seller token isolation');
ok(Accounts::sellerCode('seller_two')==='ABC012','seller code isolation');
ok(Accounts::token('digikala','default')==='TEST_SECRET','default legacy token migration preserved');
ok(Accounts::save('digikala','seller_two','Second seller','ABC012','')===true,'resaving unchanged account succeeds');
ok(Accounts::save('digikala','seller-hyphen','Hyphenated seller','','TOKEN_HYPHEN')===true,'hyphenated seller ID can save a token');
ok(Accounts::token('digikala','seller-hyphen')==='TOKEN_HYPHEN','hyphenated seller token roundtrip');
ok(!Secrets::set('digikala','access_token',"TOKEN\nCONTINUATION"),'credential with embedded whitespace rejected');
$GLOBALS['mbwc_fail_option']='mbwc_accounts_digikala';
ok(Accounts::save('digikala','seller-hyphen','Changed label','','NEW_TOKEN')===false
    && Accounts::token('digikala','seller-hyphen')==='TOKEN_HYPHEN','failed account save restores previous credential');
unset($GLOBALS['mbwc_fail_option']);
$GLOBALS['mbwc_mapping_exists']=123;
ok(!Accounts::remove('digikala','seller_two'),'account deletion with mappings is rejected at the storage layer');
ok(is_wp_error(Accounts::save('digikala','seller_two','Second seller','NEW_SELLER','TEST_SECOND')),'mapped account cannot change seller identity');
unset($GLOBALS['mbwc_mapping_exists']);
$GLOBALS['mbwc_busy']=0;
ok(is_wp_error(Accounts::credentials('seller_two')),'credential read fails cleanly while the account is changing');
unset($GLOBALS['mbwc_busy']);
ok(is_wp_error(Accounts::save('digikala','SELLER-HYPHEN','Duplicate','','')),'account ID aliases cannot share storage');
ok(Accounts::save('digikala','default','Default','OLD_CODE','')===true,'stored default account supported');
update_option('mbwc_digikala_seller_code','NEW_CODE');
ok(Accounts::sellerCode()==='NEW_CODE','changes to default seller code apply after account creation');
ok(!is_wp_error((new DigikalaClient())->read('variants', ['search[search_term]'=>'A&B + C'], 'seller-hyphen')),'seller-specific query can be sent');
$last=end($GLOBALS['mbwc_http_calls']);
ok(!isset($last[1]['headers']['User-Agent']),'additional seller does not inherit default seller header');
ok(str_ends_with($last[0],'?search%5Bsearch_term%5D=A%26B%20%2B%20C'),'query values keep literal separators and spaces');
ok(!Accounts::exists('digikala','missing'),'missing seller rejected');
$spec = json_encode(['openapi'=>'3.0.3','info'=>['version'=>'current'], 'servers'=>[['url'=>'https://seller.digikala.com/open-api/v1']], 'paths'=>[
 '/variants'=>['get'=>['summary'=>'List variants','tags'=>['variants']]],
 '/open-api/v1/variants/{variant_id}'=>['get'=>['summary'=>'Get variant','parameters'=>[['in'=>'path','name'=>'variant_id']]]],
 '/open-api/v1/finance/transfer'=>['post'=>['summary'=>'Transfer funds']],
 'https://evil.example/steal'=>['get'=>[]]
]],JSON_UNESCAPED_SLASHES);
$count=OpenApiCatalog::import($spec);
ok($count===3,'relative and full seller API paths normalized; hostile URL rejected');
$catalog=OpenApiCatalog::all();
ok(count($catalog)===3,'safe catalog persisted');
$write=array_values(array_filter($catalog,fn($row)=>$row['method']==='POST'))[0];
$before=count($GLOBALS['mbwc_http_calls']);
ok(is_wp_error(OpenApiCatalog::execute($write['id'],[],[],['amount'=>1])),'imported arbitrary write disabled');
ok(count($GLOBALS['mbwc_http_calls'])===$before,'disabled write makes no HTTP request');
$get=array_values(array_filter($catalog,fn($row)=>$row['method']==='GET' && !$row['paths']))[0];
$result=OpenApiCatalog::execute($get['id'],[],[],null,'seller_two');
ok(!is_wp_error($result),'imported safe GET callable');
$last=end($GLOBALS['mbwc_http_calls']);
ok(str_contains($last[0],'seller.digikala.com/open-api/v1/variants'),'GET targets fixed Digikala origin');
ok(($last[1]['headers']['Authorization']??'')==='Bearer TEST_SECOND','account-specific authentication');
ok(str_starts_with(($last[1]['headers']['User-Agent']??''),'DigikalaTrustedBot/SelfToken/ABC012'),'account-specific seller header');
ok(is_wp_error(OpenApiCatalog::execute($get['id'],[],[],null,'missing')),'unknown account rejected');
ok(is_wp_error(OpenApiCatalog::execute($get['id'],[],[],['irrelevant'=>1])),'GET body forbidden');
$bad = OpenApiCatalog::import(json_encode(['openapi'=>'3.0.0','servers'=>[['url'=>'https://evil.example/open-api/v1']],'paths'=>['/variants'=>['get'=>[]]]],JSON_UNESCAPED_SLASHES));
ok(is_wp_error($bad),'foreign OpenAPI origin rejected');
$nested = json_encode([
    'openapi'=>'3.0.3',
    'servers'=>[['url'=>'/open-api/v1/seller']],
    'components'=>['parameters'=>['page'=>['in'=>'query','name'=>'page','required'=>true]]],
    'paths'=>['/variants/{variant-id}'=>[
        'parameters'=>[['$ref'=>'#/components/parameters/page']],
        'get'=>['parameters'=>[['in'=>'query','name'=>'page','required'=>false]]],
    ]],
]);
ok(OpenApiCatalog::import($nested)===1,'importer accepts an API subpath with local references');
$nestedOp=array_values(OpenApiCatalog::all())[0];
ok($nestedOp['path']==='/open-api/v1/seller/variants/{variant-id}' && $nestedOp['paths']===['variant-id']
    && $nestedOp['query']===['page'] && !$nestedOp['required_query'],'importer resolves base path, path parameter and operation-level query override');
ok(!is_wp_error(OpenApiCatalog::execute($nestedOp['id'],['variant-id'=>'42'],[],null,'seller_two')),
    'resolved relative OpenAPI route is executable');
ok(is_wp_error(Http::request('digikala','GET','/open-api/v1/../secret','x')),'path traversal rejected');
ok(is_wp_error(Http::request('torob','GET','//evil.example/a','x')),'host injection rejected');
$redacted=Redaction::scrub(['access_token'=>'abc','nested'=>['phone_number'=>'09123456789','public_id'=>'safe'],'details'=>'Bearer secret123']);
ok($redacted['access_token']==='[REDACTED]' && $redacted['nested']['phone_number']==='[REDACTED]' && $redacted['nested']['public_id']==='safe' && str_contains($redacted['details'],'[REDACTED]'),'PII and bearer values redacted');
ok(is_wp_error((new TorobClient())->run('answer_report',[],['status'=>'claimed_to_be_corrected'])),'report answers require IDs');
Secrets::set('torob','shop_token','TOROB_TEST');
$result=(new TorobClient())->run('products');
ok(!is_wp_error($result),'Torob Shop products request test transport');
$last=end($GLOBALS['mbwc_http_calls']);
ok(($last[1]['body']??'')==='{}','Torob empty filter serialized as JSON object');
ok(is_wp_error((new TorobClient())->run('clicks')),'Torob click report requires date');
ok(is_wp_error((new TorobClient())->run('clicks',['date'=>'2026-02-31'])),'Torob click report rejects impossible date');
ok(is_wp_error((new TorobClient())->run('activate',[],['product_ids'=>[123]])),'Torob rejects incorrect activation product IDs');
Secrets::set('torob','webhook_token','TOROB_WEBHOOK_TEST');
$items=[['page_url'=>'https://example.org/product/123','page_unique'=>'wc_123']];
ok(is_wp_error((new TorobClient())->update_webhook($items)),'unexpected successful HTTP response is not a webhook acknowledgment');
$GLOBALS['mbwc_http_response']=['response'=>['code'=>200],'body'=>'{"message":"success"}'];
ok(!is_wp_error((new TorobClient())->update_webhook($items)),'documented Torob webhook acknowledgment accepted');
unset($GLOBALS['mbwc_http_response']);
ok(is_wp_error((new DigikalaClient())->read('not_allowed')),'Digikala operation allowlist enforced');
ok(Accounts::remove('digikala','seller_two'),'seller account removal');
ok(Accounts::token('digikala','seller_two')==='','removed seller credential deleted');
ok(is_wp_error(Accounts::save('digikala','seller_two','Old form','ABC012','',false,false))
    && !Accounts::exists('digikala','seller_two'), 'stale edit cannot re-create a removed seller');
echo "ALL SMOKE TESTS PASSED (No live provider calls or WordPress integration tests).\n";
