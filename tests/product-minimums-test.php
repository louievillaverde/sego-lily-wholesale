<?php
/**
 * Integration test for SLW_Product_Minimums enforcement (product-type minimums:
 * "6 per Ageless, mix scents"). Loads the REAL class and exercises the actual
 * get_violations()/enforce_product_minimums() against a mock wholesale cart.
 * Run: php tests/product-minimums-test.php   (no WordPress needed)
 */
define('ABSPATH', __DIR__ . '/');   // satisfy the plugin's ABSPATH guard

class MockProduct {
    public $id, $parent, $name;
    function __construct($id,$parent,$name){$this->id=$id;$this->parent=$parent;$this->name=$name;}
    function get_id(){return $this->id;} function get_parent_id(){return $this->parent;}
    function get_name(){return $this->name;} function get_price(){return 18;}
}
class MockCart { public $items=[]; function get_cart(){return $this->items;} }
class MockWC { public $cart; function __construct(){$this->cart=new MockCart();} }

$GLOBALS['__wc']=new MockWC(); $GLOBALS['__notices']=[]; $GLOBALS['__mins']=[]; $GLOBALS['__exempt']=false;
$GLOBALS['__catalog']=[ new MockProduct(103,0,'Ageless Tallow Butter'), new MockProduct(105,0,'Renewal Tallow Butter') ];

function WC(){return $GLOBALS['__wc'];}
function wc_add_notice($m,$t='success'){$GLOBALS['__notices'][]=$m;}
function slw_is_wholesale_context(){return true;}
function get_option($k,$d=false){return $d;}
function get_current_user_id(){return 7;}
function get_user_meta($u,$k,$s=false){return ($k==='slw_min_exempt' && $GLOBALS['__exempt'])?'1':'';}
function esc_html($s){return $s;}
function get_post_meta($id,$k,$s=false){ if($k==='_slw_minimum_qty')return $GLOBALS['__mins'][$id]??''; return ''; }
function wc_get_product($id){ foreach($GLOBALS['__catalog'] as $p){ if($p->id==$id)return $p; } return new MockProduct($id,0,"Product $id"); }

require __DIR__ . '/../includes/class-product-minimums.php';

function set_cart($rows){ $c=[]; foreach($rows as $r){ [$parent,$vid,$qty]=$r;
    $c[]=['data'=>new MockProduct($vid,$parent,"var $vid"),'product_id'=>$parent,'variation_id'=>$vid,'quantity'=>$qty]; }
    $GLOBALS['__wc']->cart->items=$c; }
$pass=0;$fail=0;
function check($l,$g,$w){global $pass,$fail;$ok=($g===$w);echo ($ok?"PASS":"FAIL")." | $l\n";if(!$ok)echo "   got=".json_encode($g)." want=".json_encode($w)."\n";$ok?$pass++:$fail++;}
function notices(){$n=$GLOBALS['__notices'];$GLOBALS['__notices']=[];return $n;}

$GLOBALS['__mins']=[103=>6,105=>6];
set_cart([[103,1031,2],[103,1032,2],[103,1033,2]]);
check("Ageless 2+2+2 mixed scents = 6 -> no violation", SLW_Product_Minimums::get_violations(false), []);
SLW_Product_Minimums::enforce_product_minimums(); check("   ...no checkout notice", notices(), []);
set_cart([[103,1031,2],[103,1032,2]]);
$v=SLW_Product_Minimums::get_violations(false);
check("Ageless 2+2 = 4 -> 1 violation", count($v), 1);
check("   ...message says add 2 more", (strpos($v[0]??'','Add 2 more')!==false), true);
SLW_Product_Minimums::enforce_product_minimums(); check("   ...blocks checkout (1 error notice)", count(notices()), 1);
set_cart([[103,1031,3],[103,1032,3],[105,1051,2],[105,1052,2]]);
check("Multi-product flags only the under-min product", array_map(fn($x)=>$x['name'],SLW_Product_Minimums::get_violations(true)), ['Renewal Tallow Butter']);
set_cart([[103,1031,6]]); check("Ageless 6 of one scent = ok", SLW_Product_Minimums::get_violations(false), []);
$GLOBALS['__exempt']=true; set_cart([[103,1031,2]]);
check("Exempt customer skips the minimum", SLW_Product_Minimums::get_violations(false), []); $GLOBALS['__exempt']=false;
set_cart([[103,1031,2]]); $s=SLW_Product_Minimums::get_violations(true)[0];
check("Structured keys match category violations", array_keys($s), ['type','name','have','min','add','label']);
check("   ...type=product, have=2, min=6, add=4", [$s['type'],$s['have'],$s['min'],$s['add']], ['product',2,6,4]);
$GLOBALS['__mins']=[103=>6]; set_cart([[105,1051,1]]);
check("Product with no min never flagged", SLW_Product_Minimums::get_violations(false), []);
echo "\n$pass passed, $fail failed\n";
exit($fail>0?1:0);
