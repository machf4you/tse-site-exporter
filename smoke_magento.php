<?php
/**
 * Smoke test: TSE Magento Exporter V1.0.0 — multi-store extraction.
 *
 * Stubs every Magento interface used by the extractors / orchestrator so we
 * can validate the transformation logic end-to-end without a Magento install:
 *
 *   - StoreContextResolver returns 3 store views (hf, cbs, mt) across 1
 *     website with 1 group.
 *   - ProductExtractor and CategoryExtractor return store-specific overrides
 *     by inspecting StoreManagerInterface::getStore()->getCode().
 *   - Verifies:
 *       • stores.json is emitted at root
 *       • per-store sub-directories contain the 6 expected files
 *       • each record carries store_id + store_code
 *       • product website_ids are preserved
 *       • CrossStoreMapper detects shared vs single-store SKUs
 *       • CrossStoreMapper detects diverging categories (rename across stores)
 *       • Relationship cross-store flags surface unknown targets
 *       • manifest.json lists every produced file
 */

declare(strict_types=1);

// Bootstrap Magento namespaces we need to stub.
namespace Magento\Framework\App {
    class Area { public const AREA_FRONTEND = 'frontend'; }
}

namespace Magento\Catalog\Api {
    interface CategoryListInterface       { public function getList($criteria); }
    interface CategoryRepositoryInterface { public function get($id, $storeId = null); }
    interface ProductRepositoryInterface  { public function getList($criteria); }
}
namespace Magento\Cms\Api {
    interface PageRepositoryInterface { public function getList($criteria); }
}
namespace Magento\CatalogInventory\Api {
    interface StockRegistryInterface { public function getStockItemBySku($sku); }
}
namespace Magento\Store\Model {
    interface StoreManagerInterface {
        public function getStore($id = null);
        public function getDefaultStoreView();
    }
}
namespace Magento\Store\Api {
    interface WebsiteRepositoryInterface { public function getList(); }
    interface GroupRepositoryInterface   { public function getList(); }
    interface StoreRepositoryInterface   { public function getList(); }
}

namespace Magento\Framework\Api {
    class SearchCriteriaBuilder {
        public function setPageSize($n)     { return $this; }
        public function setCurrentPage($n)  { return $this; }
        public function create()            { return new \stdClass(); }
    }
}

namespace Magento\Store\Model\App {
    class Emulation {
        public $started = []; public $stopped = 0;
        public function startEnvironmentEmulation($id, $area, $force) { $this->started[] = $id; }
        public function stopEnvironmentEmulation() { $this->stopped++; }
    }
}

namespace Magento\Framework {
    interface UrlInterface {}
    class UrlInterface_Const { public const URL_TYPE_WEB = 'web'; }
    // Real Magento UrlInterface declares URL_TYPE_WEB as const on interface;
    // shim that via class_alias so CmsPageExtractor's reference works.
}
namespace { class_alias('Magento\\Framework\\UrlInterface_Const', 'TseTestUrlConst'); }

namespace TSE\MagentoExporterTest {

require_once __DIR__ . '/tse-magento-exporter/TSE/MagentoExporter/Model/CategoryExtractor.php';
require_once __DIR__ . '/tse-magento-exporter/TSE/MagentoExporter/Model/ProductExtractor.php';
require_once __DIR__ . '/tse-magento-exporter/TSE/MagentoExporter/Model/CmsPageExtractor.php';
require_once __DIR__ . '/tse-magento-exporter/TSE/MagentoExporter/Model/RelationshipBuilder.php';
require_once __DIR__ . '/tse-magento-exporter/TSE/MagentoExporter/Model/CrossStoreMapper.php';
require_once __DIR__ . '/tse-magento-exporter/TSE/MagentoExporter/Model/StoreContextResolver.php';
require_once __DIR__ . '/tse-magento-exporter/TSE/MagentoExporter/Model/Exporter.php';
require_once __DIR__ . '/tse-magento-exporter/TSE/MagentoExporter/Model/ZipBuilder.php';

// --- Stub data ---------------------------------------------------------------
class FakeStore {
    public function __construct(public int $id, public string $code, public string $name, public int $websiteId, public int $groupId) {}
    public function getId() { return $this->id; }
    public function getCode() { return $this->code; }
    public function getName() { return $this->name; }
    public function getWebsiteId() { return $this->websiteId; }
    public function getStoreGroupId() { return $this->groupId; }
    public function getBaseUrl($type, $secure = false) { return 'https://' . $this->code . '.test/' . ($secure ? '' : ''); }
    public function isActive() { return true; }
    public function getCurrentCurrencyCode() { return 'GBP'; }
}
class FakeWebsite {
    public function __construct(public int $id, public string $code, public string $name) {}
    public function getId() { return $this->id; }
    public function getCode() { return $this->code; }
    public function getName() { return $this->name; }
    public function getDefaultGroupId() { return 1; }
}
class FakeGroup {
    public function __construct(public int $id, public string $code, public string $name, public int $websiteId, public int $rootCategoryId, public int $defaultStoreId) {}
    public function getId() { return $this->id; }
    public function getCode() { return $this->code; }
    public function getName() { return $this->name; }
    public function getWebsiteId() { return $this->websiteId; }
    public function getRootCategoryId() { return $this->rootCategoryId; }
    public function getDefaultStoreId() { return $this->defaultStoreId; }
}
class StoreManagerStub implements \Magento\Store\Model\StoreManagerInterface {
    public $current;
    public function __construct(public array $stores) { $this->current = $stores[0]; }
    public function getStore($id = null) { return $this->current; }
    public function getDefaultStoreView() { return $this->current; }
    public function setCurrent(FakeStore $s) { $this->current = $s; }
}
class WebsiteRepoStub implements \Magento\Store\Api\WebsiteRepositoryInterface { public function __construct(public array $items) {} public function getList() { return $this->items; } }
class GroupRepoStub   implements \Magento\Store\Api\GroupRepositoryInterface   { public function __construct(public array $items) {} public function getList() { return $this->items; } }
class StoreRepoStub   implements \Magento\Store\Api\StoreRepositoryInterface   { public function __construct(public array $items) {} public function getList() { return $this->items; } }

class SearchResultStub {
    public function __construct(public array $items, public int $total) {}
    public function getItems() { return $this->items; }
    public function getTotalCount() { return $this->total; }
}

class FakeCategory {
    public function __construct(public array $data) {}
    public function getId()              { return $this->data['id']; }
    public function getParentId()        { return $this->data['parent_id'] ?? 0; }
    public function getName()            { return $this->data['name'] ?? ''; }
    public function getLevel()           { return $this->data['level'] ?? 1; }
    public function getPath()            { return $this->data['path'] ?? '1'; }
    public function getUrl()             { return $this->data['url'] ?? ''; }
    public function getUrlKey()          { return $this->data['url_key'] ?? ''; }
    public function getMetaTitle()       { return $this->data['meta_title'] ?? ''; }
    public function getMetaDescription() { return $this->data['meta_description'] ?? ''; }
    public function getMetaKeywords()    { return $this->data['meta_keywords'] ?? ''; }
    public function getIsActive()        { return $this->data['is_active'] ?? true; }
    public function getIncludeInMenu()   { return $this->data['include_in_menu'] ?? true; }
    public function getPosition()        { return $this->data['position'] ?? 0; }
    public function getProductCount()    { return $this->data['product_count'] ?? 0; }
}

class CategoryListStub implements \Magento\Catalog\Api\CategoryListInterface {
    public function __construct(public StoreManagerStub $sm, public array $perStore) {}
    public function getList($criteria) {
        $code = $this->sm->current->code;
        $rows = $this->perStore[$code] ?? [];
        return new SearchResultStub(array_map(fn($r) => new FakeCategory($r), $rows), count($rows));
    }
}
class CategoryRepoStub implements \Magento\Catalog\Api\CategoryRepositoryInterface { public function get($id, $storeId = null) { return null; } }

class FakeProduct {
    public function __construct(public array $data) {}
    public function getId()              { return $this->data['id']; }
    public function getSku()             { return $this->data['sku']; }
    public function getName()            { return $this->data['name']; }
    public function getTypeId()          { return 'simple'; }
    public function getStatus()          { return $this->data['status'] ?? 1; }
    public function getVisibility()      { return $this->data['visibility'] ?? 4; }
    public function getUrlKey()          { return $this->data['url_key']; }
    public function getMetaTitle()       { return $this->data['meta_title'] ?? ''; }
    public function getMetaDescription() { return $this->data['meta_description'] ?? ''; }
    public function getMetaKeyword()     { return ''; }
    public function getPrice()           { return $this->data['price']; }
    public function getSpecialPrice()    { return $this->data['special_price'] ?? null; }
    public function getCategoryIds()     { return $this->data['category_ids'] ?? []; }
    public function getWebsiteIds()      { return $this->data['website_ids']  ?? []; }
    public function getStoreIds()        { return $this->data['store_ids']    ?? []; }
    public function getCreatedAt()       { return '2026-01-01'; }
    public function getUpdatedAt()       { return '2026-02-01'; }
    public function getCustomAttributes(){ return []; }
    public function getMediaGalleryEntries() { return []; }
    public function getProductUrl()      { return $this->data['url'] ?? ''; }
    public function getProductLinks()    {
        $out = [];
        foreach (($this->data['product_links'] ?? []) as $l) {
            $out[] = new class($l) {
                public function __construct(public array $l) {}
                public function getLinkType() { return $this->l['type']; }
                public function getLinkedProductSku() { return $this->l['sku']; }
                public function getPosition() { return 0; }
            };
        }
        return $out;
    }
}
class ProductRepoStub implements \Magento\Catalog\Api\ProductRepositoryInterface {
    public function __construct(public StoreManagerStub $sm, public array $perStore) {}
    public function getList($criteria) {
        $code = $this->sm->current->code;
        $rows = $this->perStore[$code] ?? [];
        return new SearchResultStub(array_map(fn($r) => new FakeProduct($r), $rows), count($rows));
    }
}

class StockRegistryStub implements \Magento\CatalogInventory\Api\StockRegistryInterface {
    public function getStockItemBySku($sku) {
        return new class {
            public function getIsInStock() { return true; }
            public function getQty() { return 10; }
            public function getManageStock() { return true; }
        };
    }
}

class FakeCmsPage {
    public function __construct(public array $d) {}
    public function getId() { return $this->d['id']; }
    public function getIdentifier() { return $this->d['identifier']; }
    public function getTitle() { return $this->d['title']; }
    public function getMetaTitle() { return ''; }
    public function getMetaDescription() { return ''; }
    public function getMetaKeywords() { return ''; }
    public function getContentHeading() { return ''; }
    public function isActive() { return true; }
    public function getPageLayout() { return '1column'; }
    public function getSortOrder() { return 0; }
    public function getCreationTime() { return ''; }
    public function getUpdateTime() { return ''; }
}
class PageRepoStub implements \Magento\Cms\Api\PageRepositoryInterface {
    public function __construct(public StoreManagerStub $sm, public array $perStore) {}
    public function getList($criteria) {
        $code = $this->sm->current->code;
        $rows = $this->perStore[$code] ?? [];
        return new SearchResultStub(array_map(fn($r) => new FakeCmsPage($r), $rows), count($rows));
    }
}

class TrackingEmulation extends \Magento\Store\Model\App\Emulation {
    public $startedFor = [];
    public function __construct(public StoreManagerStub $sm, public array $byId) {}
    public function startEnvironmentEmulation($id, $area, $force) {
        $this->startedFor[] = $id;
        if (isset($this->byId[$id])) $this->sm->setCurrent($this->byId[$id]);
    }
    public function stopEnvironmentEmulation() {}
}

// --- Fixture ----------------------------------------------------------------
$stores = [
    new FakeStore(1, 'hf',  'HF Store View',  1, 1),
    new FakeStore(2, 'cbs', 'CBS Store View', 1, 1),
    new FakeStore(3, 'mt',  'MT Store View',  1, 1),
];
$byId = []; foreach ($stores as $s) $byId[$s->getId()] = $s;
$sm = new StoreManagerStub($stores);

$websiteRepo = new WebsiteRepoStub([new FakeWebsite(1, 'main', 'Main Website')]);
$groupRepo   = new GroupRepoStub([new FakeGroup(1, 'main', 'Main Group', 1, 2, 1)]);
$storeRepo   = new StoreRepoStub($stores);

// Categories — note CBS renames "Mattresses" to "Bed Sale" → divergence.
$catPerStore = [
    'hf'  => [
        ['id' => 2, 'parent_id' => 1, 'name' => 'Default Category', 'level' => 1, 'path' => '1/2', 'url_key' => '', 'is_active' => true,  'include_in_menu' => true,  'url' => 'https://hf.test/'],
        ['id' => 3, 'parent_id' => 2, 'name' => 'Mattresses',       'level' => 2, 'path' => '1/2/3', 'url_key' => 'mattresses', 'is_active' => true, 'include_in_menu' => true,  'url' => 'https://hf.test/mattresses'],
        ['id' => 4, 'parent_id' => 2, 'name' => 'Beds',             'level' => 2, 'path' => '1/2/4', 'url_key' => 'beds',       'is_active' => true, 'include_in_menu' => true,  'url' => 'https://hf.test/beds'],
    ],
    'cbs' => [
        ['id' => 2, 'parent_id' => 1, 'name' => 'Default Category', 'level' => 1, 'path' => '1/2', 'is_active' => true],
        ['id' => 3, 'parent_id' => 2, 'name' => 'Bed Sale',         'level' => 2, 'path' => '1/2/3', 'url_key' => 'bed-sale',  'is_active' => true, 'include_in_menu' => false, 'url' => 'https://cbs.test/bed-sale'],
        ['id' => 4, 'parent_id' => 2, 'name' => 'Beds',             'level' => 2, 'path' => '1/2/4', 'url_key' => 'beds',      'is_active' => true, 'include_in_menu' => true,  'url' => 'https://cbs.test/beds'],
    ],
    'mt'  => [
        ['id' => 2, 'parent_id' => 1, 'name' => 'Default Category', 'level' => 1, 'path' => '1/2', 'is_active' => true],
        ['id' => 3, 'parent_id' => 2, 'name' => 'Mattresses',       'level' => 2, 'path' => '1/2/3', 'url_key' => 'mattresses-mt', 'is_active' => true, 'include_in_menu' => true, 'url' => 'https://mt.test/mattresses-mt'],
    ],
];

// Products — SKU "MAT-001" exists in all 3 stores (shared); "CBS-EXCL" only in CBS.
// HF has an upsell pointing to "UNKNOWN-SKU" → cross-store flag.
$prodPerStore = [
    'hf' => [
        ['id' => 101, 'sku' => 'MAT-001', 'name' => 'Memory Foam Mattress', 'url' => 'https://hf.test/mat-001', 'url_key' => 'mat-001', 'price' => 299.0, 'category_ids' => [3], 'website_ids' => [1], 'store_ids' => [1,2,3], 'product_links' => [['type' => 'upsell', 'sku' => 'UNKNOWN-SKU']]],
        ['id' => 102, 'sku' => 'BED-001', 'name' => 'Pine Bed Frame',       'url' => 'https://hf.test/bed-001', 'url_key' => 'bed-001', 'price' => 199.0, 'category_ids' => [4], 'website_ids' => [1], 'store_ids' => [1,2]],
    ],
    'cbs' => [
        ['id' => 101, 'sku' => 'MAT-001',  'name' => 'Memory Foam Mattress (CBS)', 'url' => 'https://cbs.test/mat-001', 'url_key' => 'mat-001', 'price' => 249.0, 'category_ids' => [3], 'website_ids' => [1], 'store_ids' => [1,2,3], 'product_links' => [['type' => 'related', 'sku' => 'BED-001']]],
        ['id' => 103, 'sku' => 'CBS-EXCL', 'name' => 'CBS Exclusive',              'url' => 'https://cbs.test/cbs-excl', 'url_key' => 'cbs-excl', 'price' => 99.0, 'category_ids' => [3], 'website_ids' => [1], 'store_ids' => [2]],
    ],
    'mt' => [
        ['id' => 101, 'sku' => 'MAT-001', 'name' => 'Memory Foam Mattress (MT)', 'url' => 'https://mt.test/mat-001', 'url_key' => 'mat-001-mt', 'price' => 279.0, 'category_ids' => [3], 'website_ids' => [1], 'store_ids' => [1,2,3]],
    ],
];

$cmsPerStore = [
    'hf'  => [['id' => 1, 'identifier' => 'about-us', 'title' => 'About HF']],
    'cbs' => [['id' => 2, 'identifier' => 'about-us', 'title' => 'About CBS']],
    'mt'  => [['id' => 3, 'identifier' => 'about-us', 'title' => 'About MT']],
];

$scb = new \Magento\Framework\Api\SearchCriteriaBuilder();
$catEx = new \TSE\MagentoExporter\Model\CategoryExtractor(
    new CategoryListStub($sm, $catPerStore), new CategoryRepoStub(), $scb, $sm
);
$prodEx = new \TSE\MagentoExporter\Model\ProductExtractor(
    new ProductRepoStub($sm, $prodPerStore), $scb, new StockRegistryStub(), $sm
);
$cmsEx = new \TSE\MagentoExporter\Model\CmsPageExtractor(
    new PageRepoStub($sm, $cmsPerStore), $scb, $sm
);
$rel = new \TSE\MagentoExporter\Model\RelationshipBuilder();
$xstore = new \TSE\MagentoExporter\Model\CrossStoreMapper();
$store = new \TSE\MagentoExporter\Model\StoreContextResolver($websiteRepo, $groupRepo, $storeRepo, $sm);
$emul = new TrackingEmulation($sm, $byId);

$exporter = new \TSE\MagentoExporter\Model\Exporter(
    $store, $emul, $catEx, $prodEx, $cmsEx, $rel, $xstore
);

$bundle = $exporter->buildBundle();

// --- Assertions -------------------------------------------------------------
$fail = 0;
function check($label, $cond, $detail = '') {
    global $fail;
    $status = $cond ? 'PASS' : 'FAIL';
    if (! $cond) $fail++;
    echo "[$status] $label" . ($detail !== '' ? "  -- $detail" : '') . "\n";
}

echo "=== TSE Magento Exporter V1.0 multi-store smoke test ===\n";

// Hierarchy + global files
check('stores.json present',         isset($bundle['stores.json']));
check('stores.json lists 3 stores',  count($bundle['stores.json']['stores']) === 3);
check('manifest.json present',       isset($bundle['manifest.json']));
check('manifest.multi_store = true', $bundle['manifest.json']['multi_store'] === true);
check('manifest store_codes covers hf/cbs/mt', $bundle['manifest.json']['store_codes'] === ['hf','cbs','mt']);
check('manifest.files lists every produced file',
    count($bundle['manifest.json']['files']) === count(array_keys($bundle)));

// Per-store directories
$expected_per_store = ['products.json','categories.json','cms-pages.json','product-relationships.json','category-graph.json','product-category-edges.json'];
foreach (['hf','cbs','mt'] as $code) {
    foreach ($expected_per_store as $f) {
        check("stores/$code/$f present", isset($bundle["stores/$code/$f"]));
    }
}

// Emulation was called for every store
check('emulation started for each store', $emul->startedFor === [1, 2, 3], implode(',', $emul->startedFor));

// Store-scoped records carry store_id + store_code
$hfProducts = $bundle['stores/hf/products.json']['products'];
check('hf product 0 carries store_id=1',   $hfProducts[0]['store_id'] === 1);
check('hf product 0 carries store_code=hf', $hfProducts[0]['store_code'] === 'hf');
check('hf product 0 has website_ids',       $hfProducts[0]['website_ids'] === [1]);
check('product_links_raw stripped from public output', ! isset($hfProducts[0]['product_links_raw']));

// CBS-only product
$cbsProducts = $bundle['stores/cbs/products.json']['products'];
$cbsSkus = array_column($cbsProducts, 'sku');
check('CBS contains CBS-EXCL', in_array('CBS-EXCL', $cbsSkus, true));
check('HF does NOT contain CBS-EXCL', ! in_array('CBS-EXCL', array_column($hfProducts, 'sku'), true));

// Category divergence (CBS renamed Mattresses → Bed Sale, include_in_menu=false)
$hfCat3  = current(array_filter($bundle['stores/hf/categories.json']['categories'],  fn($c) => $c['id'] === 3));
$cbsCat3 = current(array_filter($bundle['stores/cbs/categories.json']['categories'], fn($c) => $c['id'] === 3));
check('HF cat 3 named Mattresses', $hfCat3['name'] === 'Mattresses');
check('CBS cat 3 renamed to Bed Sale', $cbsCat3['name'] === 'Bed Sale');
check('CBS cat 3 include_in_menu=false', $cbsCat3['include_in_menu'] === false);

// Cross-store map
$psm = $bundle['product-store-map.json'];
$matEntry = current(array_filter($psm['products'], fn($p) => $p['sku'] === 'MAT-001'));
check('MAT-001 marked as shared',     $matEntry['is_shared'] === true);
check('MAT-001 spans 3 store codes',  $matEntry['store_count'] === 3);
check('MAT-001 store_codes contains all 3', $matEntry['store_codes'] === ['hf','cbs','mt']);
$cbsOnly = current(array_filter($psm['products'], fn($p) => $p['sku'] === 'CBS-EXCL'));
check('CBS-EXCL is_shared=false', $cbsOnly['is_shared'] === false);
check('product-store-map totals.shared_skus = 1 (only MAT-001 in 3 stores)', $psm['totals']['shared_skus'] === 1);

// Category diff
$cdiff = $bundle['category-store-diff.json'];
$cat3diff = current(array_filter($cdiff['categories'], fn($c) => $c['category_id'] === 3));
check('category 3 diverges across stores', $cat3diff['diverges'] === true);
check('category-store-diff has diverging entries', $cdiff['totals']['diverging_categories'] >= 1);

// Cross-store relationship flag (HF upsell → UNKNOWN-SKU)
$flags = $bundle['relationship-cross-store-flags.json'];
$hasUnknown = false;
foreach ($flags['flags'] as $f) {
    if ($f['store_code'] === 'hf' && $f['target_sku'] === 'UNKNOWN-SKU') $hasUnknown = true;
}
check('HF upsell to UNKNOWN-SKU flagged as cross-store unknown', $hasUnknown);
check('relationship-cross-store-flags totals.target_unknown >= 1', $flags['totals']['target_unknown'] >= 1);

// Per-store relationship still functional
$cbsRel = $bundle['stores/cbs/product-relationships.json'];
check('CBS related edge present (MAT-001 → BED-001)',
    count(array_filter($cbsRel['edges'], fn($e) => $e['type'] === 'related' && $e['source_sku'] === 'MAT-001' && $e['target_sku'] === 'BED-001')) === 1);
check('CBS related edge target_known=false (BED-001 not in CBS catalog — correctly flagged cross-store)',
    current(array_filter($cbsRel['edges'], fn($e) => $e['target_sku'] === 'BED-001'))['target_known'] === false);
$cbsBedInFlags = false;
foreach ($flags['flags'] as $f) {
    if ($f['store_code'] === 'cbs' && $f['target_sku'] === 'BED-001') $cbsBedInFlags = true;
}
check('cross-store-flags surfaces CBS → BED-001 dangling reference', $cbsBedInFlags);

// Category graph
$hfGraph = $bundle['stores/hf/category-graph.json'];
check('HF category graph has 3 nodes', $hfGraph['totals']['nodes'] === 3);
check('HF category graph has 2 edges (2→3, 2→4)', $hfGraph['totals']['edges'] === 2);

// CMS pages per-store
check('HF CMS pages have store-specific title',
    $bundle['stores/hf/cms-pages.json']['pages'][0]['title'] === 'About HF');
check('CBS CMS pages have store-specific title',
    $bundle['stores/cbs/cms-pages.json']['pages'][0]['title'] === 'About CBS');

// ZipBuilder
require_once __DIR__ . '/tse-magento-exporter/TSE/MagentoExporter/Model/ZipBuilder.php';
$zb = new \TSE\MagentoExporter\Model\ZipBuilder();
$zp = $zb->build($bundle);
check('ZipBuilder produced a file',  file_exists($zp) && filesize($zp) > 0);
$z = new \ZipArchive(); $z->open($zp);
check('ZIP contains stores/hf/products.json', $z->locateName('stores/hf/products.json') !== false);
check('ZIP contains product-store-map.json',  $z->locateName('product-store-map.json')  !== false);
$z->close(); @unlink($zp);

echo "\n";
if ($fail === 0) { echo "ALL ASSERTIONS PASS\n"; exit(0); }
echo "FAILED: $fail assertion(s)\n"; exit(1);

} // namespace TSE\MagentoExporterTest
