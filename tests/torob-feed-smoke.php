<?php
/** Feed contract sanity checks with WooCommerce fixture objects; not a WordPress integration test. */
declare(strict_types=1);
define('ARRAY_A', 'ARRAY_A');
define('DB_NAME', 'fixture');
class WP_Error {
    public function __construct(public string $code='',public string $message='',public mixed $data=null) {}
    public function get_error_data(): mixed {return $this->data;}
}
class WP_REST_Request {
    public function __construct(private string $body='', private array $params=[]) {}
    public function get_body(): string {return $this->body;}
    public function get_param(string $key): mixed {return $this->params[$key]??null;}
}
class WP_REST_Response {
    public function __construct(private array $data,private int $status=200){}
    public function get_data(): array{return $this->data;}
    public function get_status(): int{return $this->status;}
}
class WC_Product {
    public function __construct(public int $id) {}
    public function get_status():string{return 'publish';}
    public function get_catalog_visibility():string{return 'visible';}
    public function is_type(string $type):bool {
        return $type === match($this->id) {126=>'variable', 127=>'variation', default=>'simple'};
    }
    public function is_purchasable():bool{return true;}
    public function is_in_stock():bool{return true;}
    public function get_price():string{return match($this->id) {125=>'4', 128=>'0', 129=>'150.42', default=>'159950'};}
    public function get_regular_price():string{return '199950';}
    public function get_image_id():int{return 10;}
    public function get_gallery_image_ids():array{return [11];}
    public function get_parent_id():int{return $this->id === 127 ? 126 : 0;}
    public function get_variation_attributes():array {return ['attribute_color'=>'Red & Blue'];}
    public function get_id():int{return $this->id;}
    public function get_name():string{return 'Example product';}
    public function get_short_description():string{return 'Example description';}
    public function get_attributes():array{return [];}
    public function get_date_created():?object{return null;}
    public function get_date_modified():?object{return null;}
}
function wc_get_product(int $id):?WC_Product{return $id>=123 && $id<=129?new WC_Product($id):null;}
function get_option(string $key,mixed $default=null):mixed{return $GLOBALS['options'][$key]??$default;}
function get_woocommerce_currency():string{return 'IRR';}
function get_post_field(string $field,int $id,string $context):string{return $id===124?'protected':'';}
function get_post_modified_time(string $format,bool $gmt,int $id):int{return 1730206000;}
function wp_html_excerpt(string $text,int $max):string{return substr($text,0,$max);}
function wp_strip_all_tags(string $text):string{return strip_tags($text);}
function get_permalink(int $id):string{return $id===126?'https://example.com/?post_type=product&p=126':'https://example.com/product/'.$id.'/';}
function home_url():string{return 'https://example.com/';}
function wp_parse_url(string $url,int $part=-1):mixed{return parse_url($url,$part);}
function url_to_postid(string $url):int {
    if (preg_match('/[?&]p=([0-9]+)/',$url,$match)) {return (int)$match[1];}
    return preg_match('~/product/([0-9]+)/~',$url,$match) ? (int)$match[1] : 0;
}
function add_query_arg(array $params,string $url):string {
    return $url.(str_contains($url,'?')?'&':'?').implode('&',array_map(
        static fn($key,$value)=>$key.'='.$value,array_keys($params),$params
    ));
}
function wc_attribute_label(string $name):string{return $name;}
function is_wp_error(mixed $value):bool{return $value instanceof WP_Error;}
function wp_get_attachment_image_url(int $id,string $size):string{return 'https://example.com/image/'.$id.'.jpg';}
function get_the_terms(int $id,string $taxonomy):bool{return false;}
class FakeDb {
    public string $prefix='wp_';
    public string $posts='wp_posts';
    public string $term_relationships='wp_term_relationships';
    public string $term_taxonomy='wp_term_taxonomy';
    public string $terms='wp_terms';
    public string $last_error='';
    public array $ids=[];
    public array $calls=[];
    public array $trackingRows=[];
    public array $orders=[];
    public array $writes=[];
    public function query(string $sql):int { $this->writes[]=$sql; return 1; }
    public function insert(string $table,array $data,array $fmt):bool{return true;}
    public function prepare(string $query,mixed ...$args):string {
        $this->calls[]=[$query,$args];
        return $query;
    }
    public function get_col(string $query):array{return $this->ids;}
    public function get_var(string $query):mixed{return str_contains($query, 'LOCK') ? 1 : count($this->ids);}
    public function get_results(string $query, string $format):array{
        if (str_contains($query,'FROM wp_mbwc_attribution')) { return array_splice($this->orders,0,25); }
        return $this->trackingRows;
    }
}
function wc_get_product_visibility_term_ids():array{return ['exclude-from-catalog'=>11,'exclude-from-search'=>12];}
function _prime_post_caches(array $ids):void{}
$GLOBALS['wpdb']=new FakeDb();
function fail(string $msg):never{fwrite(STDERR,'FAIL '.$msg."\n");exit(1);}
function check(bool $pass,string $msg):void{if(!$pass){fail($msg);}echo 'PASS '.$msg."\n";}
require dirname(__DIR__).'/src/Infrastructure/Database.php';
require dirname(__DIR__).'/src/Infrastructure/Logger.php';
require dirname(__DIR__).'/src/Domain/Money.php';
require dirname(__DIR__).'/src/Providers/Torob/Feed.php';
$feed=new MarketplaceBridge\WooCommerce\Providers\Torob\Feed();
$response=$feed->handle(new WP_REST_Request('{"page_uniques":["wc_123","wc_999"]}'));
check($response instanceof WP_REST_Response,'lookup returns 200 response');
$out=$response->get_data();
check($out['api_version']==='torob_api_v3','API version');
check(count($out['products'])===1,'only existing eligible product exported');
$p=$out['products'][0];
check($p['page_unique']==='wc_123','stable product ID');
check($p['current_price']===15995 && $p['old_price']===19995,'Toman prices from integer Rial');
check($p['availability']===true,'availability');
check(count($p['image_links'])===2 && is_object($p['spec']),'required images and spec object');
$bad=$feed->handle(new WP_REST_Request('{"page":0,"sort":"date_added_desc"}'));
check($bad instanceof WP_Error,'invalid page rejected');
$bad=$feed->handle(new WP_REST_Request('{"sort":"date_added_desc","cursor":"100"}'));
check($bad instanceof WP_Error,'wrong cursor sort rejected');
$bad=$feed->handle(new WP_REST_Request('{"page_urls":{"0":"https://example.com/product/123/"}}'));
check($bad instanceof WP_Error,'lookup requires a JSON array');
$bad=$feed->handle(new WP_REST_Request('{"sort":"product_id_desc","cursor":"999999999999999999999999"}'));
check($bad instanceof WP_Error,'overflowing cursor rejected');
$response=$feed->handle(new WP_REST_Request('{"page_uniques":["wc_124","wc_125","wc_128","wc_129"]}'));
$products=array_column($response->get_data()['products'],null,'page_unique');
check(!isset($products['wc_124']),'password-protected product excluded from ID lookup');
check(!$products['wc_125']['availability'] && $products['wc_125']['current_price']===0,'small nonzero Rial price cannot be advertised as free');
check($products['wc_128']['availability'] && $products['wc_128']['current_price']===0,'genuinely free product remains available');
check(!$products['wc_129']['availability'],'invalid price exports as unavailable');
$url=MarketplaceBridge\WooCommerce\Providers\Torob\Feed::url(wc_get_product(127));
check(str_contains($url, 'attribute_color=Red+%26+Blue'),'variation attributes are URL encoded');
$urls=[$url,'https://example.com/product/123/?p=126&variation_id=127','https://example.com/?p=123&variation_id=127'];
foreach ($urls as $index=>$value) {
    $response=$feed->handle(new WP_REST_Request(json_encode(['page_urls'=>[$value]])));
    check(count($response->get_data()['products'])===($index===0?1:0),'variation URL lookup case '.($index+1));
    if ($index===0) {
        check($response->get_data()['products'][0]['date_updated']===gmdate(DATE_ATOM, 1730206000), 'variation update time includes a parent edit');
    }
}
$GLOBALS['wpdb']->ids=range(123,223);
$response=$feed->handle(new WP_REST_Request('{"sort":"product_id_desc"}'));
check($response->get_data()['next_cursor']==='222','cursor uses the last selected ID and a one-row lookahead');
$GLOBALS['wpdb']->ids=[123];
$response=$feed->handle(new WP_REST_Request('{"sort":"product_id_desc","cursor":"222"}'));
check($response->get_data()['next_cursor']===null && $GLOBALS['wpdb']->calls[1][1]===[222], 'final cursor page uses the supplied ID and terminates');
$GLOBALS['wpdb']->last_error='Database unavailable';
$bad=$feed->handle(new WP_REST_Request('{"sort":"product_id_desc"}'));
check($bad instanceof WP_Error && $bad->data['status']===503,'database failure does not publish an empty catalog');
require dirname(__DIR__).'/src/Providers/Torob/Attribution.php';
$attribution=new MarketplaceBridge\WooCommerce\Providers\Torob\Attribution();
$bad=$attribution->actions(new WP_REST_Request('', ['timestamp_gt'=>'2026-02-30T12:00:00Z','limit'=>'10']));
check($bad instanceof WP_Error && $bad->data['status']===400,'tracking rejects invalid UTC dates');
$GLOBALS['wpdb']->last_error='';
$base=['click_id'=>'fixture-click','action_type'=>'signup','status'=>'completed','updated_at'=>'2026-11-23 12:00:01'];
$first=$base+['happened_at'=>'2026-11-23 12:00:00'];
$sameSecond=$base+['happened_at'=>'2026-11-23 12:00:01'];
$GLOBALS['wpdb']->trackingRows=[$first,$sameSecond,$sameSecond];
$firstPage=$attribution->actions(new WP_REST_Request('', ['timestamp_gt'=>'2026-11-23T11:59:59Z','limit'=>'2']));
check(count($firstPage->get_data()['data'])===1,'tracking keeps a same-second group together at the page boundary');
$GLOBALS['wpdb']->trackingRows=[$sameSecond,$sameSecond];
$secondPage=$attribution->actions(new WP_REST_Request('', ['timestamp_gt'=>'2026-11-23T12:00:00Z','limit'=>'2']));
check(count($secondPage->get_data()['data'])===2,'deferred timestamp group fits on the next tracking page');
$blocked=$attribution->actions(new WP_REST_Request('', ['timestamp_gt'=>'2026-11-23T12:00:00Z','limit'=>'1']));
check($blocked instanceof WP_Error && $blocked->data['status']===409, 'a timestamp-only poll cannot silently skip the second action');
$GLOBALS['wpdb']->trackingRows=array_fill(0, 1001, $sameSecond);
$blocked=$attribution->actions(new WP_REST_Request('', ['timestamp_gt'=>'2026-11-23T12:00:00Z','limit'=>'1000']));
check($blocked instanceof WP_Error && $blocked->data['status']===409, 'an oversized legacy timestamp group reports the protocol limit');
class WC_Order {
    public function get_meta(string $key,bool $single):string{return 'fixture-click';}
    public function get_status():string{return 'completed';}
    public function get_date_modified():?object{return null;}
    public function get_payment_method():string{return 'offline';}
}
function wc_get_order(int $id):WC_Order|false { return $id===2005 ? new WC_Order() : false; }
for ($id=1;$id<=2005;++$id) {
    $GLOBALS['wpdb']->orders[]=['id'=>$id,'order_id'=>$id,'click_id'=>'fixture-click','purchase_at'=>'2026-11-23 12:00:01','updated_at'=>'2026-11-23 12:00:01'];
}
$scanned=$attribution->orders(new WP_REST_Request('', ['purchase_timestamp_gt'=>'2026-11-23T12:00:00Z','limit'=>'1']));
check(count($scanned->get_data()['data'])===1, 'older deleted orders cannot hide later reportable purchases');

function wp_salt(string $context):string{return 'fixture-key';}
require dirname(__DIR__).'/src/Infrastructure/Secrets.php';
require dirname(__DIR__).'/src/Providers/Torob/Webhook.php';
$GLOBALS['options']=['mbwc_schema_version'=>MarketplaceBridge\WooCommerce\Infrastructure\Database::VERSION,
    'mbwc_torob_enabled'=>'1','mbwc_torob_webhooks'=>'1'];
function update_option(string $key,mixed $value,bool $autoload):bool{$GLOBALS['options'][$key]=$value;return true;}
MarketplaceBridge\WooCommerce\Infrastructure\Secrets::set('torob','webhook_token','fixture-only');
$GLOBALS['wpdb']->trackingRows=[['product_id'=>'124','revision'=>'1','attempts'=>'0','previous_url'=>'']];
(new MarketplaceBridge\WooCommerce\Providers\Torob\Webhook())->drain();
check(count($GLOBALS['wpdb']->writes)===1 && str_starts_with($GLOBALS['wpdb']->writes[0],'DELETE'), 'a queued edit to a protected product is discarded before delivery');
echo "TOROB FEED FIXTURE TESTS PASSED.\n";
