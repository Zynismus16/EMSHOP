<?php
defined('EM_ROOT') || exit('access denied!');

/**
 * 测试模板 - 模块逻辑
 *
 * 在模板渲染前执行，用于生成导航等模板变量。
 * 通过 $this（View 实例）注入变量到模板。
 *
 * 变量命名约定：
 *   nav_ 前缀的变量专供 header.php / footer.php 使用
 */

// ============================================================
// 1. 收集全局数据（由 Dispatcher 在 render 前注入）
// ============================================================
$data = $this->getData();
$controller   = $data['_controller'] ?? 'index';      // 当前控制器名（已被 HOMEPAGE_MODE 替换）
$homepageMode = $data['homepage_mode'] ?? 'mall';     // 'mall' / 'goods_list' / 'blog'
$isHomepage   = !empty($data['is_homepage']);         // 当前是否落在站点根 "/"（替换前的原始判断）

// ============================================================
// 2. 判断当前页面身份（决定哪个导航项高亮）
// ============================================================
$isMallMode      = ($homepageMode === 'mall');
$isGoodsListMode = ($homepageMode === 'goods_list');

// is_homepage 优先 —— 各种首页模式下都让"首页"导航高亮，避免被替换后的 controller 干扰
if ($isHomepage) {
    $navId = 'home';
} elseif (in_array($controller, ['goods_list', 'goods', 'goods_index', 'goods_tag'], true)) {
    $navId = 'goods';
} elseif (in_array($controller, ['blog_list', 'blog_index', 'blog', 'blog_tag'], true)) {
    $navId = 'blog';
} else {
    $navId = '';
}

// ============================================================
// 3. 常用链接（其他页面可能用到）
// ============================================================
// URL 帮助函数按 Config('url_format') 生成对应格式。"商城"导航的目标按当前首页模式
// 分流——首页占用了哪个入口，导航就指向另一种页面，避免点击和首页重复：
//   mall      ：首页=goods_index → 商城导航去 goods_list
//   goods_list：首页=goods_list  → 商城导航去 goods_index
// 博客只剩「列表页 + 详情页」两个页面（原博客首页已并入列表页），导航固定指向列表页。
$navGoodsUrl = $isMallMode ? url_goods_list() : url_goods_index();
$navBlogUrl  = url_blog_list();
$navSearchUrl = url_search();

// ============================================================
// 4. 从数据库加载导航（NaviModel）—— 按当前 MerchantContext 过滤：
//    主站只看主站导航 + 系统导航；商户看自己的自定义 + 系统导航
// ============================================================
$naviModel = new NaviModel();
$naviTree = $naviModel->getEnabledTree(MerchantContext::currentId());

// 系统导航的链接根据首页模式动态调整
$systemLinkMap = [
    '首页' => url_home(),
    '商城' => $navGoodsUrl,
    '博客' => $navBlogUrl,
];

// 构建 navItems 供模板使用
$navItems = [];
foreach ($naviTree as $nav) {
    $url = $nav['link'];
    // 系统导航根据首页模式动态覆盖链接
    if ($nav['type'] === 'system' && isset($systemLinkMap[$nav['name']])) {
        $url = $systemLinkMap[$nav['name']];
    }

    $item = [
        'id'       => 'nav_' . $nav['id'],
        'text'     => $nav['name'],
        'url'      => $url,
        'target'   => $nav['target'] ?? '_self',
        'children' => [],
    ];

    // 子导航
    if (!empty($nav['children'])) {
        foreach ($nav['children'] as $child) {
            $item['children'][] = [
                'text'   => $child['name'],
                'url'    => $child['link'],
                'target' => $child['target'] ?? '_self',
            ];
        }
    }

    $navItems[] = $item;
}

// 系统「博客」导航是否启用（禁用时首页不展示文章板块）
$navBlogEnabled = false;
foreach ($naviTree as $nav) {
    if (($nav['type'] ?? '') === 'system' && ($nav['name'] ?? '') === '博客') {
        $navBlogEnabled = true;
        break;
    }
}

// 当前请求的 path（用于 URL 精确匹配高亮，规范化为以 / 开头、无尾斜杠）
$currentPath = '/';
if (!empty($_SERVER['REQUEST_URI'])) {
    $p = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_string($p) && $p !== '') {
        $currentPath = $p;
    }
}
$currentPath = '/' . trim($currentPath, '/');

// 生成 header 导航 HTML（支持二级下拉）
$navHtml = '';
foreach ($navItems as $item) {
    // 高亮判断：
    //   1) 主匹配：当前 URL path 和 nav item 的 URL path 完全一致（覆盖 CMS 页面、商品分类导航、自定义链接等）
    //   2) 兜底：对系统导航（首页 / 商城 / 博客），按名称 + $navId 匹配（让商品详情页也能高亮"商城"父项）
    $active = '';
    $itemText = $item['text'];

    $itemPath = '/';
    $ip = parse_url((string) ($item['url'] ?? ''), PHP_URL_PATH);
    if (is_string($ip) && $ip !== '') {
        $itemPath = $ip;
    }
    $itemPath = '/' . trim($itemPath, '/');

    if ($itemPath !== '/' && $itemPath === $currentPath) {
        // 非首页 + 精确匹配 → 高亮
        $active = ' active';
    } elseif ($itemText === '首页' && $navId === 'home') {
        $active = ' active';
    } elseif ($itemText === '商城' && $navId === 'goods') {
        $active = ' active';
    } elseif ($itemText === '博客' && $navId === 'blog') {
        $active = ' active';
    }

    $hasChildren = !empty($item['children']);
    $targetAttr = ($item['target'] === '_blank') ? ' target="_blank"' : '';
    $itemNavPathAttr = ($itemPath !== '/')
        ? (' data-nav-path="' . htmlspecialchars($itemPath, ENT_QUOTES, 'UTF-8') . '"')
        : '';

    if ($hasChildren) {
        $navHtml .= '<div class="nav-dropdown" data-nav="' . $item['id'] . '">';
        $navHtml .= '<a href="' . htmlspecialchars(safe_url((string) $item['url'])) . '" data-pjax data-nav="' . $item['id'] . '" class="' . trim($active) . '"' . $itemNavPathAttr . $targetAttr . '>'
                  . htmlspecialchars($item['text'])
                  . '<svg class="nav-arrow" width="10" height="10" viewBox="0 0 10 10"><path d="M2 3.5L5 6.5L8 3.5" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>'
                  . '</a>';
        $navHtml .= '<div class="nav-dropdown-menu">';
        foreach ($item['children'] as $child) {
            $childPath = '/';
            $cip = parse_url((string) ($child['url'] ?? ''), PHP_URL_PATH);
            if (is_string($cip) && $cip !== '') {
                $childPath = $cip;
            }
            $childPath = '/' . trim($childPath, '/');
            $childNavPathAttr = ($childPath !== '/')
                ? (' data-nav-path="' . htmlspecialchars($childPath, ENT_QUOTES, 'UTF-8') . '"')
                : '';
            $childTarget = ($child['target'] === '_blank') ? ' target="_blank"' : '';
            $navHtml .= '<a href="' . htmlspecialchars(safe_url((string) $child['url'])) . '" data-pjax' . $childNavPathAttr . $childTarget . '>' . htmlspecialchars($child['text']) . '</a>';
        }
        $navHtml .= '</div></div>';
    } else {
        $navHtml .= '<a href="' . htmlspecialchars(safe_url((string) $item['url'])) . '" data-pjax data-nav="' . $item['id'] . '" class="' . trim($active) . '"' . $itemNavPathAttr . $targetAttr . '>' . htmlspecialchars($item['text']) . '</a>';
    }
}

// 生成 footer 导航 HTML
$navFooterHtml = '';
foreach ($navItems as $item) {
    $navFooterHtml .= '<a href="' . htmlspecialchars(safe_url((string) $item['url'])) . '" data-pjax>' . htmlspecialchars($item['text']) . '</a>';
}

// ============================================================
// 5. 前台用户登录状态（从数据库刷新实时数据）
// ============================================================
$frontUser = $_SESSION['em_front_user'] ?? null;
if ($frontUser && !empty($frontUser['id'])) {
    $freshUser = (new UserListModel())->findById((int) $frontUser['id']);
    if ($freshUser) {
        $frontUser['money']    = (int) ($freshUser['money'] ?? 0);
        $frontUser['nickname'] = (string) ($freshUser['nickname'] ?: $freshUser['username']);
        $frontUser['avatar']   = (string) $freshUser['avatar'];
        $frontUser['email']    = (string) $freshUser['email'];
        $frontUser['mobile']   = (string) ($freshUser['mobile'] ?? '');
        $_SESSION['em_front_user'] = $frontUser;
    }
}

// ============================================================
// 6. ICP 备案 + 第三方统计代码
//    - 主站 / 二级域名访问：用主站 site_icp（同一个备案主体）
//    - 自定义顶级域名访问：必须用商户自己 merchant.icp
//      （因为该域名是商户独立备案的，不属于主站备案号）
//    - 统计代码暂不区分（只主站可配置，sub-站自动复用主站埋点；后续如需可扩）
// ============================================================
$_currentHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
if (($_p = strpos($_currentHost, ':')) !== false) $_currentHost = substr($_currentHost, 0, $_p);

$_mc = MerchantContext::current();
$_onCustomDomain = $_mc !== null
    && !empty($_mc['custom_domain'])
    && strtolower((string) $_mc['custom_domain']) === $_currentHost;

$siteIcp = $_onCustomDomain
    ? (string) ($_mc['icp'] ?? '')
    : (string) (Config::get('site_icp') ?? '');
$siteStatisticalCode = (string) (Config::get('site_statistical_code') ?? '');
$userLoginEnabled    = (string) (Config::get('user_login', '1')) === '1';
$userRegisterEnabled = (string) (Config::get('user_register', '0')) === '1';

// ============================================================
// 商品列表页：解析请求参数 + 定页面标题
// ============================================================
// 为什么参数解析放这里，而不是控制器、也不是 goods_list.php：
//   页面标题必须在 body 渲染前确定（View 先渲染 header、再渲染 body），而标题
//   （分类名 / 标签名）来自请求参数。放在 module.php（body 之前执行、且是主题自己的文件）
//   既能保持 GoodsController::display() 只做「调用页面」，又让模板不必再解析一遍参数
//   ——模板直接用下面 assign 的 $list_q 取数即可。
//
// 参数必须用 Dispatcher::getArg() 取：pretty URL（/post-{分类}-{id}.html 等）的参数
// 在 pathArgs 里，不在 $_GET。
$listQ = null;
if (in_array($controller, ['goods_list', 'goods_index'], true)) {
    $d = Dispatcher::getInstance();
    $categoryId     = (int) $d->getArg('category_id', 0);
    $categorySource = (string) $d->getArg('category_source', 'main');
    if (!in_array($categorySource, ['main', 'merchant'], true)) {
        $categorySource = 'main';
    }
    $tagId = (int) $d->getArg('tag_id', 0);
    $page  = max(1, (int) $d->getArg('page', 1));
    $sort  = trim((string) $d->getArg('sort', 'default'));
    if (!in_array($sort, ['default', 'hot', 'sold', 'price_asc', 'price_desc'], true)) {
        $sort = 'default';
    }

    // slug 路由兼容（仅主站分类有 slug 字段）：?slug=xxx 或 /goods_list/slug/xxx
    if ($categoryId <= 0) {
        $slug = trim((string) $d->getArg('slug', ''));
        if ($slug !== '' && preg_match('/^[a-zA-Z0-9_\-\p{Han}]+$/u', $slug)) {
            $row = Database::fetchOne(
                'SELECT `id` FROM `' . Database::prefix() . 'goods_category` WHERE `slug` = ? AND `status` = 1 LIMIT 1',
                [$slug]
            );
            if ($row) {
                $categoryId = (int) $row['id'];
                $categorySource = 'main';
            }
        }
    }

    // 页面标题：标签优先，其次分类名，都没有就是「全部商品」
    $title = '全部商品';
    if ($tagId > 0) {
        $tag = GoodsTagModel::getById($tagId);
        if ($tag) {
            $title = '标签：' . $tag['name'];
        }
    } elseif ($categoryId > 0) {
        $row = Database::fetchOne(
            'SELECT `name` FROM `' . Database::prefix() . 'goods_category` WHERE `id` = ? AND `status` = 1 LIMIT 1',
            [$categoryId]
        );
        if ($row) {
            $title = (string) $row['name'];
        }
    }
    $this->setTitle($title);

    $listQ = [
        'category_id'     => $categoryId,
        'category_source' => $categorySource,
        'tag_id'          => $tagId,
        'page'            => $page,
        'sort'            => $sort,
        // 解析后的展示名（分类名 / 标签名 / 全部商品）。标题已经查过了，顺手带下去，
        // 模板「筛选分类」按钮第二行显示当前分类时就不用再查一次。
        'filter_label'    => $title,
    ];
}

// ============================================================
// 文章列表页：解析请求参数 + 定页面标题
// ============================================================
// 与商品列表页同理：标题必须在 body 渲染前确定，模板里 setTitle() 已经太晚。
// 解析结果 assign 成 $blog_q，模板（blog_list.php）用它取数。
// 参数必须用 Dispatcher::getArg() 取：pretty URL（/blog-list-{分类}-{页}.html、
// /blog/list/{分类}）的参数在 pathArgs 里，不在 $_GET。
$blogQ = null;
if (in_array($controller, ['blog_list', 'blog_index'], true)) {
    $d = Dispatcher::getInstance();
    $categoryId = (int) $d->getArg('category_id', 0);
    $tagId      = (int) $d->getArg('tag_id', 0);
    $page       = max(1, (int) $d->getArg('page', 1));

    // 页面标题：标签优先，其次分类名，都没有就是「全部文章」
    // （判断规则与 template_blog_list_data() 的筛选保持一致：标签要属于当前 scope）
    $title = '全部文章';
    if ($tagId > 0) {
        $tag = BlogTagModel::getById($tagId);
        if ($tag && (int) $tag['merchant_id'] === MerchantContext::currentId()) {
            $title = '标签：' . $tag['name'];
        }
    }
    if ($categoryId > 0) {
        $row = Database::fetchOne(
            'SELECT `name` FROM `' . Database::prefix() . 'blog_category`
              WHERE `id` = ? AND `status` = 1 AND `merchant_id` = ? LIMIT 1',
            [$categoryId, MerchantContext::currentId()]
        );
        if ($row) {
            $title = (string) $row['name'];
        }
    }
    $this->setTitle($title);

    $blogQ = [
        'category_id'  => $categoryId,
        'tag_id'       => $tagId,
        'page'         => $page,
        // 解析后的展示名（分类名 / 标签名 / 全部文章），顺手带下去省一次查询
        'filter_label' => $title,
    ];
}

// ============================================================
// 搜索页：解析请求参数 + 定页面标题
// ============================================================
// 与商品/文章列表页同理：标题必须在 body 渲染前确定，模板里 setTitle() 已经太晚。
// 解析结果 assign 成 $search_q，模板（search.php）用它取数。
$searchQ = null;
if ($controller === 'search') {
    $d = Dispatcher::getInstance();
    $keyword = trim((string) $d->getArg('q', ''));
    $type    = trim((string) $d->getArg('type', 'all'));
    if (!in_array($type, ['all', 'goods', 'article'], true)) {
        $type = 'all';
    }

    $this->setTitle($keyword !== '' ? '搜索：' . $keyword : '商品搜索');

    $searchQ = [
        'keyword' => $keyword,
        'type'    => $type,
    ];
}

// ============================================================
// 7. 注入模板变量（供 header.php / footer.php 直接输出）
// ============================================================
$this->assign([
    'list_q'                => $listQ,
    'blog_q'                => $blogQ,
    'search_q'              => $searchQ,
    'nav_html'              => $navHtml,
    'nav_items'             => $navItems,
    'nav_footer_html'       => $navFooterHtml,
    'nav_current_path'      => $currentPath,
    'nav_id'                => $navId,
    'nav_goods_url'         => $navGoodsUrl,
    'nav_blog_url'          => $navBlogUrl,
    'nav_blog_enabled'      => $navBlogEnabled,
    'nav_search_url'        => $navSearchUrl,
    'user_login_enabled'    => $userLoginEnabled,
    'user_register_enabled' => $userRegisterEnabled,
    'front_user'            => $frontUser,
    'site_icp'              => $siteIcp,
    'site_statistical_code' => $siteStatisticalCode,
]);

// ============================================================
// 8. 页面数据方法（模板里直接调用）
// ============================================================
// 模板要保持"只有 markup、没有取数逻辑"：取数写在这里，模板开头调一次拿数据即可。
// 这些是**主题自己的函数**（全局命名空间，统一用 template_ 前缀避免与其他主题撞名）。
//
// 取数统一走 FrontDataService —— 与改造前控制器用的是同一套逻辑（商户隔离、价格换算、
// 上下架/软删过滤、goods_delivery_type / goods_stock_display 过滤器都一致）。

/**
 * 首页数据：精选商品（后台「推荐」标记）+ 最新动态 + 公告。
 *
 * @return array<string, mixed>
 */
function template_home_data(): array
{
    $fs  = new FrontDataService();
    $cfg = template_config();

    // 精选商品显示几个：主题设置页可配，默认 6（保存时已校验，这里再兜一次防止库里被写成怪值）
    $featuredMax = max(1, min(30, (int) ($cfg['featured_count'] ?? 6)));

    $featured = $fs->queryGoodsList(['is_recommended' => true], $featuredMax, 'g.sort ASC, g.id DESC');
    // 插件扩展点：沿用改造前的 index_goods_list(list, ctx) 契约
    $featured = applyFilter('index_goods_list', $featured, 'recommended');

    return [
        'featured'        => $featured,
        'recent_articles' => $fs->queryArticleList([], 6),
        'announcement'    => $fs->getCurrentAnnouncement(),
    ];
}

/**
 * 商品列表页数据：商品分页 + 分类树 + 公告，以及拼链接要用的参数。
 *
 * @param array<string, mixed> $q 请求参数（module.php 上面解析好的 $list_q）
 * @return array<string, mixed>
 */
function template_goods_list_data(array $q): array
{
    $fs = new FrontDataService();

    $categoryId     = (int) ($q['category_id'] ?? 0);
    $categorySource = (string) ($q['category_source'] ?? 'main');
    $tagId          = (int) ($q['tag_id'] ?? 0);
    $page           = max(1, (int) ($q['page'] ?? 1));
    $sort           = (string) ($q['sort'] ?? 'default');

    // 分类树：分类筛选 Tab + 每个分类的商品计数
    $categories = $fs->getGoodsSidebarData()['goods_categories'] ?? [];

    // 查询条件：选中父分类时把子分类也算进来。
    // 必须在分类树里按「id + source」一起匹配 —— 主站分类与商户自建分类的 id 可能撞号。
    $where = [];
    if ($tagId > 0) {
        $where['tag_id'] = $tagId;
    }
    if ($categoryId > 0) {
        $categoryIds = [$categoryId];
        foreach ($categories as $cat) {
            if ((int) $cat['id'] === $categoryId && (string) ($cat['source'] ?? 'main') === $categorySource) {
                foreach ($cat['children'] ?? [] as $child) {
                    $categoryIds[] = (int) $child['id'];
                }
                break;
            }
            foreach ($cat['children'] ?? [] as $child) {
                if ((int) $child['id'] === $categoryId && (string) ($child['source'] ?? 'main') === $categorySource) {
                    break 2;
                }
            }
        }
        $where['category_ids']    = $categoryIds;
        $where['category_source'] = $categorySource;
    }

    $result         = $fs->queryGoodsListPaginated($where, $page, 20, $fs->resolveGoodsListOrderBy($sort));
    $result['list'] = applyFilter('index_goods_list', $result['list'], 'list');

    // 商品列表排序功能（主题配置，开关，默认开启）
    $sortEnabled = (string) (template_config()['goods_sort_enabled'] ?? '1') !== '0';

    // 拼筛选/翻页链接用的基础参数（模板里 url_append 直接用）
    $baseParams = [];
    if ($categoryId > 0) {
        $baseParams['category_id'] = $categoryId;
        if ($categorySource === 'merchant') {
            $baseParams['category_source'] = 'merchant';
        }
    }
    if ($tagId > 0) {
        $baseParams['tag_id'] = $tagId;
    }

    return [
        'goods_list'       => $result['list'],
        'pagination'       => $result,
        'goods_categories' => $categories,
        'category_id'      => $categoryId,
        'category_source'  => $categorySource,
        'tag_id'           => $tagId,
        'sort'             => $sort,
        'sort_params'      => ($sort !== 'default') ? ['sort' => $sort] : [],
        'base_params'      => $baseParams,
        'sort_enabled'     => $sortEnabled,
        'filter_label'     => (string) ($q['filter_label'] ?? '全部商品'),
        'announcement'     => $fs->getCurrentAnnouncement(),
    ];
}

// ============================================================
// 9. 商品详情页数据方法（模板里直接调用）
// ============================================================
// 与第 8 节同理：取数写在这里，模板只渲染。

/**
 * 获取商品的多维规格维度及其维度值。
 *
 * @return array<array{id:int, name:string, values:array}>
 */
function template_goods_spec_dims(int $goodsId): array
{
    $prefix = Database::prefix();

    $dims = Database::query(
        "SELECT id, name FROM {$prefix}goods_spec_dim WHERE goods_id = ? ORDER BY sort ASC, id ASC",
        [$goodsId]
    );

    if (empty($dims)) {
        return [];
    }

    $values = Database::query(
        "SELECT id, dim_id, name FROM {$prefix}goods_spec_value WHERE goods_id = ? ORDER BY sort ASC, id ASC",
        [$goodsId]
    );

    // 按维度分组
    $valuesByDim = [];
    foreach ($values as $v) {
        $valuesByDim[(int) $v['dim_id']][] = [
            'id'   => (int) $v['id'],
            'name' => $v['name'],
        ];
    }

    $result = [];
    foreach ($dims as $d) {
        $dimId = (int) $d['id'];
        // 仅返回有维度值的维度
        if (!empty($valuesByDim[$dimId])) {
            $result[] = [
                'id'     => $dimId,
                'name'   => $d['name'],
                'values' => $valuesByDim[$dimId],
            ];
        }
    }
    return $result;
}

/**
 * 构建商品详情页用的表单字段数据。
 *
 * 输出一个扁平 section 数组，供任意模板直接遍历渲染；
 * 把"附加选项映射、查单模式字段合并、登录态判断"等逻辑集中在这里，
 * 模板只需关心样式，便于新模板复用。
 *
 * 顺序：附加选项 → 查单模式（仅未登录时）
 *
 * 每个 section 结构：
 *   [
 *     'id'     => string  section 容器 id（空字符串表示不需要外层容器）
 *     'group'  => 'extra' | 'guest_find_contact' | 'guest_find_password'
 *     'fields' => array<field>
 *   ]
 *
 * 每个 field 结构：
 *   [
 *     'name'        => string  input name
 *     'id'          => string  input id（可选）
 *     'label'       => string  标签文字
 *     'type'        => string  HTML input type
 *     'placeholder' => string
 *     'required'    => bool
 *     'maxlength'   => int
 *     'hidden'      => [ 'id' => ..., 'name' => ..., 'value' => ... ]?  伴随 hidden
 *   ]
 */
function template_goods_form_sections(array $configs, bool $isGuest): array
{
    $sections = [];

    // —— 附加选项（商品 configs.extra_fields）
    $extraFields = [];
    foreach (($configs['extra_fields'] ?? []) as $ef) {
        $name = (string) ($ef['name'] ?? '');
        if ($name === '') continue;

        // format → HTML input type + maxlength 默认值
        $format = $ef['format'] ?? 'text';
        $type = 'text';
        $maxLen = 64;
        if ($format === 'email') { $type = 'email'; }
        elseif ($format === 'phone') { $type = 'tel'; $maxLen = 20; }
        elseif ($format === 'number') { $type = 'number'; $maxLen = 20; }

        $extraFields[] = [
            'name'        => 'extra_' . $name,
            'id'          => '',
            'label'       => (string) ($ef['title'] ?? $name),
            'type'        => $type,
            'placeholder' => (string) ($ef['placeholder'] ?? ''),
            'required'    => !empty($ef['required']),
            'maxlength'   => $maxLen,
        ];
    }
    if ($extraFields) {
        $sections[] = [
            'id'     => '',
            'group'  => 'extra',
            'fields' => $extraFields,
        ];
    }

    // —— 查单模式字段（仅未登录用户）
    if ($isGuest) {
        $gf = GuestFindModel::getConfig();

        if (!empty($gf['contact_enabled'])) {
            $contactIconMap = [
                GuestFindModel::CONTACT_TYPE_PHONE => 'fa-mobile',
                GuestFindModel::CONTACT_TYPE_EMAIL => 'fa-envelope-o',
                GuestFindModel::CONTACT_TYPE_QQ    => 'fa-qq',
            ];
            $contactType = (string) ($gf['contact_type'] ?? GuestFindModel::CONTACT_TYPE_ANY);
            $sections[] = [
                'id'     => 'guestFindContactSection',
                'group'  => 'guest_find_contact',
                'fields' => [
                    [
                        'name'        => 'guest_find_contact_query',
                        'id'          => 'guestFindContactQuery',
                        'label'       => (string) $gf['contact_type_label'],
                        'icon'        => $contactIconMap[$contactType] ?? 'fa-phone',
                        'type'        => (string) $gf['contact_input_type'],
                        'placeholder' => (string) $gf['contact_checkout_placeholder'],
                        'required'    => true,
                        'maxlength'   => 32,
                        // 伴随 hidden：记录当前联系方式类型（供 JS 读取）
                        'hidden'      => [
                            'id'    => 'guestFindContactType',
                            'name'  => '',
                            'value' => (string) $gf['contact_type'],
                        ],
                    ],
                ],
            ];
        }
        if (!empty($gf['password_enabled'])) {
            $sections[] = [
                'id'     => 'guestFindPasswordSection',
                'group'  => 'guest_find_password',
                'fields' => [
                    [
                        'name'        => 'guest_find_password_query',
                        'id'          => 'guestFindPasswordQuery',
                        'label'       => '订单密码',
                        'icon'        => 'fa-lock',
                        // 下单时"设置"订单密码，用明文 text 便于用户确认输入
                        'type'        => 'text',
                        'placeholder' => (string) $gf['password_checkout_placeholder'],
                        'required'    => true,
                        'maxlength'   => 32,
                    ],
                ],
            ];
        }
    }

    return $sections;
}

/**
 * 商品详情页数据：规格 / 价格 / 支付方式 / 表单字段 / 收货地址。
 *
 * @param array<string, mixed>|null $row 商品行。控制器为「能不能打开这一页」的判断
 *        已经查过一次，这里直接复用，不再重复查库。
 * @return array<string, mixed>
 */
function template_goods_content_data(?array $row): array
{
    // 下面从控制器搬来的代码用 $id 引用商品 id
    $id = $row !== null ? (int) $row["id"] : 0;
    $goods = null;
    $specs = [];
    $specDims = [];
    $specsJson = "[]";
    if ($row !== null) {
                    // 获取所有规格（价格已自动转换）
                    $rawSpecs = GoodsModel::getSpecsByGoodsId($id);
                    $defaultSpec = null;
                    foreach ($rawSpecs as $s) {
                        if ((int) $s['is_default'] === 1) {
                            $defaultSpec = $s;
                            break;
                        }
                    }
                    if (!$defaultSpec && !empty($rawSpecs)) {
                        $defaultSpec = $rawSpecs[0];
                    }

                    // 预加载 combo 数据（spec_id → value_ids 映射）
                    $comboMap = [];
                    $combos = Database::query(
                        "SELECT spec_id, value_ids FROM " . Database::prefix() . "goods_spec_combo WHERE goods_id = ?",
                        [$id]
                    );
                    foreach ($combos as $c) {
                        $comboMap[(int) $c['spec_id']] = json_decode($c['value_ids'], true) ?: [];
                    }

                    // 构建前端用的规格数据
                    // stock 是原始整数（供 JS 业务判断），stock_text 是展示文字（默认千分位，可被插件替换）
                    foreach ($rawSpecs as $s) {
                        $stockInt = (int) $s['stock'];
                        // tags：DB 存 JSON 数组（如 ["热卖","新品"]），后台保存时已 json_encode；
                        // 前端切换规格后在规格区上方展示这些徽章
                        $tagList = [];
                        if (!empty($s['tags'])) {
                            $decoded = is_string($s['tags']) ? json_decode($s['tags'], true) : $s['tags'];
                            if (is_array($decoded)) {
                                $tagList = array_values(array_filter(array_map('strval', $decoded), 'strlen'));
                            }
                        }
                        $specs[] = [
                            'id'           => (int) $s['id'],
                            'name'         => $s['name'],
                            'price'        => (float) $s['price'],
                            'market_price' => $s['market_price'] ? (float) $s['market_price'] : null,
                            'stock'        => $stockInt,
                            'stock_text'   => FrontDataService::formatStockText($stockInt),
                            'sold_count'   => (int) ($s['sold_count'] ?? 0),
                            'min_buy'      => (int) ($s['min_buy'] ?? 1),
                            'max_buy'      => (int) ($s['max_buy'] ?? 0),
                            'is_default'   => (int) $s['is_default'],
                            'value_ids'    => $comboMap[(int) $s['id']] ?? [],
                            'tags'         => $tagList,
                        ];
                    }

                    // 规格数据扩展点：插件可往每个规格上挂自己的载荷（随 specs_json 一起下发给前台 JS），
                    // 例如按规格的优惠规则。核心不认这些键，原样透传。
                    // 这里 $goods 还没组装出来，给的是商品原始行（$row），插件需要什么自己取。
                    $specs = applyFilter('goods_detail_specs', $specs, $row);
                    if (!is_array($specs)) {
                        $specs = [];
                    }

                    // 获取多维规格数据（维度 + 维度值）
                    // 注意：tags 不会被注入到 dim.value 上 —— 因为 tags 是"规格组合行"级别（如"红+S"），
                    // 把它并集到维度值（如"红"）会让按钮挂上其它组合的标签，语义不准。
                    // 多维度场景下 tags 由 JS 在用户选完规格组合后，按当前 spec.tags 渲染到 #specTags 容器。
                    $specDims = template_goods_spec_dims($id);

                    // 规格 JSON（供 JS 切换价格/库存）
                    $specsJson = json_encode($specs, JSON_UNESCAPED_UNICODE);

                    // 获取分类名
                    $categoryName = '';
                    if ((int) $row['category_id'] > 0) {
                        $catModel = new GoodsCategoryModel();
                        $cat = $catModel->findById((int) $row['category_id']);
                        $categoryName = $cat ? $cat['name'] : '';
                    }

                    // 递增浏览量
                    Database::execute(
                        "UPDATE " . Database::prefix() . "goods SET views_count = views_count + 1 WHERE id = ?",
                        [$id]
                    );

                    $covers = json_decode($row['cover_images'] ?? '[]', true) ?: [];
                    // 解析商品配置（附加选项 / 返佣 / SEO 等；插件的数据走各自挂载点）
                    $configs = json_decode($row['configs'] ?? '{}', true) ?: [];
                    $defaultDeliveryType = 'manual';
                    if (!empty($row['goods_type']) && class_exists('GoodsTypeManager')) {
                        $typeCfg = GoodsTypeManager::getTypeConfig((string) $row['goods_type']);
                        if ($typeCfg && !empty($typeCfg['delivery_type'])) {
                            $defaultDeliveryType = (string) $typeCfg['delivery_type'];
                        }
                    }
                    $deliveryType = applyFilter('goods_delivery_type', $defaultDeliveryType, $row);

                    // 多规格：首屏「库存」展示各规格库存之和（与 goods.total_stock 缓存一致）；单规格仍用该行库存
                    $multiSpec = count($rawSpecs) > 1;
                    $stock = $multiSpec
                        ? (int) ($row['total_stock'] ?? 0)
                        : ($defaultSpec ? (int) $defaultSpec['stock'] : (int) ($row['total_stock'] ?? 0));
                    $goods = [
                        'id'             => (int) $row['id'],
                        'name'           => $row['title'],
                        'goods_type'     => (string) ($row['goods_type'] ?? ''),
                        'delivery_type'  => $deliveryType,
                        'image'          => $covers[0] ?? '',
                        'images'         => $covers,
                        'price'          => $defaultSpec ? (float) $defaultSpec['price'] : (float) $row['min_price'],
                        'original_price' => FrontDataService::resolveOriginalPrice($defaultSpec),
                        // stock 是业务数字（整数），stock_text 是展示文字（默认千分位，可被插件替换）
                        'stock'          => $stock,
                        'stock_text'     => FrontDataService::formatStockText($stock),
                        'min_buy'        => $defaultSpec ? (int) ($defaultSpec['min_buy'] ?? 1) : 1,
                        'max_buy'        => $defaultSpec ? (int) ($defaultSpec['max_buy'] ?? 0) : 0,
                        'category'       => $categoryName,
                        'sku'            => $row['code'],
                        'description'    => $row['intro'] ?: '',
                        'content'        => $row['content'] ?: '',
                        'tags'           => GoodsTagModel::getTagsByGoodsId($id),
                        'configs'        => $configs,
                        'unit'           => $row['unit'] ?: '件',
                        'total_sold'     => array_sum(array_column($specs, 'sold_count')),
                    ];
    }

        // 构建详情页表单字段（附加选项 + 查单模式），视图直接遍历渲染即可
        // 未登录用户才会拿到查单模式字段；登录用户订单天然与账户关联，无需游客查单
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $isGuest = empty($_SESSION['em_front_user']);

        // 获取支付方式，并在控制器里预先标记禁用态和默认选中项（视图直接按标记渲染）
        // 规则：
        //   - 余额支付 + 未登录 → disabled（不可选），避免前端错误提交
        //   - 默认选中：第一个 disabled=false 的方式
        $paymentMethods = PaymentService::getMethods();
        $defaultAssigned = false;
        foreach ($paymentMethods as &$pm) {
            $pm['disabled'] = ($pm['code'] === 'balance' && $isGuest);
            $pm['selected'] = false;
            if (!$defaultAssigned && !$pm['disabled']) {
                $pm['selected'] = true;
                $defaultAssigned = true;
            }
        }
        unset($pm);

        // 商品级支付方式限制：插件按本商品过滤列表 / 把不允许的方式标成禁用态
        // 必须放在上面的 disabled/selected 循环之后：插件才能看到核心已算好的标记，
        // 并在过滤后负责重算默认选中项（否则原选中项被隐藏会没人 active）
        $paymentMethods = applyFilter('goods_detail_payment_methods', $paymentMethods, $goods, $row, $isGuest);

        $formSections = $goods
            ? template_goods_form_sections($goods['configs'] ?? [], $isGuest)
            : [];

        // 本商品是否要求收货地址（由商品类型插件在 goods_type_register 里声明 needs_address=true）
        // 模板 / JS 据此在"立即购买"时弹出地址选择或手填表单；不需要时保持现有流程不变
        $needsAddress = false;
        if ($goods && !empty($goods['goods_type']) && class_exists('GoodsTypeManager')) {
            $typeCfg = GoodsTypeManager::getTypeConfig((string) $goods['goods_type']);
            $needsAddress = !empty($typeCfg['needs_address']);
            $needsAddress = (bool) applyFilter('goods_needs_address', $needsAddress, $goods);
        }
        // 登录用户预拉地址簿，省一次 AJAX；游客走前端手填不预拉
        $userAddresses = [];
        $defaultAddressId = 0;
        $buyerId = (int) ($GLOBALS['frontUser']['id'] ?? $_SESSION['em_front_user']['id'] ?? 0);
        if ($needsAddress && $buyerId > 0) {
            $userAddresses = UserAddressModel::listByUserId($buyerId);
            foreach ($userAddresses as $addr) {
                if ((int) ($addr['is_default'] ?? 0) === 1) {
                    $defaultAddressId = (int) $addr['id'];
                    break;
                }
            }
            if ($defaultAddressId === 0 && !empty($userAddresses)) {
                $defaultAddressId = (int) $userAddresses[0]['id'];
            }
        }

    return [
            'goods'              => $goods,
            'specs'              => $specs,
            'spec_dims'          => $specDims,
            'specs_json'         => $specsJson,
            'payment_methods'    => $paymentMethods,
            'form_sections'      => $formSections,
            'needs_address'      => $needsAddress,
            'user_addresses'     => $userAddresses,
            'default_address_id' => $defaultAddressId,
            // 注意：unavailable_reason 不在这里返回 —— 它属于控制器的守卫判断（下架/售罄），
            // 由控制器通过 setData 直接给模板，模板里同名变量即可。
        ];
}

// ============================================================
// 10. 博客页面数据方法（模板里直接调用）
// ============================================================
// 与第 8 节同理：取数写在这里，模板只渲染。

/**
 * 文章列表页数据：文章分页 + 分类树 + 分类/标签筛选。
 *
 * @param array<string, mixed> $q 请求参数（module.php 解析好的 $blog_q）
 * @return array<string, mixed>
 */
function template_blog_list_data(array $q): array
{
    $fs = new FrontDataService();

    $categoryId = (int) ($q['category_id'] ?? 0);
    $tagId      = (int) ($q['tag_id'] ?? 0);
    $page       = max(1, (int) ($q['page'] ?? 1));

    // 侧栏分类树（模板的分类筛选栏也用这一棵）
    $sidebarData = $fs->getBlogSidebarData();
    $categories  = $sidebarData['blog_categories'] ?? [];

    $where = [];
    // 标签筛选 —— 标签 ID 必须属于当前 scope
    if ($tagId > 0) {
        $tag = BlogTagModel::getById($tagId);
        if ($tag && (int) $tag['merchant_id'] === MerchantContext::currentId()) {
            $where['tag_id'] = $tagId;
        }
    }

    // 分类筛选（选中父分类时把所有子分类也算进来）
    if ($categoryId > 0) {
        $categoryIds = [$categoryId];
        foreach ($categories as $cat) {
            if ((int) $cat['id'] === $categoryId) {
                foreach ($cat['children'] ?? [] as $child) {
                    $categoryIds[] = (int) $child['id'];
                }
                break;
            }
            foreach ($cat['children'] ?? [] as $child) {
                if ((int) $child['id'] === $categoryId) {
                    break 2;
                }
            }
        }
        $where['category_ids'] = $categoryIds;
    }

    $result = $fs->queryArticleListPaginated($where, $page, 20);

    return [
        'article_list'      => $result['list'],
        'pagination'        => $result,
        'blog_categories'   => $categories,
        'popular_blog_tags' => $sidebarData['popular_blog_tags'] ?? [],
        'current_category'  => $categoryId,
        'current_tag'       => $tagId,
        'announcement'      => $fs->getCurrentAnnouncement(),
        // 解析后的展示名（分类名 / 标签名 / 全部文章）：module.php 定标题时已经查过一次
        'filter_label'      => (string) ($q['filter_label'] ?? '全部文章'),
    ];
}

/**
 * 文章详情页数据：正文装配 + 上下篇 + 评论数 + 侧栏（分类树 / 最新文章）。
 *
 * @param array<string, mixed>|null $row 文章行。控制器为「能不能打开这一页」的判断和
 *        页面标题已经查过一次，这里直接复用，不再重复查库。
 * @return array<string, mixed>
 */
function template_blog_content_data(?array $row): array
{
    $article   = null;
    $prevId    = null;
    $prevTitle = null;
    $nextId    = null;
    $nextTitle = null;

    if ($row !== null) {
        $id = (int) $row['id'];

        // 递增浏览量：页面上显示的阅读数取「递增后」的值（与搬走前一致）
        BlogModel::incrementViews($id);

        $article = [
            'id'       => $id,
            'title'    => $row['title'],
            'content'  => $row['content'] ?: '',
            'date'     => substr($row['created_at'], 0, 10),
            'author'   => $row['author'] ?: '管理员',
            'category' => $row['category_name'] ?: '未分类',
            'views'    => (int) $row['views_count'] + 1,
            'tags'     => BlogTagModel::getTagsByBlogId($id),
        ];

        // 上下篇（限定 scope 内）
        $nav = BlogModel::getPrevNextId($id, MerchantContext::currentId());
        $prevId    = $nav['prev_id'];
        $prevTitle = $nav['prev_title'];
        $nextId    = $nav['next_id'];
        $nextTitle = $nav['next_title'];
    }

    // 侧栏（分类树 + 热门标签）+ 最新文章
    $fs = new FrontDataService();
    $sidebarData = $fs->getBlogSidebarData();

    return [
        'article'           => $article,
        'prev_id'           => $prevId,
        'prev_title'        => $prevTitle,
        'next_id'           => $nextId,
        'next_title'        => $nextTitle,
        'comment_count'     => $article !== null ? BlogCommentModel::getCountByBlog((int) $article['id']) : 0,
        'recent_articles'   => $fs->queryArticleList([], 5),
        'blog_categories'   => $sidebarData['blog_categories'] ?? [],
        'popular_blog_tags' => $sidebarData['popular_blog_tags'] ?? [],
    ];
}

// ============================================================
// 11. 搜索页数据方法（模板里直接调用）
// ============================================================
// 与第 8 / 10 节同理：取数写在这里，模板只渲染。

/**
 * 搜索页数据：按搜索类型查商品 / 文章。
 *
 * @param array<string, mixed> $q 请求参数（module.php 解析好的 $search_q）
 * @return array<string, mixed>
 */
function template_search_data(array $q): array
{
    $keyword = (string) ($q['keyword'] ?? '');
    $type    = (string) ($q['type'] ?? 'all');

    $goodsResults   = [];
    $articleResults = [];

    // 关键词为空时是「搜索页」（只显示搜索框），不查库
    if ($keyword !== '') {
        $fs = new FrontDataService();
        // type=all 两边都查；goods / article 只查一边
        if ($type !== 'article') {
            $goodsResults = $fs->queryGoodsList(['keyword' => $keyword], 12);
            // 插件扩展点：沿用改造前的 index_goods_list(list, ctx) 契约
            $goodsResults = applyFilter('index_goods_list', $goodsResults, 'search');
        }
        if ($type !== 'goods') {
            $articleResults = $fs->queryArticleList(['keyword' => $keyword], 6);
        }
    }

    return [
        'keyword'         => $keyword,
        'search_type'     => $type,
        'results'         => $goodsResults,
        'article_results' => $articleResults,
        'result_count'    => count($goodsResults) + count($articleResults),
    ];
}

// ============================================================
// 12. 领券中心数据方法（模板里直接调用）
// ============================================================
// 与第 8 / 10 / 11 节同理：取数写在这里，模板只渲染。

/**
 * 领券中心数据：可领券列表 + 当前登录态 + 已领取的券 id。
 *
 * @return array<string, mixed>
 */
function template_coupon_data(): array
{
    // 可领取的券：启用中 + 前台展示 + 在有效期内 + 未领完（条件在 CouponModel 里）
    $coupons = (new CouponModel())->getPubliclyClaimable(100);

    // 登录态：已登录才需要查「哪些券领过」，用于按钮显示「已领取」；
    // 会话由 init.php 统一启动，这里只读不启
    $userId = (int) ($_SESSION['em_front_user']['id'] ?? 0);

    $claimedIds = [];
    if ($userId > 0) {
        $rows = Database::query(
            'SELECT `coupon_id` FROM `' . Database::prefix() . 'user_coupon` WHERE `user_id` = ?',
            [$userId]
        );
        foreach ($rows as $row) {
            $claimedIds[] = (int) $row['coupon_id'];
        }
    }

    return [
        'coupons'      => $coupons,
        'is_logged_in' => $userId > 0,
        'claimed_ids'  => $claimedIds,
    ];
}
