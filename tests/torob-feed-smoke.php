<?php
/** Feed contract sanity checks with WooCommerce fixture objects; not a WordPress integration test. */
declare(strict_types=1);
class WP_Error { public function __construct(public string $code='',public string $message='',public mixed $data=null) {} }
class WP_REST_Request {
    public function __construct(private string $body='') {}
    public function get_body(): string {return $this->body;}
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
    public function is_type(string $type):bool{return $type==='simple';}
    public function is_purchasable():bool{return true;}
    public function is_in_stock():bool{return true;}
    public function get_price():string{return '159950';}
    public function get_regular_price():string{return '199950';}
    public function get_image_id():int{return 10;}
    public function get_gallery_image_ids():array{return [11];}
    public function get_parent_id():int{return 0;}
    public function get_id():int{return $this->id;}
    public function get_name():string{return 'Example product';}
    public function get_short_description():string{return 'Example description';}
    public function get_attributes():array{return [];}
    public function get_date_created():?object{return null;}
    public function get_date_modified():?object{return null;}
}
function wc_get_product(int $id):?WC_Product{return $id===123?new WC_Product(123):null;}
function get_option(string $key,mixed $default=null):mixed{return $key==='mbwc_currency_unit'?'IRR':$default;}
function wp_html_excerpt(string $text,int $max):string{return substr($text,0,$max);}
function wp_strip_all_tags(string $text):string{return strip_tags($text);}
function get_permalink(int $id):string{return 'https://example.com/product/'.$id.'/';}
function wp_get_attachment_image_url(int $id,string $size):string{return 'https://example.com/image/'.$id.'.jpg';}
function get_the_terms(int $id,string $taxonomy):bool{return false;}
function gmdate_stub():void{}
class FakeDb {public string $prefix='wp_';public function insert(string $table,array $data,array $fmt):bool{return true;}}
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
echo "TOROB FEED FIXTURE TESTS PASSED.\n";
