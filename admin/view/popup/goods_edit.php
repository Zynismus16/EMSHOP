<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
$csrfToken = Csrf::token();
$isEdit = isset($goods) && !empty($goods);
$placeholderImg = EM_CONFIG['placeholder_img'] ?? '/content/static/img/placeholder.png';
$coverImages = $isEdit && !empty($goods['cover_images']) ? json_decode($goods['cover_images'], true) : [];
$pageTitle = $isEdit ? '编辑商品' : '添加商品';

$esc = function (?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
};

// 解析 configs JSON（附加选项、营销配置）
$goodsConfigs = [];
if ($isEdit && !empty($goods['configs'])) {
    $goodsConfigs = json_decode($goods['configs'], true) ?: [];
}
$extraFields = $goodsConfigs['extra_fields'] ?? [];
// 返佣：未设置时保持 null（表单显示空），不要默认成 0 —— 0 也是合法值，会被理解为"不返佣"而非"未设置"
$rebateConfig = $goodsConfigs['rebate'] ?? null;
// 显示用：DB 存万分位（500 = 5%），前端展示百分比（/100）；null 保持 null
$rebateL1Pct = ($rebateConfig !== null && isset($rebateConfig['l1'])) ? $rebateConfig['l1'] / 100 : null;
$rebateL2Pct = ($rebateConfig !== null && isset($rebateConfig['l2'])) ? $rebateConfig['l2'] / 100 : null;
$rebateMode  = ($rebateConfig !== null && !empty($rebateConfig['mode'])) ? (string) $rebateConfig['mode'] : '';

include __DIR__ . '/header.php';
?>

<div class="popup-inner">
    <form class="layui-form" id="goodsForm" lay-filter="goodsForm">
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="csrf_token" value="<?php echo $esc($csrfToken); ?>">
        <input type="hidden" name="id" value="<?php echo $isEdit ? $esc($goods['id']) : ''; ?>">
        <input type="hidden" name="cover_images" id="coverImagesInput" value='<?php echo $esc($isEdit ? $goods['cover_images'] : '[]'); ?>'>

        <!-- 选项卡（em-tabs，切换时联动下方 layui-tab-content 里的面板） -->
        <div class="em-tabs em-tabs--scroll" id="goodsEditTabs" style="margin-bottom: 0;">
            <a class="em-tabs__item is-active"><i class="fa fa-cog"></i>基础设置</a>
            <a class="em-tabs__item"><i class="fa fa-image"></i>图片/规格</a>
            <a class="em-tabs__item"><i class="fa fa-file-text-o"></i>详细内容</a>
            <a class="em-tabs__item"><i class="fa fa-list-alt"></i>附加选项</a>
            <a class="em-tabs__item"><i class="fa fa-tags"></i>营销配置</a>
            <a class="em-tabs__item"><i class="fa fa-search"></i>SEO 配置</a>
            <a class="em-tabs__item"><i class="fa fa-sliders"></i>其他设置</a>
        </div>
        <div class="layui-tab-content goods-edit-content">

                <!-- ========== Tab 1: 基础设置 ========== -->
                <div class="layui-tab-item layui-show">
                    <div class="popup-section">
                    <div class="layui-form-item">
                        <label class="layui-form-label">商品类型</label>
                        <div class="layui-input-block">
                            <div class="goods-type-group" style="display:flex;align-items:center;border:1px solid #e6e6e6;border-radius:4px;">
                                <div class="goods-type-group__select" style="flex:1;min-width:0;">
                                    <select name="goods_type" id="goodsTypeSelect" lay-filter="goodsType">
                                        <?php if (!$isEdit): ?>
                                            <option value="">请选择商品类型</option>
                                        <?php endif; ?>
                                        <?php foreach ($goodsTypes as $type => $config): ?>
                                            <option value="<?php echo $esc($type); ?>" <?php echo $isEdit && $goods['goods_type'] == $type ? 'selected' : ''; ?>><?php echo $esc($config['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="button" class="goods-type-group__btn" id="editPluginConfigBtn" style="flex-shrink:0;padding:0 14px;height:38px;border:none;border-left:1px solid #d2d2d2;background:#1e9fff;color:#fff;font-size:13px;cursor:pointer;white-space:nowrap;border-radius:0 3px 3px 0;">
                                    <i class="fa fa-cog"></i> 类型配置
                                </button>
                            </div>
                        </div>
                        <div class="layui-form-mid"> 商品类型已全面插件化，如当前没有您所需的类型，可前往插件管理处启用更多商品类型插件</div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">商品分类</label>
                        <div class="layui-input-block">
                            <select name="category_id" lay-verify="required" lay-search>
                                <option value="">请选择商品分类</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>" <?php echo $isEdit && $goods['category_id'] == $cat['id'] ? 'selected' : ''; ?>><?php echo str_repeat('—', $cat['parent_id'] ? 1 : 0) . $esc($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">商品标题</label>
                        <div class="layui-input-block">
                            <input type="text" name="title" lay-verify="required" placeholder="请输入商品标题" class="layui-input" value="<?php echo $isEdit ? $esc($goods['title']) : ''; ?>">
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">商品编码</label>
                        <div class="layui-input-block">
                            <input type="text" name="code" placeholder="留空自动生成" class="layui-input" value="<?php echo $isEdit ? $esc($goods['code']) : ''; ?>">
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">商品单位</label>
                        <div class="layui-input-block">
                            <input type="text" name="unit" class="layui-input" maxlength="10"
                                   placeholder="如：件 / 个 / 张 / 份 / 套 / 台 / 本 / 瓶 / 箱 / 次（留空默认"个"）"
                                   value="<?php echo $isEdit ? $esc((string) ($goods['unit'] ?? '')) : ''; ?>">
                        </div>
                    </div>
                    <!-- 商品标签 -->
                    <div class="layui-form-item">
                        <label class="layui-form-label">商品标签</label>
                        <div class="layui-input-block">
                            <div class="goods-tag-input-wrap" id="goodsTagInputWrap">
                                <div class="goods-tag-tokens" id="goodsTagTokens">
                                    <?php
                                    $goodsTags = [];
                                    if ($isEdit) {
                                        $goodsTags = GoodsTagModel::getTagsByGoodsId((int) $goods['id']);
                                        foreach ($goodsTags as $t):
                                    ?>
                                    <span class="goods-tag-token" data-id="<?php echo (int) $t['id']; ?>">
                                        <?php echo $esc($t['name']); ?>
                                        <i class="fa fa-times goods-tag-remove"></i>
                                    </span>
                                    <?php endforeach; } ?>
                                    <input type="text" class="goods-tag-text-input" id="goodsTagTextInput" placeholder="输入标签名，回车添加" autocomplete="off">
                                </div>
                                <div class="goods-tag-suggest" id="goodsTagSuggest" style="display:none;"></div>
                            </div>
                            <input type="hidden" name="goods_tags" id="goodsTagHiddenInput" value="<?php echo $esc(implode(',', array_column($goodsTags, 'name'))); ?>">
                            <div class="layui-form-mid layui-word-aux">输入标签名后按回车添加，支持多个标签</div>
                        </div>
                    </div>
                    </div>
                </div>

                <!-- ========== Tab 2: 图片/规格 ========== -->
                <div class="layui-tab-item">
                    <!-- 商品图片 -->
                    <div class="popup-section">
                        <div class="layui-form-item">
                            <div class="layui-input-block" style="margin-left:0;">
                                <div class="admin-img-field" id="goodsImgField" data-placeholder="<?php echo $esc($placeholderImg); ?>">
                                    <!-- 多图上传：此处永远展示占位图；实际已上传的图在下方 imagePreviewList 里展示与排序 -->
                                    <img src="<?php echo $esc($placeholderImg); ?>" alt="" id="goodsImgPreview">
                                    <input type="text" class="layui-input admin-img-url" id="goodsImgUrl" maxlength="500"
                                           placeholder="输入图片URL，点击添加按钮或按回车" value="">
                                    <div class="admin-img-btns">
                                        <button type="button" class="layui-btn layui-btn-xs layui-btn-normal" id="goodsImgAddBtn" title="添加"><i class="fa fa-plus"></i></button>
                                        <button type="button" class="layui-btn layui-btn-xs" id="goodsImgUploadBtn" title="上传"><i class="fa fa-upload"></i></button>
                                        <button type="button" class="layui-btn layui-btn-xs layui-btn-normal" id="goodsImgPickBtn" title="选择"><i class="fa fa-image"></i></button>
                                        <button type="button" class="layui-btn layui-btn-xs layui-btn-danger" id="goodsImgClearBtn" title="清除"><i class="fa fa-times"></i></button>
                                    </div>
                                </div>
                                <div class="layui-form-mid" style="color:#909399;">支持多图上传，拖拽排序调整顺序，第一张为封面图</div>
                                <div class="image-preview-list<?php echo !empty($coverImages) ? ' has-images' : ''; ?>" id="imagePreviewList">
                                    <?php foreach ($coverImages as $img): ?>
                                        <div class="image-preview-item" data-url="<?php echo $esc($img); ?>">
                                            <img src="<?php echo $esc($img); ?>" class="img-clickable">
                                            <span class="remove-btn" onclick="removeImage(this)">×</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- 规格设置 -->
                    <div class="popup-section">
                        <div class="layui-form-item">
                            <div class="layui-input-block" style="margin-left:0;">
                                <div class="spec-table-wrap">
                                <table class="layui-table spec-table" id="specTable">
                                    <colgroup>
                                        <col width="30">
                                        <col width="300">
                                        <col width="120">
                                        <col width="120">
                                        <col width="150">
                                        <col width="90">
                                        <col width="90">
                                        <col width="70">
                                        <col width="70">
                                        <col width="88">
                                        <col width="60">
                                        <col width="140">
                                    </colgroup>
                                    <thead>
                                        <tr>
                                            <th></th>
                                            <th class="spec-th">
                                                <?php
                                                // 编辑时回显维度名称（如"颜色/款式"）
                                                $dimNameStr = '';
                                                if (!empty($specDimNames)) {
                                                    $dimNameStr = implode('/', array_column($specDimNames, 'name'));
                                                }
                                                ?>
                                                <input type="text" style="font-weight: normal;" class="layui-input" id="specNameInput" name="spec_dim_name" placeholder="规格类型，多维用/分隔，如：颜色/款式" value="<?php echo $esc($dimNameStr); ?>">
                                            </th>
                                            <th>价格</th>
                                            <th>成本价</th>
                                            <th>市场价</th>
                                            <th>规格编号</th>
                                            <th>标签</th>
                                            <th>最小购买</th>
                                            <th>最大购买</th>
                                            <th title="前台展示用；订单完成后会在当前值上累加">销量</th>
                                            <th>默认选中</th>
                                            <th class="spec-col-actions">操作</th>
                                        </tr>
                                    </thead>
                                    <tbody id="specList">
                                        <?php if ($isEdit && !empty($specs)): ?>
                                            <?php foreach ($specs as $index => $spec): ?>
                                                <?php
                                                // 规格级 configs：images 从 spec.configs JSON 读；
                                                // level_prices / user_prices 从 em_goods_price_level / em_goods_price_user 预取
                                                $specConfigsRaw = $spec['configs'] ?? '';
                                                $specConfigsArr = [];
                                                if (is_string($specConfigsRaw) && $specConfigsRaw !== '') {
                                                    $specConfigsArr = json_decode($specConfigsRaw, true) ?: [];
                                                }
                                                $specIdInt = (int) $spec['id'];
                                                if (!empty($specLevelPricesBySpec[$specIdInt])) {
                                                    $specConfigsArr['level_prices'] = $specLevelPricesBySpec[$specIdInt];
                                                }
                                                if (!empty($specUserPricesBySpec[$specIdInt])) {
                                                    // 前端结构：{user_id: price}；额外挂一个 user_labels 让 UI 展示用户名/昵称
                                                    $flatPrices = [];
                                                    $labels = [];
                                                    foreach ($specUserPricesBySpec[$specIdInt] as $uid => $info) {
                                                        $flatPrices[(string) $uid] = $info['price'];
                                                        $labels[(string) $uid] = $info['label'];
                                                    }
                                                    $specConfigsArr['user_prices'] = $flatPrices;
                                                    $specConfigsArr['user_labels'] = $labels;
                                                }
                                                // 关键：强制序列化为 JSON 对象 "{}" 而不是空数组 "[]"
                                                // 否则 JS JSON.parse 出来是数组，挂 .images 等属性会被 stringify 丢弃
                                                $specConfigsJson = json_encode((object) $specConfigsArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                                ?>
                                                <tr class="spec-row" data-spec-id="<?php echo $spec['id']; ?>">
                                                    <td class="drag-handle"><input type="hidden" name="specs[<?php echo $index; ?>][id]" value="<?php echo $spec['id']; ?>"><i class="fa fa-bars"></i></td>
                                                    <td class="spec-td"><input type="text" name="specs[<?php echo $index; ?>][name]" class="layui-input" value="<?php echo $esc($spec['name']); ?>" placeholder="规格值"></td>
                                                    <td><input type="number" step="0.01" name="specs[<?php echo $index; ?>][price]" class="layui-input" value="<?php echo $spec['price']; ?>"></td>
                                                    <td><input type="number" step="0.01" name="specs[<?php echo $index; ?>][cost_price]" class="layui-input" value="<?php echo $spec['cost_price'] ?? ''; ?>" placeholder=""></td>
                                                    <td><input type="number" step="0.01" name="specs[<?php echo $index; ?>][market_price]" class="layui-input" value="<?php echo $spec['market_price'] ?? ''; ?>" placeholder=""></td>
                                                    <td><input type="text" name="specs[<?php echo $index; ?>][spec_no]" class="layui-input" value="<?php echo $esc($spec['spec_no']); ?>"></td>
                                                    <td><input type="text" name="specs[<?php echo $index; ?>][tags]" class="layui-input" value="<?php $tagsRaw=$spec["tags"]??"";$tagsDecoded=is_string($tagsRaw)?json_decode($tagsRaw,true):$tagsRaw;echo $esc(is_array($tagsDecoded)?implode(",",$tagsDecoded):$tagsRaw);?>" placeholder=""></td>
                                                    <td><input type="number" name="specs[<?php echo $index; ?>][min_buy]" class="layui-input" value="<?php echo $spec["min_buy"] ?? ""; ?>"></td>
                                                    <td><input type="number" name="specs[<?php echo $index; ?>][max_buy]" class="layui-input" value="<?php echo $spec["max_buy"] ?? ""; ?>" placeholder=""></td>
                                                    <td><input type="number" step="1" min="0" name="specs[<?php echo $index; ?>][sold_count]" class="layui-input" value="<?php echo (int) ($spec['sold_count'] ?? 0); ?>" placeholder="0" title="前台展示销量"></td>
                                                    <td><input type="radio" name="specs[is_default]" lay-skin="primary" value="<?php echo $index; ?>" <?php echo $spec['is_default'] ? 'checked' : ''; ?>></td>
                                                    <td class="spec-col-actions">
                                                        <!-- 隐藏字段承载 configs JSON（核心认 images/level_prices/user_prices）；插件的数据走 goods_spec_saved 钩子自己写，不进这里 -->
                                                        <input type="hidden" class="spec-configs" name="specs[<?php echo $index; ?>][configs]" value='<?php echo $esc($specConfigsJson); ?>'>
                                                        <div class="spec-actions">
                                                            <button type="button" class="spec-action-btn" data-action="levelPrice" title="用户等级专属价"><i class="fa fa-id-badge"></i></button>
                                                            <button type="button" class="spec-action-btn" data-action="userPrice" title="用户专属价"><i class="fa fa-user"></i></button>
                                                            <button type="button" class="spec-action-btn" data-action="images" title="规格专属图片"><i class="fa fa-image"></i></button>
                                                            <button type="button" class="spec-action-btn spec-action-btn--danger" onclick="removeSpec(this)" title="删除"><i class="fa fa-trash"></i></button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                                </div>
                                <button type="button" class="layui-btn layui-btn-sm" id="addSpecBtn"><i class="fa fa-plus"></i> 添加规格</button>
                                <button type="button" class="layui-btn layui-btn-sm layui-btn-normal" id="specHelpBtn"><i class="fa fa-info"></i> 查看设置教程</button>
                                <span class="layui-word-aux" style="margin-left:8px;">销量为前台展示值，可手动调高；用户下单完成后会在该值上累加真实销量。</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ========== Tab 3: 详细内容 ========== --> 
                <div class="layui-tab-item">
                    <div class="popup-section">
                    <div class="layui-form-item">
                        <label class="layui-form-label">商品简介</label>
                        <div class="layui-input-block">
                            <textarea name="intro" id="intro-textarea" placeholder="简短描述" class="layui-textarea"><?php echo $isEdit ? $esc($goods['intro']) : ''; ?></textarea>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">商品详情</label>
                        <div class="layui-input-block">
                            <textarea name="content" id="editor-2" placeholder="商品详情内容" class="layui-textarea"><?php echo $isEdit ? $esc($goods['content']) : ''; ?></textarea>
                        </div>
                    </div>
                    <div class="layui-form-item" style="margin-bottom:0;">
                        <label class="layui-form-label">使用教程</label>
                        <div class="layui-input-block">
                            <textarea name="guide" id="editor-guide" placeholder="使用教程内容" class="layui-textarea"><?php echo $isEdit ? $esc($goods['guide'] ?? '') : ''; ?></textarea>
                        </div>
                    </div>
                    </div>
                </div>

                <!-- ========== Tab 4: 附加选项 ========== -->
                <div class="layui-tab-item">
                    <div class="popup-section">
                    <blockquote class="layui-elem-quote" style="margin-bottom:15px;">
                        配置下单时需要用户填写的自定义表单字段，如 QQ、性别、手机号等。字段名称和字段标识为必填项。
                    </blockquote>
                    <div class="spec-table-wrap">
                    <table class="layui-table extra-fields-table" id="extraFieldsTable">
                        <colgroup>
                            <col width="30">
                            <col width="160">
                            <col width="140">
                            <col width="160">
                            <col width="130">
                            <col width="80">
                            <col width="60">
                        </colgroup>
                        <thead>
                            <tr>
                                <th></th>
                                <th>字段名称</th>
                                <th>字段标识</th>
                                <th>占位提示</th>
                                <th>格式验证</th>
                                <th>必填</th>
                                <th class="extra-col-actions">操作</th>
                            </tr>
                        </thead>
                        <tbody id="extraFieldsList">
                            <?php if (!empty($extraFields)): ?>
                                <?php foreach ($extraFields as $efIdx => $ef): ?>
                                    <tr class="extra-field-row">
                                        <td class="drag-handle"><i class="fa fa-bars"></i></td>
                                        <td><input type="text" name="extra_fields[<?php echo $efIdx; ?>][title]" class="layui-input" value="<?php echo $esc($ef['title']); ?>" placeholder="如：QQ号"></td>
                                        <td><input type="text" name="extra_fields[<?php echo $efIdx; ?>][name]" class="layui-input" value="<?php echo $esc($ef['name']); ?>" placeholder="如：qq"></td>
                                        <td><input type="text" name="extra_fields[<?php echo $efIdx; ?>][placeholder]" class="layui-input" value="<?php echo $esc($ef['placeholder'] ?? ''); ?>" placeholder="如：请输入QQ号"></td>
                                        <td>
                                            <select name="extra_fields[<?php echo $efIdx; ?>][format]">
                                                <option value="text" <?php echo ($ef['format'] ?? 'text') === 'text' ? 'selected' : ''; ?>>文本</option>
                                                <option value="number" <?php echo ($ef['format'] ?? '') === 'number' ? 'selected' : ''; ?>>纯数字</option>
                                                <option value="phone" <?php echo ($ef['format'] ?? '') === 'phone' ? 'selected' : ''; ?>>手机号</option>
                                                <option value="email" <?php echo ($ef['format'] ?? '') === 'email' ? 'selected' : ''; ?>>邮箱</option>
                                            </select>
                                        </td>
                                        <td style="text-align:center;"><input type="checkbox" name="extra_fields[<?php echo $efIdx; ?>][required]" value="1" lay-skin="switch" lay-text="是|否" <?php echo !empty($ef['required']) ? 'checked' : ''; ?>></td>
                                        <td class="extra-col-actions" style="text-align:center;"><button type="button" class="layui-btn layui-btn-danger layui-btn-xs" onclick="$(this).closest('tr').remove()"><i class="fa fa-trash"></i></button></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                    <button type="button" class="layui-btn layui-btn-sm" id="addExtraFieldBtn"><i class="fa fa-plus"></i> 添加字段</button>
                    </div>
                </div>

                <!-- ========== Tab 5: 营销配置 ========== -->
                <div class="layui-tab-item">
                    <div class="popup-section">
                        <div class="layui-form-item">
                            <label class="layui-form-label">推荐商品</label>
                            <div class="layui-input-block">
                                <?php // 新建默认开启；编辑时按数据库值回显 ?>
                                <input type="checkbox" name="is_recommended" lay-skin="switch" lay-text="是|否" value="1" <?php echo ($isEdit ? !empty($goods['is_recommended']) : true) ? 'checked' : ''; ?>>
                            </div>
                        </div>
                    </div>

                    <div class="popup-section">
                        <blockquote class="layui-elem-quote" style="margin-bottom:15px;">
                            本商品 2 级返佣比例（百分比，如 5 = 5%）。<br>
                            留空 → 使用分类级 → 全局级回退；填 0 → 本商品明确不返佣。
                        </blockquote>
                        <div class="layui-form-item">
                            <label class="layui-form-label">计算方式</label>
                            <div class="layui-input-block">
                                <input type="radio" name="rebate_mode" value="amount" title="按订单金额" <?php echo $rebateMode === 'amount' ? 'checked' : ''; ?>>
                                <input type="radio" name="rebate_mode" value="profit" title="按订单利润" <?php echo $rebateMode === 'profit' ? 'checked' : ''; ?>>
                                <input type="radio" name="rebate_mode" value="" title="跟随系统" <?php echo $rebateMode === '' ? 'checked' : ''; ?>>
                            </div>
                            <div class="layui-form-mid layui-word-aux">"跟随系统"则使用全局配置；"按订单利润"需要商品填了成本价才会计算</div>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">一级返佣</label>
                            <div class="layui-input-inline" style="width:180px;">
                                <div class="layui-input-wrap">
                                    <input type="number" name="rebate_l1" class="layui-input" min="0" max="100" step="0.01"
                                           placeholder="留空=未设置"
                                           value="<?php echo $rebateL1Pct !== null ? htmlspecialchars((string) $rebateL1Pct) : ''; ?>">
                                    <div class="layui-input-suffix"><i class="fa fa-percent"></i></div>
                                </div>
                            </div>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">二级返佣</label>
                            <div class="layui-input-inline" style="width:180px;">
                                <div class="layui-input-wrap">
                                    <input type="number" name="rebate_l2" class="layui-input" min="0" max="100" step="0.01"
                                           placeholder="留空=未设置"
                                           value="<?php echo $rebateL2Pct !== null ? htmlspecialchars((string) $rebateL2Pct) : ''; ?>">
                                    <div class="layui-input-suffix"><i class="fa fa-percent"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ========== Tab 6: SEO 配置 ========== -->
                <div class="layui-tab-item">
                    <div class="popup-section">
                        <?php
                        // SEO 数据存在 goods.configs.seo 里，不新增列；只在前端拆字段回显
                        $seoCfg = $goodsConfigs['seo'] ?? [];
                        $seoTitle   = (string) ($seoCfg['title']       ?? '');
                        $seoKeys    = (string) ($seoCfg['keywords']    ?? '');
                        $seoDesc    = (string) ($seoCfg['description'] ?? '');
                        ?>
                        <blockquote class="layui-elem-quote" style="margin-bottom:15px;">
                            设置本商品在浏览器标签栏 / 搜索引擎结果里的展示。留空则使用商品标题与简介作为默认值。
                        </blockquote>
                        <div class="layui-form-item">
                            <label class="layui-form-label">标题 title</label>
                            <div class="layui-input-block">
                                <input type="text" name="seo_title" maxlength="200" placeholder="留空则使用商品标题"
                                       class="layui-input" value="<?php echo $esc($seoTitle); ?>">
                            </div>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">关键词 keywords</label>
                            <div class="layui-input-block">
                                <input type="text" name="seo_keywords" maxlength="200" placeholder="多个关键词用英文逗号分隔"
                                       class="layui-input" value="<?php echo $esc($seoKeys); ?>">
                            </div>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">描述 description</label>
                            <div class="layui-input-block">
                                <textarea name="seo_description" maxlength="500" rows="3" placeholder="留空则使用商品简介"
                                          class="layui-textarea"><?php echo $esc($seoDesc); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ========== Tab 7: 其他设置 ========== -->
                <div class="layui-tab-item">
                    <div class="popup-section">
                    <div class="layui-form-item">
                        <label class="layui-form-label">排序值</label>
                        <div class="layui-input-block">
                            <input type="number" name="sort" class="layui-input" value="<?php echo $isEdit ? $goods['sort'] : 0; ?>">
                        </div>
                        <div class="layui-form-mid">数字越小越靠前</div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">首页置顶</label>
                        <div class="layui-input-block">
                            <input type="checkbox" name="is_top_home" lay-skin="switch" lay-text="是|否" value="1" <?php echo $isEdit && $goods['is_top_home'] ? 'checked' : ''; ?>>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">分类置顶</label>
                        <div class="layui-input-block">
                            <input type="checkbox" name="is_top_category" lay-skin="switch" lay-text="是|否" value="1" <?php echo $isEdit && $goods['is_top_category'] ? 'checked' : ''; ?>>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">API对接</label>
                        <div class="layui-input-block">
                            <input type="checkbox" name="api_enabled" lay-skin="switch" lay-text="开启|关闭" value="1" <?php echo (!$isEdit || $goods['api_enabled']) ? 'checked' : ''; ?>>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">跳转链接</label>
                        <div class="layui-input-block">
                            <input type="text" name="jump_url" class="layui-input" value="<?php echo $isEdit ? $esc($goods['jump_url']) : ''; ?>">
                        </div>
                        <div class="layui-form-mid">非空时点击商品跳转至外部链接</div>
                    </div>
                    <?php doAction('admin_goods_edit_other_settings', $goods ?? null, $isEdit); ?>
                    </div>
                </div>

        </div>

        <!-- 类型配置（隐藏区域，存放插件表单字段供主表单提交） -->
        <div id="pluginFormContainer" style="display:none;">
            <?php if ($isEdit && isset($goods['goods_type']) && !empty($goods['goods_type'])): ?>
                <?php doAction("goods_type_{$goods['goods_type']}_create_form", $goods); ?>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="popup-footer">
    <button type="button" class="popup-btn" id="goodsCancelBtn"><i class="fa fa-times"></i> 取消</button>
    <button type="button" class="em-btn em-save-btn" id="goodsSubmitBtn"><i class="fa fa-check"></i> 确认保存</button>
</div>

<!-- =================== 规格行配置弹窗（3 个，layer.open 时使用 $('#specLevelPriceModal') 内容） =================== -->

<!-- ① 用户等级专属价 -->
<div class="spec-modal" id="specLevelPriceModal">
    <div class="spec-modal__tip">为下列用户等级设置本规格的专属价（单位：元）。留空 = 使用规格原价。</div>
    <table class="spec-modal__table">
        <thead>
            <tr>
                <th style="width:40%;">等级</th>
                <th style="width:35%;">专属价</th>
                <th style="width:25%;">说明</th>
            </tr>
        </thead>
        <tbody id="specLevelPriceList">
            <?php if (!empty($userLevels)): foreach ($userLevels as $lvl): ?>
            <tr>
                <td><?= $esc((string) $lvl['name']) ?> <span style="color:#9ca3af;font-size:11px;">LV<?= (int) ($lvl['level'] ?? 0) ?></span></td>
                <td><input type="number" step="0.01" min="0" class="spec-level-price" data-level-id="<?= (int) $lvl['id'] ?>" placeholder="留空=使用原价"></td>
                <td style="color:#9ca3af;font-size:12px;">折扣 <?= $esc((string) ($lvl['discount'] ?? '')) ?> 折</td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="3" style="color:#9ca3af;text-align:center;">尚未配置用户等级，请先在 用户管理 → 用户等级 创建</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ② 用户专属价（带搜索） -->
<div class="spec-modal" id="specUserPriceModal">
    <div class="spec-modal__tip">给指定用户设置本规格的专属价。在上方搜索用户（用户名 / 昵称 / 邮箱 / ID），点击匹配项加入下方列表。</div>
    <div class="spec-user-search">
        <i class="fa fa-search spec-user-search__ico"></i>
        <input type="text" id="specUserSearchInput" placeholder="输入用户名、昵称、邮箱或用户 ID 搜索..." autocomplete="off">
        <div class="spec-user-search__dropdown" id="specUserSearchDropdown"></div>
    </div>
    <table class="spec-modal__table" style="margin-top:10px;">
        <thead>
            <tr>
                <th style="width:55%;">用户</th>
                <th style="width:30%;">专属价（元）</th>
                <th style="width:15%;"></th>
            </tr>
        </thead>
        <tbody id="specUserPriceList"></tbody>
    </table>
</div>

<!-- ③ 规格专属图片（多图 + 拖拽排序，复用商品图片样式） -->
<div class="spec-modal" id="specImagesModal">
    <div class="spec-modal__tip">为本规格设置专属图片。支持多图、拖拽排序。</div>
    <div class="admin-img-field" id="specImagesField" data-placeholder="<?php echo $esc($placeholderImg); ?>">
        <img src="<?php echo $esc($placeholderImg); ?>" alt="" id="specImagesPreview">
        <input type="text" class="layui-input admin-img-url" id="specImagesUrl" maxlength="500" placeholder="输入图片 URL 后点 + 或按回车">
        <div class="admin-img-btns">
            <button type="button" class="layui-btn layui-btn-xs layui-btn-normal" id="specImagesAddBtn" title="添加"><i class="fa fa-plus"></i></button>
            <button type="button" class="layui-btn layui-btn-xs" id="specImagesUploadBtn" title="上传"><i class="fa fa-upload"></i></button>
            <button type="button" class="layui-btn layui-btn-xs layui-btn-normal" id="specImagesPickBtn" title="选择"><i class="fa fa-image"></i></button>
            <button type="button" class="layui-btn layui-btn-xs layui-btn-danger" id="specImagesClearBtn" title="清空 URL 输入"><i class="fa fa-times"></i></button>
        </div>
    </div>
    <div class="image-preview-list" id="specImagesPreviewList"></div>
</div>

<style>
/* 选项卡面板：沿用 layui-tab-item / layui-show 做显隐，去掉默认 padding */
.goods-edit-content > .layui-tab-item { padding: 0; }

/* 规格行操作列：4 个紧凑图标按钮一行展示 */
.spec-actions {
    display: inline-flex; align-items: center; gap: 4px;
    flex-wrap: nowrap;
}
.spec-action-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 26px; height: 26px;
    padding: 0; border: 1px solid #e5e7eb; border-radius: 5px;
    background: #fff; color: #6b7280;
    cursor: pointer; font-size: 12px;
    transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
}
.spec-action-btn:hover { background: #eef2ff; color: #4f46e5; border-color: #c7d2fe; }
.spec-action-btn--danger:hover { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
/* 有值徽记：右上角小蓝点（通过 spec-action-btn.has-value 控制） */
.spec-action-btn.has-value { position: relative; }
.spec-action-btn.has-value::after {
    content: ''; position: absolute; top: 2px; right: 2px;
    width: 6px; height: 6px; border-radius: 50%;
    background: #4f46e5;
}

/* 三个规格级配置弹窗（放在 body 底部，layer.open 时移出） */
.spec-modal { display: none; padding: 16px 18px; }
.spec-modal__tip { color: #6b7280; font-size: 12.5px; margin-bottom: 12px; }
.spec-modal__table { width: 100%; border-collapse: collapse; }
.spec-modal__table th,
.spec-modal__table td { padding: 8px 10px; border-bottom: 1px solid #f3f4f6; text-align: left; font-size: 13px; }
.spec-modal__table th { color: #6b7280; font-weight: 500; background: #f9fafb; }
.spec-modal__table input[type="text"],
.spec-modal__table input[type="number"] {
    width: 100%; height: 30px; padding: 0 10px;
    border: 1px solid #e5e7eb; border-radius: 5px;
    font-size: 13px;
}
.spec-modal__table input:focus { border-color: #4f46e5; outline: none; }
.spec-modal__row-del {
    border: none; background: transparent; color: #9ca3af; cursor: pointer;
    font-size: 13px; padding: 4px 8px;
}
.spec-modal__row-del:hover { color: #ef4444; }
.spec-modal__add {
    margin-top: 10px;
    display: inline-flex; align-items: center; gap: 4px;
    padding: 6px 14px;
    border: 1px dashed #d1d5db; border-radius: 6px;
    background: #fff; color: #4f46e5;
    cursor: pointer; font-size: 12.5px;
}
.spec-modal__add:hover { background: #eef2ff; border-color: #c7d2fe; }

/* 规格图片弹窗：复用商品图片样式（admin-img-field + image-preview-list）
   但跳过上面 .image-preview-list { display: none } 的规则——规格弹窗里的列表始终显示 */
#specImagesModal .image-preview-list { margin-top: 10px; display: flex !important; }

/* 用户专属价弹窗的搜索输入 + 下拉 */
.spec-user-search { position: relative; }
.spec-user-search__ico {
    position: absolute; left: 10px; top: 50%;
    transform: translateY(-50%);
    color: #9ca3af; font-size: 12px; pointer-events: none;
}
.spec-user-search > input {
    width: 100%; height: 34px;
    padding: 0 12px 0 30px;
    border: 1px solid #e5e7eb; border-radius: 6px;
    background: #fff; color: #1f2937;
    font-size: 13px;
}
.spec-user-search > input:focus {
    border-color: #4f46e5; outline: none;
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
}
.spec-user-search__dropdown {
    display: none; position: absolute; top: 100%; left: 0; right: 0;
    margin-top: 4px; z-index: 10;
    background: #fff; border: 1px solid #e5e7eb; border-radius: 6px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    max-height: 280px; overflow-y: auto;
}
.spec-user-search__dropdown.is-open { display: block; }
.spec-user-search__item {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 12px; cursor: pointer;
    border-bottom: 1px solid #f3f4f6;
    transition: background 0.12s ease;
}
.spec-user-search__item:last-child { border-bottom: none; }
.spec-user-search__item:hover { background: #eef2ff; }
.spec-user-search__item img,
.spec-user-search__item .spec-user-search__avatar {
    width: 28px; height: 28px; border-radius: 50%;
    background: #e5e7eb; color: #6b7280;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 600;
    flex-shrink: 0;
}
.spec-user-search__meta { flex: 1; min-width: 0; }
.spec-user-search__name {
    font-size: 13px; color: #1f2937; font-weight: 500;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.spec-user-search__sub {
    font-size: 11.5px; color: #9ca3af;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.spec-user-search__empty {
    padding: 12px; text-align: center; color: #9ca3af; font-size: 12.5px;
}
.spec-user-search__added {
    color: #10b981; font-size: 11px; font-weight: 500;
}

/* 分组标题 */
/* 商品类型选择 + 类型配置按钮组合 */
.goods-type-group {
    display: flex;
    align-items: center;
    border: 1px solid #e6e6e6;
    border-radius: 4px;
}
.goods-type-group__select {
    flex: 1;
    min-width: 0;
}
.goods-type-group .layui-form-select .layui-input {
    border: none !important;
    box-shadow: none !important;
}
.goods-type-group__btn {
    flex-shrink: 0;
    padding: 0 14px;
    height: 38px;
    border: none;
    border-left: 1px solid #d2d2d2;
    background: #1e9fff;
    color: #fff;
    font-size: 13px;
    cursor: pointer;
    white-space: nowrap;
    transition: background .2s;
}
.goods-type-group__btn:hover {
    background: #0c8ce6;
}
.goods-type-group__btn .fa {
    margin-right: 3px;
    color: #fff;
}

/* 图片预览列表间距 & 无图时隐藏 */
.image-preview-list { margin-top: 8px; display: none; }
div.image-preview-list.has-images { display: block; }

/* 图片可点击放大 */
.img-clickable { cursor: pointer; transition: opacity 0.2s; }
.img-clickable:hover { opacity: 0.8; }

/* 规格/附加选项表格通用样式 */
.spec-table, .extra-fields-table { margin-bottom: 10px; }
.extra-fields-table { min-width: 760px; }
.spec-table th, .spec-table td,
.extra-fields-table th, .extra-fields-table td {
    text-align: center; padding: 5px 8px !important; min-width: 70px;
}
.spec-td, .spec-th{
    min-width: 123px!important;
    text-align: center!important;
}
/* 拖拽列（第一列）覆盖 min-width */
.spec-table th:first-child, .spec-table td:first-child,
.extra-fields-table th:first-child, .extra-fields-table td:first-child {
    min-width: 30px !important; width: 30px; padding: 0 !important;
}
/* 第二列左对齐（原第一列内容列） */
.spec-table th:nth-child(2), .spec-table td:nth-child(2),
.extra-fields-table th:nth-child(2), .extra-fields-table td:nth-child(2) {
    text-align: left;
}
.extra-fields-table td:last-child {
    text-align: center !important;
}
.spec-table td.spec-col-actions {
    text-align: center !important;
}
.spec-table .layui-input:not([type="radio"]):not([type="checkbox"]),
.extra-fields-table .layui-input {
    height: 32px; line-height: 32px; padding: 0 10px; text-align: center;
    border-radius: 4px; border: 1px solid #dcdfe6;
    transition: border-color .2s, box-shadow .2s;
}
.spec-table .layui-input:focus,
.extra-fields-table .layui-input:focus {
    border-color: #1e9fff;
    box-shadow: 0 0 0 2px rgba(30, 159, 255, 0.12);
}
.extra-fields-table .layui-input { text-align: left; }
/* 表格内 select 下拉框样式 */
.extra-fields-table .layui-form-select .layui-input {
    height: 32px; line-height: 32px; border-radius: 4px;
}
.spec-table .layui-form-switch { height: 20px; line-height: 20px; margin-top: 4px; }
.spec-table .layui-form-radio{ padding-right: 0; margin-right: 0; }
.spec-table .layui-form-radio i{ padding-right: 0; margin-right: 0; }
/* 表格横向滚动 */
.spec-table-wrap { overflow-x: auto; margin-bottom: 10px; -webkit-overflow-scrolling: touch; }
.spec-table-wrap::-webkit-scrollbar { height: 6px; }
.spec-table-wrap::-webkit-scrollbar-thumb { background: #d0d0d0; border-radius: 3px; }
.spec-table-wrap::-webkit-scrollbar-track { background: #f0f0f0; }
/* 规格表操作列：横向滚动时固定在右侧，左侧阴影强化层次 */
.spec-table th.spec-col-actions,
.spec-table td.spec-col-actions {
    position: sticky;
    right: 0;
    z-index: 2;
    min-width: 140px !important;
    width: 140px;
    background: #fff;
    border-left: 1px solid #e2e8f0;
    box-shadow:
        -4px 0 8px rgba(15, 23, 42, 0.06),
        -12px 0 20px -4px rgba(15, 23, 42, 0.14),
        -20px 0 32px -8px rgba(15, 23, 42, 0.08);
}
.spec-table th.spec-col-actions::before,
.spec-table td.spec-col-actions::before {
    content: '';
    position: absolute;
    left: -14px;
    top: 0;
    bottom: 0;
    width: 14px;
    pointer-events: none;
    background: linear-gradient(to right, rgba(15, 23, 42, 0), rgba(15, 23, 42, 0.07));
}
.spec-table thead th.spec-col-actions {
    z-index: 3;
    background: #fafafa;
    box-shadow:
        -4px 0 8px rgba(15, 23, 42, 0.06),
        -12px 0 20px -4px rgba(15, 23, 42, 0.14),
        -20px 0 32px -8px rgba(15, 23, 42, 0.08);
}
.spec-table tbody tr:hover td.spec-col-actions {
    background: #f8f8f8;
}

/* 附加选项表操作列：横向滚动时固定在右侧，阴影与规格表一致 */
.extra-fields-table th.extra-col-actions,
.extra-fields-table td.extra-col-actions {
    position: sticky;
    right: 0;
    z-index: 2;
    min-width: 60px !important;
    width: 60px;
    background: #fff;
    border-left: 1px solid #e2e8f0;
    box-shadow:
        -4px 0 8px rgba(15, 23, 42, 0.06),
        -12px 0 20px -4px rgba(15, 23, 42, 0.14),
        -20px 0 32px -8px rgba(15, 23, 42, 0.08);
}
.extra-fields-table th.extra-col-actions::before,
.extra-fields-table td.extra-col-actions::before {
    content: '';
    position: absolute;
    left: -14px;
    top: 0;
    bottom: 0;
    width: 14px;
    pointer-events: none;
    background: linear-gradient(to right, rgba(15, 23, 42, 0), rgba(15, 23, 42, 0.07));
}
.extra-fields-table thead th.extra-col-actions {
    z-index: 3;
    background: #fafafa;
    box-shadow:
        -4px 0 8px rgba(15, 23, 42, 0.06),
        -12px 0 20px -4px rgba(15, 23, 42, 0.14),
        -20px 0 32px -8px rgba(15, 23, 42, 0.08);
}
.extra-fields-table tbody tr:hover td.extra-col-actions {
    background: #f8f8f8;
}

/* 拖拽手柄 */
.drag-handle {
    cursor: grab; text-align: center !important; color: #ccc;
    width: 30px; min-width: 30px !important; padding: 0 !important;
    user-select: none;
}
.drag-handle:active { cursor: grabbing; }
.drag-handle:hover { color: #999; }
.drag-handle .fa { font-size: 14px; line-height: 30px; }

/* Sortable 拖拽样式 */
.sortable-ghost { opacity: 0.4; background: #e6f7ff; }

/* 商品标签输入 */
.goods-tag-input-wrap { position: relative; }
.goods-tag-tokens {
    display: flex; flex-wrap: wrap; gap: 6px; align-items: center;
    padding: 4px 8px;
    border: 1px solid #e6e6e6; border-radius: 4px; background: #fff;
    cursor: text;
}
.goods-tag-token {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 8px; font-size: 12px; line-height: 20px;
    background: #eef3ff; color: #5b8ff9; border-radius: 3px;
    white-space: nowrap;
}
.goods-tag-remove { cursor: pointer; font-size: 11px; color: #aaa; }
.goods-tag-remove:hover { color: #f00; }
.goods-tag-text-input {
    border: none; outline: none; flex: 1; min-width: 120px;
    font-size: 13px; line-height: 28px; background: transparent;
}
.goods-tag-suggest {
    position: absolute; left: 0; right: 0; top: 100%; z-index: 9999;
    background: #fff; border: 1px solid #e6e6e6; border-top: none;
    border-radius: 0 0 4px 4px; max-height: 200px; overflow-y: auto;
    box-shadow: 0 4px 12px rgba(0,0,0,.08);
}
.goods-tag-suggest-item {
    padding: 6px 12px; font-size: 13px; cursor: pointer;
    display: flex; justify-content: space-between; align-items: center;
}
.goods-tag-suggest-item:hover { background: #f0f5ff; }
.goods-tag-suggest-item .tag-count { font-size: 11px; color: #adb5bd; }
</style>

<script>
$(function() {
    // em-tabs 点击切换：同步 .is-active 到 tab 项，同步 .layui-show 到对应面板
    // 独立于 layui.use 注册，确保模块加载异常时也能切换
    $('#goodsEditTabs').on('click', '.em-tabs__item', function() {
        var $item = $(this);
        if ($item.hasClass('is-active')) return;
        var index = $item.index();
        $item.addClass('is-active').siblings().removeClass('is-active');
        $item.closest('.em-tabs').next('.layui-tab-content')
            .children('.layui-tab-item')
            .removeClass('layui-show').eq(index).addClass('layui-show');
    });

    layui.use(['layer', 'form', 'upload', 'element'], function() {
        var layer = layui.layer;
        var form = layui.form;
        var upload = layui.upload;
        var element = layui.element;

        form.render(); 

        // ============================================================
        // 变量初始化
        // ============================================================
        var csrfToken = <?php echo json_encode($csrfToken); ?>;
        var goodsId = <?php echo $isEdit ? $goods['id'] : 0; ?>;
        var specIndex = <?php echo ($isEdit && !empty($specs)) ? count($specs) : 0; ?>;
        var extraFieldIndex = <?php echo !empty($extraFields) ? count($extraFields) : 0; ?>;
        var $previewList = $('#imagePreviewList');
        var $coverInput = $('#coverImagesInput');
        var placeholderImg = <?php echo json_encode($placeholderImg); ?>;

        // 富文本编辑器（封装见 /admin/static/js/em-tinymce.js）
        window.emTinymceInit({
            context: 'goods_image',
            onCsrf: function(token) { csrfToken = token; }, 
            editors: [
                { selector: '#editor-2', height: 450, placeholder: '请输入商品详情...' },
                { selector: '#editor-guide', height: 350, placeholder: '请输入使用教程...' }
            ]
        });
        window.emTinymceBindTabResize({ tabs: '#goodsEditTabs', tabIndex: 2 });

        // ============================================================
        // 图片相关
        // ============================================================
        function updateCoverInput() {
            var urls = [];
            $previewList.find('.image-preview-item').each(function() {
                urls.push($(this).data('url'));
            });
            $coverInput.val(JSON.stringify(urls));
            if ($previewList.find('.image-preview-item').length === 0) {
                $previewList.removeClass('has-images');
            } else {
                $previewList.addClass('has-images');
            }
        }

        function addImageToList(url) {
            if (!url) return;
            var $item = $('<div class="image-preview-item" data-url="' + url + '"><img src="' + url + '" class="img-clickable"><span class="remove-btn" onclick="removeImage(this)">×</span></div>');
            $previewList.append($item);
            updateCoverInput();
            bindImageZoom();
            // 多图模式：顶部主区永远保持占位图；上传/添加后清空 URL 输入框以便继续输入下一张
            $('#goodsImgUrl').val('');
        }

        window.removeImage = function(btn) {
            $(btn).closest('.image-preview-item').remove();
            updateCoverInput();
        };

        function bindImageZoom() {
            $previewList.find('.img-clickable').off('click').on('click', function() {
                var src = $(this).attr('src');
                layer.photos({
                    photos: { data: [{ src: src }] },
                    shade: 0.6,
                    anim: 5
                });
            });
        }
        bindImageZoom();

        // 多图模式下主预览区不跟随输入/操作变化，始终显示占位图；
        // 仅"清除"按钮用于清空 URL 输入框（不涉及图片预览）。
        $('#goodsImgClearBtn').on('click', function() {
            $('#goodsImgUrl').val('');
        });

        $('#goodsImgAddBtn').on('click', function() {
            var url = $.trim($('#goodsImgUrl').val());
            if (!url) {
                layer.msg('请先输入图片URL');
                return;
            }
            addImageToList(url);
        });

        $('#goodsImgUrl').on('keydown', function(e) {
            if (e.keyCode === 13) {
                e.preventDefault();
                $('#goodsImgAddBtn').trigger('click');
            }
        });

        // 选择按钮：打开媒体库（顶层弹出，避免嵌套在商品编辑弹窗内）
        $('#goodsImgPickBtn').on('click', function() {
            emOpenMediaPicker(function(url) {
                addImageToList(url);
            }, null, parent);
        });

        // 上传按钮
        var $fileInput = $('<input type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple style="display:none;">');
        $('body').append($fileInput);

        $fileInput.on('change', function() {
            var files = this.files;
            if (!files || !files.length) return;

            var uploaded = 0;
            var total = files.length;

            function uploadNext() {
                if (uploaded >= total) {
                    $fileInput.val('');
                    return;
                }
                var file = files[uploaded++];
                if (!file.type.match(/image\/(jpeg|png|gif|webp)/i)) {
                    layer.msg('仅支持 JPG、PNG、GIF、WebP 格式');
                    uploadNext();
                    return;
                }
                if (file.size > 10 * 1024 * 1024) {
                    layer.msg('图片大小不能超过 10MB');
                    uploadNext();
                    return;
                }

                var formData = new FormData();
                formData.append('file', file);
                formData.append('csrf_token', csrfToken);
                formData.append('context', 'goods_image');
                var loadingIdx = layer.load(2);
                $.ajax({
                    url: '/admin/upload.php',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(res) {
                        if (res.code === 200) {
                            addImageToList(res.data.url);
                            if (res.data.csrf_token) {
                                csrfToken = res.data.csrf_token;
                                $('input[name="csrf_token"]').val(csrfToken);
                            }
                            layer.msg('上传成功');
                        } else {
                            layer.msg(res.msg || '上传失败');
                        }
                    },
                    error: function() {
                        layer.msg('网络异常，上传失败');
                    },
                    complete: function() {
                        layer.close(loadingIdx);
                        uploadNext();
                    }
                });
            }

            uploadNext();
        });

        $('#goodsImgUploadBtn').on('click', function() {
            $fileInput.trigger('click');
        });

        // 拖拽排序（Sortable.js）
        if (typeof Sortable !== 'undefined') {
            // 图片排序
            new Sortable($previewList[0], {
                animation: 150,
                ghostClass: 'sortable-ghost',
                onEnd: function() { updateCoverInput(); }
            });
            // 规格表格排序
            new Sortable(document.getElementById('specList'), {
                animation: 150,
                handle: '.drag-handle',
                ghostClass: 'sortable-ghost'
            });
            // 附加选项表格排序
            new Sortable(document.getElementById('extraFieldsList'), {
                animation: 150,
                handle: '.drag-handle',
                ghostClass: 'sortable-ghost'
            });
        } else {
            console.warn('Sortable.js 未加载，拖拽排序不可用');
        }

        // ============================================================
        // 规格相关
        // ============================================================
        // 规格行操作列（4 个图标按钮 + 隐藏 configs JSON 字段）的模板，新增/重建时复用
        function specActionsCellHtml(idx) {
            return '<td class="spec-col-actions">' +
                '<input type="hidden" class="spec-configs" name="specs[' + idx + '][configs]" value="{}">' +
                '<div class="spec-actions">' +
                    '<button type="button" class="spec-action-btn" data-action="levelPrice" title="用户等级专属价"><i class="fa fa-id-badge"></i></button>' +
                    '<button type="button" class="spec-action-btn" data-action="userPrice" title="用户专属价"><i class="fa fa-user"></i></button>' +
                    '<button type="button" class="spec-action-btn" data-action="images" title="规格专属图片"><i class="fa fa-image"></i></button>' +
                    '<button type="button" class="spec-action-btn spec-action-btn--danger" onclick="removeSpec(this)" title="删除"><i class="fa fa-trash"></i></button>' +
                '</div>' +
            '</td>';
        }

        $('#addSpecBtn').click(function() {
            var idx = specIndex++;
            var html = '<tr class="spec-row">' +
                '<td class="drag-handle"><input type="hidden" name="specs[' + idx + '][id]" value=""><i class="fa fa-bars"></i></td>' +
                '<td class="spec-td"><input type="text" name="specs[' + idx + '][name]" class="layui-input" placeholder="规格值"></td>' +
                '<td><input type="number" step="0.01" name="specs[' + idx + '][price]" class="layui-input" value="" placeholder=""></td>' +
                '<td><input type="number" step="0.01" name="specs[' + idx + '][cost_price]" class="layui-input" value="" placeholder=""></td>' +
                '<td><input type="number" step="0.01" name="specs[' + idx + '][market_price]" class="layui-input" value="" placeholder=""></td>' +
                '<td><input type="text" name="specs[' + idx + '][spec_no]" class="layui-input" placeholder=""></td>' +
                '<td><input type="text" name="specs[' + idx + '][tags]" class="layui-input" placeholder=""></td>' +
                '<td><input type="number" name="specs[' + idx + '][min_buy]" class="layui-input" value=""></td>' +
                '<td><input type="number" name="specs[' + idx + '][max_buy]" class="layui-input" value="" placeholder=""></td>' +
                '<td><input type="number" step="1" min="0" name="specs[' + idx + '][sold_count]" class="layui-input" value="0" placeholder="0" title="前台展示销量"></td>' +
                '<td><input type="radio" name="specs[is_default]" lay-skin="primary" value="' + idx + '"></td>' +
                specActionsCellHtml(idx) +
                '</tr>';
            $('#specList').append(html);
            form.render();
        });

        window.removeSpec = function(btn) {
            var $row = $(btn).closest('.spec-row');
            var specId = $row.data('spec-id');

            layer.confirm('确定要删除该规格吗？', function(index) {
                layer.close(index);
                if (specId) {
                    $.ajax({
                        url: '/admin/goods_edit.php?_action=remove_spec',
                        type: 'POST',
                        dataType: 'json',
                        data: { spec_id: specId, csrf_token: csrfToken },
                        success: function(res) {
                            if (res.code === 200) {
                                csrfToken = res.data.csrf_token;
                                $row.remove();
                                layer.msg('删除成功');
                            } else {
                                layer.msg(res.msg || '删除失败');
                            }
                        },
                        error: function() { layer.msg('网络异常'); }
                    });
                } else {
                    $row.remove();
                }
            });
        };

        // ============================================================
        // 规格行 3 个配置弹窗（用户等级专属价 / 用户专属价 / 规格图片）
        // 每行的 configs JSON 存在 .spec-configs 隐藏字段里，弹窗打开时反序列化、保存时序列化
        // ============================================================
        function readSpecConfigs($row) {
            try {
                var v = JSON.parse($row.find('.spec-configs').val() || '{}');
                // 兜底：如果解析出来是数组（PHP 空 associative array 被 json_encode 编成 "[]"），强制当对象看待
                // 否则后续给它挂 .images / .level_prices 属性在 JSON.stringify 时会被全部丢弃
                return (v && typeof v === 'object' && !Array.isArray(v)) ? v : {};
            } catch (e) { return {}; }
        }
        function writeSpecConfigs($row, obj) {
            // 去掉空集合让 JSON 更干净
            if (obj.images && !obj.images.length) delete obj.images;
            if (obj.level_prices && !Object.keys(obj.level_prices).length) delete obj.level_prices;
            if (obj.user_prices && !Object.keys(obj.user_prices).length) {
                delete obj.user_prices;
                delete obj.user_labels;   // 价格清了 label 也没意义
            }
            $row.find('.spec-configs').val(JSON.stringify(obj));
            // 每个按钮各自根据自己的字段是否有值显示小徽记（插件按钮的徽记由插件自己按它的键决定）
            $row.find('[data-action="levelPrice"]').toggleClass('has-value', !!(obj.level_prices && Object.keys(obj.level_prices).length));
            $row.find('[data-action="userPrice"]').toggleClass('has-value', !!(obj.user_prices && Object.keys(obj.user_prices).length));
            $row.find('[data-action="images"]').toggleClass('has-value', !!(obj.images && obj.images.length));
        }
        // 初始化时：对现有每一行，根据 configs 内容点亮徽记
        $('#specList .spec-row').each(function () {
            var $row = $(this);
            var cfg = readSpecConfigs($row);
            writeSpecConfigs($row, cfg); // 会把徽记刷新出来
        });

        // 规格图片拖拽排序（模态里）
        var specImagesSortable = null;
        function ensureSpecImagesSortable() {
            if (specImagesSortable || typeof Sortable === 'undefined') return;
            specImagesSortable = new Sortable(document.getElementById('specImagesPreviewList'), {
                animation: 150, ghostClass: 'sortable-ghost'
            });
        }

        // 规格配置弹窗尺寸：大屏用首选 px，小屏/矮屏按视口百分比自适应
        function specModalArea(preferW, preferH) {
            var w = window.innerWidth >= 700 ? (preferW + 'px') : '95%';
            var h = window.innerHeight >= 640 ? (preferH + 'px') : '80%';
            return [w, h];
        }

        // 统一入口：点击任意规格行的 3 个动作按钮
        // （插件往 .spec-actions 里加的按钮自带 data-action，不在此列 —— 它自己绑事件）
        $(document).on('click', '.spec-action-btn[data-action]', function () {
            var action = $(this).data('action');
            var $row = $(this).closest('.spec-row');
            if (action === 'levelPrice') openLevelPriceModal($row);
            else if (action === 'userPrice') openUserPriceModal($row);
            else if (action === 'images') openImagesModal($row);
        });

        // ---------- ① 用户等级专属价 ----------
        function openLevelPriceModal($row) {
            var specName = $row.find('input[name*="[name]"]').val() || '规格';
            var cfg = readSpecConfigs($row);
            var levelPrices = cfg.level_prices || {};
            // 把现值填进去
            $('#specLevelPriceList .spec-level-price').each(function () {
                var id = String($(this).data('level-id'));
                $(this).val(levelPrices[id] !== undefined ? levelPrices[id] : '');
            });
            $('#specLevelPriceModal').show(); // .spec-modal 类默认 display:none，layer 不会帮忙去掉
            layer.open({
                type: 1, title: '用户等级专属价 — ' + emEsc(specName),
                skin: 'admin-modal',
                area: specModalArea(520, 500),
                content: $('#specLevelPriceModal'),
                btn: ['保存', '取消'],
                yes: function (idx) {
                    var next = {};
                    $('#specLevelPriceList .spec-level-price').each(function () {
                        var id = String($(this).data('level-id'));
                        var v = $.trim($(this).val());
                        if (v !== '') next[id] = parseFloat(v);
                    });
                    cfg.level_prices = next;
                    writeSpecConfigs($row, cfg);
                    layer.close(idx);
                    layer.msg('已保存');
                },
                end: function () { $('#specLevelPriceModal').hide(); } // 关闭后 hide 回去，避免 body 底部出现残影
            });
        }

        // ---------- ② 用户专属价（搜索式） ----------
        // 每一行：hidden[user-id] + 用户展示名 + price + 删除
        function buildUserPriceRow(userId, label, price) {
            var safeLabel = String(label || '').replace(/</g, '&lt;').replace(/"/g, '&quot;');
            return '<tr data-user-id="' + userId + '">' +
                '<td style="font-size:13px;color:#374151;">' + safeLabel + '</td>' +
                '<td><input type="number" step="0.01" min="0" class="spec-user-price" value="' + (price || '') + '" placeholder="专属价"></td>' +
                '<td><button type="button" class="spec-modal__row-del" onclick="$(this).closest(&quot;tr&quot;).remove();"><i class="fa fa-times"></i></button></td>' +
            '</tr>';
        }
        function openUserPriceModal($row) {
            var specName = $row.find('input[name*="[name]"]').val() || '规格';
            var cfg = readSpecConfigs($row);
            var userPrices = cfg.user_prices || {};
            var userLabels = cfg.user_labels || {};
            // 渲染已配置行
            var $list = $('#specUserPriceList').empty();
            Object.keys(userPrices).forEach(function (uid) {
                var label = userLabels[uid] || ('用户 #' + uid);
                $list.append(buildUserPriceRow(uid, label, userPrices[uid]));
            });
            // 清空搜索框 + 下拉
            $('#specUserSearchInput').val('');
            $('#specUserSearchDropdown').removeClass('is-open').empty();

            $('#specUserPriceModal').show();
            layer.open({
                type: 1, title: '用户专属价 — ' + emEsc(specName),
                skin: 'admin-modal',
                area: specModalArea(560, 540),
                content: $('#specUserPriceModal'),
                btn: ['保存', '取消'],
                yes: function (idx) {
                    var nextPrices = {}, nextLabels = {};
                    $('#specUserPriceList tr').each(function () {
                        var uid = String($(this).data('user-id') || '');
                        var price = $.trim($(this).find('.spec-user-price').val());
                        var label = $(this).find('td:first').text();
                        if (uid !== '' && price !== '') {
                            nextPrices[uid] = parseFloat(price);
                            nextLabels[uid] = label;
                        }
                    });
                    cfg.user_prices = nextPrices;
                    cfg.user_labels = nextLabels;
                    writeSpecConfigs($row, cfg);
                    layer.close(idx);
                    layer.msg('已保存');
                },
                end: function () { $('#specUserPriceModal').hide(); }
            });
        }

        // 用户搜索输入：debounce 300ms
        var userSearchTimer = null;
        $(document).on('input', '#specUserSearchInput', function () {
            var q = $.trim($(this).val());
            clearTimeout(userSearchTimer);
            var $dd = $('#specUserSearchDropdown');
            if (q === '') { $dd.removeClass('is-open').empty(); return; }
            userSearchTimer = setTimeout(function () {
                $.ajax({
                    url: '/admin/goods_edit.php',
                    method: 'GET',
                    data: { _action: 'search_users', q: q, _t: Date.now() },
                    dataType: 'json',
                    timeout: 6000
                }).done(function (resp) {
                    var list = (resp && resp.data && resp.data.list) || [];
                    var html = '';
                    if (list.length === 0) {
                        html = '<div class="spec-user-search__empty">没有匹配的用户</div>';
                    } else {
                        // 已在下方列表里的 user_id 标记为"已添加"
                        var addedIds = {};
                        $('#specUserPriceList tr').each(function () { addedIds[String($(this).data('user-id'))] = 1; });
                        list.forEach(function (u) {
                            var name = u.nickname || u.username || ('#' + u.id);
                            var safeName = String(name).replace(/</g, '&lt;');
                            var safeUname = String(u.username || '').replace(/</g, '&lt;');
                            var safeEmail = String(u.email || '').replace(/</g, '&lt;');
                            var avatar = u.avatar
                                ? '<img src="' + u.avatar + '" alt="">'
                                : '<div class="spec-user-search__avatar">' + safeName.slice(0, 1) + '</div>';
                            var added = addedIds[String(u.id)];
                            html += '<div class="spec-user-search__item" data-user-id="' + u.id + '" data-label="' + safeName.replace(/"/g, '&quot;') + ' (#' + u.id + ')">' +
                                avatar +
                                '<div class="spec-user-search__meta">' +
                                    '<div class="spec-user-search__name">' + safeName + ' <span style="color:#9ca3af;font-size:11px;">#' + u.id + '</span></div>' +
                                    '<div class="spec-user-search__sub">' + safeUname + (safeEmail ? ' · ' + safeEmail : '') + '</div>' +
                                '</div>' +
                                (added ? '<span class="spec-user-search__added">已添加</span>' : '') +
                            '</div>';
                        });
                    }
                    $dd.html(html).addClass('is-open');
                });
            }, 300);
        });

        // 点击搜索结果 → 加入价格列表
        $(document).on('click', '#specUserSearchDropdown .spec-user-search__item', function () {
            var uid = String($(this).data('user-id'));
            var label = $(this).data('label');
            // 去重
            if ($('#specUserPriceList tr[data-user-id="' + uid + '"]').length > 0) {
                layer.msg('该用户已在列表中');
                return;
            }
            $('#specUserPriceList').append(buildUserPriceRow(uid, label, ''));
            // 清搜索状态，聚焦第一行的价格输入
            $('#specUserSearchInput').val('');
            $('#specUserSearchDropdown').removeClass('is-open').empty();
            $('#specUserPriceList tr[data-user-id="' + uid + '"] .spec-user-price').focus();
        });

        // 点外面关下拉
        $(document).on('click', function (e) {
            if (!$(e.target).closest('.spec-user-search').length) {
                $('#specUserSearchDropdown').removeClass('is-open');
            }
        });

        // ---------- ③ 规格图片 ----------
        var _specImagesRow = null;  // 当前正在编辑的 spec row
        function renderSpecImages(images) {
            var $list = $('#specImagesPreviewList').empty();
            (images || []).forEach(function (url) {
                $list.append(
                    '<div class="image-preview-item" data-url="' + url + '">' +
                        '<img src="' + url + '" class="img-clickable">' +
                        '<span class="remove-btn" onclick="$(this).closest(&quot;.image-preview-item&quot;).remove();">×</span>' +
                    '</div>'
                );
            });
            ensureSpecImagesSortable();
        }
        function collectSpecImages() {
            var urls = [];
            $('#specImagesPreviewList .image-preview-item').each(function () { urls.push($(this).data('url')); });
            return urls;
        }
        function openImagesModal($row) {
            _specImagesRow = $row;
            var specName = $row.find('input[name*="[name]"]').val() || '规格';
            var cfg = readSpecConfigs($row);
            renderSpecImages(cfg.images || []);
            $('#specImagesUrl').val('');
            $('#specImagesModal').show();
            layer.open({
                type: 1, title: '规格专属图片 — ' + emEsc(specName),
                skin: 'admin-modal',
                area: specModalArea(600, 520),
                content: $('#specImagesModal'),
                btn: ['保存', '取消'],
                yes: function (idx) {
                    var cfg2 = readSpecConfigs(_specImagesRow);
                    cfg2.images = collectSpecImages();
                    writeSpecConfigs(_specImagesRow, cfg2);
                    layer.close(idx);
                    layer.msg('已保存');
                },
                end: function () {
                    _specImagesRow = null;
                    $('#specImagesModal').hide();
                }
            });
        }
        // 规格图片弹窗里的按钮处理（复用商品图片逻辑，但独立 ID）
        $(document).on('click', '#specImagesAddBtn', function () {
            var url = $.trim($('#specImagesUrl').val());
            if (!url) { layer.msg('请先输入图片 URL'); return; }
            $('#specImagesPreviewList').append(
                '<div class="image-preview-item" data-url="' + url + '">' +
                    '<img src="' + url + '" class="img-clickable">' +
                    '<span class="remove-btn" onclick="$(this).closest(&quot;.image-preview-item&quot;).remove();">×</span>' +
                '</div>'
            );
            $('#specImagesUrl').val('');
            ensureSpecImagesSortable();
        });
        $(document).on('keydown', '#specImagesUrl', function (e) {
            if (e.keyCode === 13) { e.preventDefault(); $('#specImagesAddBtn').trigger('click'); }
        });
        $(document).on('click', '#specImagesClearBtn', function () { $('#specImagesUrl').val(''); });
        // 上传按钮：和商品图片共用同一个文件选择器体验（直接新开 input）
        $(document).on('click', '#specImagesUploadBtn', function () {
            var $fi = $('<input type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple style="display:none;">').appendTo('body');
            $fi.on('change', function () {
                var files = this.files; if (!files || !files.length) { $fi.remove(); return; }
                var done = 0, total = files.length;
                var loadingIdx = layer.load(2);
                function finishOne() {
                    done++;
                    if (done === total) {
                        layer.close(loadingIdx);
                        $fi.remove();
                    }
                }
                Array.prototype.forEach.call(files, function (file) {
                    if (!file.type.match(/image\/(jpeg|png|gif|webp)/i) || file.size > 10 * 1024 * 1024) { finishOne(); return; }
                    var fd = new FormData();
                    fd.append('file', file); fd.append('csrf_token', csrfToken); fd.append('context', 'spec_image');
                    $.ajax({
                        url: '/admin/upload.php', type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
                        success: function (res) {
                            if (res.code === 200 && res.data && res.data.url) {
                                $('#specImagesPreviewList').append(
                                    '<div class="image-preview-item" data-url="' + res.data.url + '">' +
                                        '<img src="' + res.data.url + '" class="img-clickable">' +
                                        '<span class="remove-btn" onclick="$(this).closest(&quot;.image-preview-item&quot;).remove();">×</span>' +
                                    '</div>'
                                );
                                if (res.data.csrf_token) { csrfToken = res.data.csrf_token; $('input[name="csrf_token"]').val(csrfToken); }
                                ensureSpecImagesSortable();
                            }
                        },
                        complete: function () { finishOne(); }
                    });
                });
            }).trigger('click');
        });
        // 选择按钮：打开媒体库
        $(document).on('click', '#specImagesPickBtn', function () {
            emOpenMediaPicker(function (url) {
                $('#specImagesPreviewList').append(
                    '<div class="image-preview-item" data-url="' + url + '">' +
                        '<img src="' + url + '" class="img-clickable">' +
                        '<span class="remove-btn" onclick="$(this).closest(&quot;.image-preview-item&quot;).remove();">×</span>' +
                    '</div>'
                );
                ensureSpecImagesSortable();
            }, null, parent);
        });

        // 刷新规格列表
        window.refreshSpecList = function(goodsId) {
            $.ajax({
                url: '/admin/goods_edit.php',
                type: 'GET',
                dataType: 'json',
                data: { id: goodsId, _action: 'get_specs_json' },
                success: function(res) {
                    if (res.code === 200 && res.data) {
                        renderSpecsToTable(res.data.specs);
                    }
                },
                error: function() {
                    console.error('刷新规格列表失败');
                }
            });
        };

        window.renderSpecsToTable = function(specs) {
            var $tbody = $('#specList');
            $tbody.empty();
            var esc = function(str) {
                return String(str || '').replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;');
            };
            specs.forEach(function(spec, idx) {
                var tags = Array.isArray(spec.tags) ? spec.tags.join(',') : (spec.tags || '');
                var minBuy = spec.min_buy || '';
                var maxBuy = spec.max_buy || '';
                // configs 可能是已解码的对象/数组，也可能是 JSON 字符串，也可能缺失
                var configsStr = '{}';
                if (typeof spec.configs === 'string' && spec.configs) configsStr = spec.configs;
                else if (spec.configs && typeof spec.configs === 'object') configsStr = JSON.stringify(spec.configs);
                var html = '<tr class="spec-row" data-spec-id="' + esc(spec.id) + '">' +
                    '<td class="drag-handle"><input type="hidden" name="specs[' + idx + '][id]" value="' + esc(spec.id) + '"><i class="fa fa-bars"></i></td>' +
                    '<td><input type="text" name="specs[' + idx + '][name]" class="layui-input" value="' + esc(spec.name) + '" placeholder="如:默认"></td>' +
                    '<td><input type="number" step="0.01" name="specs[' + idx + '][price]" class="layui-input" value="' + esc(spec.price) + '"></td>' +
                    '<td><input type="number" step="0.01" name="specs[' + idx + '][cost_price]" class="layui-input" value="' + esc(spec.cost_price) + '" placeholder="成本"></td>' +
                    '<td><input type="number" step="0.01" name="specs[' + idx + '][market_price]" class="layui-input" value="' + esc(spec.market_price) + '" placeholder="划线价"></td>' +
                    '<td><input type="text" name="specs[' + idx + '][spec_no]" class="layui-input" value="' + esc(spec.spec_no) + '"></td>' +
                    '<td><input type="text" name="specs[' + idx + '][tags]" class="layui-input" value="' + esc(tags) + '" placeholder="标签"></td>' +
                    '<td><input type="number" name="specs[' + idx + '][min_buy]" class="layui-input" value="' + esc(minBuy) + '"></td>' +
                    '<td><input type="number" name="specs[' + idx + '][max_buy]" class="layui-input" value="' + esc(maxBuy) + '" placeholder="0不限"></td>' +
                    '<td><input type="number" step="1" min="0" name="specs[' + idx + '][sold_count]" class="layui-input" value="' + esc(spec.sold_count || 0) + '" placeholder="0" title="前台展示销量"></td>' +
                    '<td><input type="radio" name="specs[is_default]" lay-skin="primary" value="' + idx + '"' + (spec.is_default == 1 ? ' checked' : '') + '></td>' +
                    '<td class="spec-col-actions">' +
                        '<input type="hidden" class="spec-configs" name="specs[' + idx + '][configs]" value="' + esc(configsStr) + '">' +
                        '<div class="spec-actions">' +
                            '<button type="button" class="spec-action-btn" data-action="levelPrice" title="用户等级专属价"><i class="fa fa-id-badge"></i></button>' +
                            '<button type="button" class="spec-action-btn" data-action="userPrice" title="用户专属价"><i class="fa fa-user"></i></button>' +
                            '<button type="button" class="spec-action-btn" data-action="images" title="规格专属图片"><i class="fa fa-image"></i></button>' +
                            '<button type="button" class="spec-action-btn spec-action-btn--danger" onclick="removeSpec(this)" title="删除"><i class="fa fa-trash"></i></button>' +
                        '</div>' +
                    '</td>' +
                    '</tr>';
                $tbody.append(html);
            });
            if (typeof form !== 'undefined') {
                form.render('radio');
            }
        };

        // 规格设置教程弹窗
        $('#specHelpBtn').on('click', function() {
            layer.open({
                type: 1,
                title: '<i class="fa fa-info-circle"></i> 规格设置教程',
                skin: 'admin-modal',
                area: [window.innerWidth >= 700 ? '520px' : '95%', 'auto'],
                content: '<div style="padding:20px;line-height:2;font-size:14px;">' +
                    '<p><b>① 单规格：</b>仅需填写一行，规格类型与规格值留空即可</p>' +
                    '<p><b>② 单维规格：</b>例如 【规格类型：时长】 【规格值：周卡、月卡、年卡】有几个就添加几个规格</p>' +
                    '<p><b>③ 多维规格：</b>例如 【规格类型：颜色/款式】 【规格值：黑色/长款、白色/长款、白色/短款】用斜线（/）分隔</p>' +
                    '<p style="margin-top:10px;color:#999;font-size:12px;">本教程由群主手写，如看不懂，那就再看一遍。</p>' +
                    '</div>',
                btn: ['<i class="fa fa-check"></i> 知道了'],
                btnAlign: 'c'
            });
        });

        // ============================================================
        // 附加选项（自定义表单字段）
        // ============================================================
        $('#addExtraFieldBtn').on('click', function() {
            var idx = extraFieldIndex++;
            var html = '<tr class="extra-field-row">' +
                '<td class="drag-handle"><i class="fa fa-bars"></i></td>' +
                '<td><input type="text" name="extra_fields[' + idx + '][title]" class="layui-input" placeholder="如：QQ号"></td>' +
                '<td><input type="text" name="extra_fields[' + idx + '][name]" class="layui-input" placeholder="如：qq"></td>' +
                '<td><input type="text" name="extra_fields[' + idx + '][placeholder]" class="layui-input" placeholder="如：请输入QQ号"></td>' +
                '<td><select name="extra_fields[' + idx + '][format]">' +
                    '<option value="text">文本</option>' +
                    '<option value="number">纯数字</option>' +
                    '<option value="phone">手机号</option>' +
                    '<option value="email">邮箱</option>' +
                '</select></td>' +
                '<td style="text-align:center;"><input type="checkbox" name="extra_fields[' + idx + '][required]" value="1" lay-skin="switch" lay-text="是|否"></td>' +
                '<td class="extra-col-actions" style="text-align:center;"><button type="button" class="layui-btn layui-btn-danger layui-btn-xs" onclick="$(this).closest(\'tr\').remove()"><i class="fa fa-trash"></i></button></td>' +
                '</tr>';
            $('#extraFieldsList').append(html);
            form.render();
        });

        // ============================================================
        // 商品类型切换：弹出类型配置弹窗
        // ============================================================
        var openPluginConfigPopup = function(goodsType) {
            if (!goodsType) {
                layer.msg('请先选择商品类型');
                return;
            }
            var loadingIdx = layer.load(2);
            $.ajax({
                url: '/admin/goods_edit.php?_action=get_plugin_form',
                type: 'POST',
                dataType: 'json',
                data: {
                    csrf_token: csrfToken,
                    goods_type: goodsType,
                    goods_id: goodsId
                },
                success: function(res) {
                    layer.close(loadingIdx);
                    if (res.code !== 200) {
                        layer.msg(res.msg || '加载失败');
                        return;
                    }

                    var formHtml = res.data.html || '';
                    if (!formHtml || !$.trim(formHtml)) {
                        layer.msg('该类型暂无配置项');
                        return;
                    }

                    // 构建弹窗内容（高度随内容收缩，超出视口时可滚动）
                    var popupHtml = '<div style="padding:15px;box-sizing:border-box;">' +
                        '<form class="layui-form" id="pluginConfigForm" lay-filter="pluginConfigForm">' +
                        formHtml +
                        '</form>' +
                        '</div>';

                    var popupW = window.innerWidth >= 700 ? '600px' : '95%';
                    var popupMaxH = Math.max(280, Math.floor(window.innerHeight * 0.9));

                    layer.open({
                        type: 1,
                        title: '<i class="fa fa-cog"></i> 类型配置',
                        skin: 'admin-modal',
                        area: [popupW, 'auto'],
                        maxHeight: popupMaxH,
                        content: popupHtml,
                        btn: ['<i class="fa fa-check"></i> 确认', '<i class="fa fa-times"></i> 取消'],
                        btnAlign: 'r',
                        yes: function(index) {
                            // 确认：将弹窗中的表单字段复制到主表单的隐藏容器中
                            var $container = $('#pluginFormContainer');
                            $container.empty().append($('#pluginConfigForm').html());
                            // 同步表单字段值
                            $('#pluginConfigForm').find('input, select, textarea').each(function() {
                                var name = $(this).attr('name');
                                if (!name) return;
                                var $target = $container.find('[name="' + name + '"]');
                                if ($(this).is(':checkbox') || $(this).is(':radio')) {
                                    $target.prop('checked', $(this).prop('checked'));
                                } else {
                                    $target.val($(this).val());
                                }
                            });
                            layer.close(index);
                        },
                        success: function() {
                            // 从隐藏容器回填之前保存的字段值到弹窗表单
                            var $container = $('#pluginFormContainer');
                            if ($container.children().length) {
                                $container.find('input, select, textarea').each(function() {
                                    var name = $(this).attr('name');
                                    if (!name) return;
                                    var $target = $('#pluginConfigForm').find('[name="' + name + '"]');
                                    if (!$target.length) return;
                                    if ($(this).is(':checkbox') || $(this).is(':radio')) {
                                        $target.prop('checked', $(this).prop('checked'));
                                    } else {
                                        $target.val($(this).val());
                                    }
                                });
                            }
                            form.render(null, 'pluginConfigForm');
                        }
                    });
                },
                error: function() {
                    layer.close(loadingIdx);
                    layer.msg('网络异常');
                }
            });
        };

        // 类型配置按钮点击
        $('#editPluginConfigBtn').on('click', function() {
            var currentType = $('#goodsTypeSelect').val();
            openPluginConfigPopup(currentType);
        });

        // 切换商品类型时自动弹出配置
        form.on('select(goodsType)', function(data) {
            var goodsType = data.value;
            if (!goodsType) {
                $('#pluginFormContainer').empty();
                return;
            }
            openPluginConfigPopup(goodsType);
        });

        // ============================================================
        // 默认行：表格为空时自动添加一行
        // ============================================================
        if ($('#specList tr').length === 0) {
            $('#addSpecBtn').trigger('click');
        }
        if ($('#extraFieldsList tr').length === 0) {
            $('#addExtraFieldBtn').trigger('click');
        }

        // 重新渲染选项卡，确保所有tab可切换
        element.render('tab');

        // ============================================================
        // 保存
        // ============================================================
        $('#goodsCancelBtn').on('click', function() {
            var index = parent.layer.getFrameIndex(window.name);
            parent.layer.close(index);
        });

        $('#goodsSubmitBtn').on('click', function() {
            var $btn = $(this);
            $btn.find('i').attr('class', 'fa fa-refresh admin-spin');
            $btn.prop('disabled', true);

            window.emTinymceSave();
            var formData = $('#goodsForm').serialize();

            $.ajax({
                url: '/admin/goods_edit.php?_action=save',
                type: 'POST',
                data: formData,
                dataType: 'json',
                success: function(res) {
                    if (res.code === 200) {
                        if (res.data && res.data.csrf_token) {
                            try { parent.updateCsrf(res.data.csrf_token); } catch(e) {}
                        }
                        try { parent.window._goodsPopupSaved = true; } catch(e) {}
                        var index = parent.layer.getFrameIndex(window.name);
                        parent.layer.msg(res.msg || '保存成功');
                        parent.layer.close(index);
                    } else {
                        layer.msg(res.msg || '保存失败');
                    }
                },
                error: function() {
                    layer.msg('网络错误，请重试');
                },
                complete: function() {
                    $btn.find('i').attr('class', 'fa fa-check');
                    $btn.prop('disabled', false);
                }
            });
        });
    });
});

// 商品标签输入
(function(){
    var allTags = <?= json_encode(GoodsTagModel::getAll(), JSON_UNESCAPED_UNICODE) ?>;
    var $wrap = $('#goodsTagInputWrap');
    var $tokens = $('#goodsTagTokens');
    var $input = $('#goodsTagTextInput');
    var $hidden = $('#goodsTagHiddenInput');
    var $suggest = $('#goodsTagSuggest');

    function syncHidden() {
        var names = [];
        $tokens.find('.goods-tag-token').each(function(){ names.push($(this).text().trim()); });
        $hidden.val(names.join(','));
    }
    function hasTag(name) {
        var found = false;
        $tokens.find('.goods-tag-token').each(function(){
            if ($(this).text().trim().toLowerCase() === name.toLowerCase()) { found = true; return false; }
        });
        return found;
    }
    function addTag(name) {
        name = $.trim(name);
        if (!name || hasTag(name)) return;
        var $tag = $('<span class="goods-tag-token">' + $('<span>').text(name).html()
            + ' <i class="fa fa-times goods-tag-remove"></i></span>');
        $input.before($tag);
        syncHidden();
    }
    // 删除标签
    $tokens.on('click', '.goods-tag-remove', function(){ $(this).parent().remove(); syncHidden(); });
    // 点击区域聚焦
    $tokens.on('click', function(){ $input.focus(); });
    // 回车添加
    $input.on('keydown', function(e){
        if (e.keyCode === 13) { e.preventDefault(); addTag($input.val()); $input.val(''); $suggest.hide(); }
        if (e.keyCode === 8 && $input.val() === '') {
            $tokens.find('.goods-tag-token').last().remove(); syncHidden();
        }
    });
    // 自动补全
    $input.on('input', function(){
        var val = $.trim($input.val()).toLowerCase();
        if (!val) { $suggest.hide(); return; }
        var html = '', count = 0;
        for (var i = 0; i < allTags.length && count < 8; i++) {
            if (allTags[i].name.toLowerCase().indexOf(val) !== -1 && !hasTag(allTags[i].name)) {
                // 标签名要进 HTML 属性（data-name），必须连引号一起转义：
                // $('<span>').text(x).html() 只转义 & < >，**不转义引号**，
                // 属性上下文里一个双引号就能逃出去注入事件处理器。
                // 标签池是全站共享的，商户可创建标签 → 可打向管理员。
                var tagName = String(allTags[i].name == null ? '' : allTags[i].name)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
                html += '<div class="goods-tag-suggest-item" data-name="' + tagName + '">'
                    + tagName
                    + '<span class="tag-count">' + (allTags[i].goods_count || 0) + '件</span></div>';
                count++;
            }
        }
        if (html) { $suggest.html(html).show(); } else { $suggest.hide(); }
    });
    $suggest.on('click', '.goods-tag-suggest-item', function(){ addTag($(this).data('name')); $input.val(''); $suggest.hide(); });
    $(document).on('click', function(e){ if (!$(e.target).closest('#goodsTagInputWrap').length) $suggest.hide(); });
})();
</script>

<?php include __DIR__ . '/footer.php'; ?>
