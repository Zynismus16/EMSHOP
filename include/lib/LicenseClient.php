<?php

declare(strict_types=1);

/**
 * 授权服务器 HTTP 客户端。
 *
 * 职责：把对授权服务器的所有网络调用集中在一处，业务层（LicenseService）只拿结构化结果。
 * 服务端地址来自本类的线路常量（多线路，不读 config.php）：每次请求**只走用户当前选中的那条**，
 * 不做自动换线、不重试；线路不通直接抛异常，由用户在后台手动切换线路。
 *
 * 约定（详见 a 系统文档/应用商店方案.md §8）：
 *   所有响应 JSON 统一格式：{ ok: bool, data?: ..., code?: string, msg?: string }
 *   成功拿 data；失败抛 RuntimeException（message = 服务端 msg 或默认文案）
 *
 * 超时：短请求 10s；批量校验 30s。
 * 网络错误全部统一 throw RuntimeException('授权服务器不可达')，调用方可据此降级（比如本地保守保留状态）。
 */
final class LicenseClient
{
    /**
     * 官方线路地址。**这里是全站唯一的定义处** —— `init.php` 用它定义
     * `EM_LICENSE_SERVER_URL`；安装程序（`InstallReporter`）在 `init.php`
     * 还没加载时直接读这份线路常量，两边不会各写一份。
     *
     * 形状是 `[{url, name}]`：url 带不带尾斜杠都行（内部会补），name 只用于后台展示。
     * 顺序只决定后台列表的展示顺序；实际请求只走当前选中索引那一条。
     *
     * 为什么写死在代码里而不是配置文件里：地址必须**跟着程序版本走**。
     * 放 config.php 的话，老站升级后残留的旧地址会继续生效（见 `serverUrl()`）。
     */
    // public const OFFICIAL_BASE_URL = 'https://bs.ihehe.me/';

    public const OFFICIAL_BASE_URL = [
        [
            'url' => 'https://bs.ihehe.me/',
            'name' => '香港优化;三网混合',
        ],
        [
            'url' => 'https://bs2.ihehe.me/',
            'name' => '香港极速;三网直连',
        ]
    ];


    /** 本地联调用的线路（后台「开发模式」开启时走它，见 init.php） */
    // public const DEV_BASE_URL = 'http://127.0.0.1:3000/';

    public const DEV_BASE_URL = [
        [
            'url' => 'http://127.0.0.1:8000/',
            'name' => '香港优化;三网混合;开发模式',
        ],
        [
            'url' => 'http://127.0.0.1:8800/',
            'name' => '香港极速;三网直连;开发模式',
        ],
    ];

    /**
     * 授权激活：把激活码绑到域名上（POST /api/open/v1/em/license/bind）。
     *
     * 请求参数（与解绑一致）：
     *   code    激活码
     *   domain  要绑定的域名
     *
     * 服务端响应 data：
     *   bound              true=本次绑定成功；false=这个码本来就绑在这个域名上，**同样算成功**
     *   code_masked        打码后的激活码（仅供展示，**不能**当 code 回传）
     *   domain             归一之后的授权域名
     *   license_type       档位数字：1=VIP / 2=SVIP / 3=至尊
     *   license_type_label 档位文本
     *
     * 已绑了别的域名 / 码不存在 / 已作废 → HTTP 400 + {code:400, message}，
     * 由 postForm() 当业务错误抛出，message 会原样呈现给用户。
     * 网络层失败直接抛异常，不自动换线（由用户手动切换线路后重试）。
     *
     * @return array{bound?:bool, code_masked?:string, domain?:string, license_type?:string, license_type_label?:string}
     * @throws RuntimeException
     */
    public static function bind(string $code, string $domain): array
    {
        return self::postForm('api/open/v1/em/license/bind', [
            'code'   => $code,
            'domain' => $domain,
        ], 8);
    }

    /**
     * 生成购买跳转 URL。
     *
     * 服务端允许两种响应：
     *   - 直接返回 302 Location
     *   - 返回 JSON { url: "https://..." }
     * 这里两种情况都支持。
     *
     * 只请求当前选中的线路；网络层失败直接抛异常，由用户手动切换线路。
     *
     * @throws RuntimeException
     */
    public static function getBuyUrl(string $level, string $domain, string $adminEmail = '', string $returnUrl = ''): string
    {
        $query = http_build_query([
            'level' => $level,
            'domain' => $domain,
            'scope' => 'main',
            'user_email' => $adminEmail,
            'return_url' => $returnUrl,
        ]);

        // 走 GET 看服务端给 302 还是 JSON —— 一次请求就够
        $ch = curl_init(self::baseUrl() . 'api/v1/buy/level?' . $query);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'emshop-' . EM_VERSION,
        ] + self::tlsOptions());
        $resp = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            throw new RuntimeException('线路请求失败，请重试');
        }

        // 302 → 直接返回服务端提供的 URL
        if ($httpCode === 302 && preg_match('/^Location:\s*(\S+)/mi', $resp, $m)) {
            return trim($m[1]);
        }

        // 否则拆 body 解 JSON
        $pos = strpos($resp, "\r\n\r\n");
        $body = $pos === false ? $resp : substr($resp, $pos + 4);
        $json = json_decode($body, true);
        if (is_array($json)) {
            if (!empty($json['ok']) && !empty($json['data']['url'])) {
                return (string) $json['data']['url'];
            }
            throw new RuntimeException('获取购买链接失败');
        }

        throw new RuntimeException('获取购买链接失败（HTTP ' . $httpCode . '）');
    }

    /**
     * 校验域名是否已授权（POST /api/open/v1/em/license/status）。
     *
     * 请求参数：domain + code（授权码）。与 app-list / app-update-check 同口径 ——
     * 都是「这个码 + 这个域名」算不算一条有效授权；code 为空串时服务端按未授权算。
     *
     * 服务端响应 data：
     *   authorized          true=已授权 / false=没授权（码不存在 / 已作废 / 域名对不上都是它）
     *   domain              归一之后的域名
     *   license_type        档位数字：1=VIP / 2=SVIP / 3=至尊；没授权时是 null
     *   license_type_label  档位文本；没授权时是 null
     *
     * ⚠️ 没授权也是 HTTP 200 的**成功响应**，不是调用失败 —— 调用方要看 authorized
     * 这个布尔值决定清不清本地记录，别把它当异常处理。
     *
     * 换线：不自动换线，只请求当前选中的线路；线路不通直接抛异常。
     *
     * @return array{authorized?:bool, domain?:string, license_type?:string|null, license_type_label?:string|null}
     * @throws RuntimeException 网络不可达 / 响应格式异常 / code != 200
     */
    public static function status(string $domain, string $code = ''): array
    {
        return self::postForm('api/open/v1/em/license/status', [
            'domain' => $domain,
            'code'   => $code,
        ], 8);
    }

    /**
     * 解除域名与授权码的绑定（POST /api/open/v1/em/license/unbind）。
     *
     * 请求参数：
     *   code    授权码
     *   domain  要解绑的授权域名
     *
     * 服务端响应 data：{unbound: bool, code_masked: string, domain: string}
     *   unbound=true  —— 本次解绑成功
     *   unbound=false —— 这个授权码本来就没绑域名，**同样算成功**（幂等）
     *
     * 域名和授权码不是同一条 / 码不存在 / 已作废 → HTTP 400 + {code:400, message}，
     * 由 postForm() 当业务错误抛出。
     *
     * 换线：不自动换线，只请求当前选中的线路；线路不通直接抛异常。
     *
     * @return array{unbound?:bool, code_masked?:string, domain?:string} 服务端 data
     * @throws RuntimeException 参数错误 / 网络错误
     */
    public static function unbind(string $code, string $domain): array
    {
        return self::postForm('api/open/v1/em/license/unbind', [
            'code'   => $code,
            'domain' => $domain,
        ], 8);
    }

    /**
     * 后台基础数据聚合（POST /api/open/v1/em/base-data）。
     *
     * 一次请求拿到授权状态 + 代理配置 + 公告 + 广告位 + 版本信息，是后台首页与
     * 代理商弹窗唯一的数据源（原 api/admin_index.php、api/agent_config.php 已退场）。
     *
     * 请求参数：
     *   identity  代理商身份标识（base.php 的 SERVICE_TOKEN）。匹配不到不算错误：
     *             服务端会回 identity_matched=false + used_fallback=true，并用站长那份配置兜底
     *   domain    本机正在跑的域名（可传完整地址，服务端归一成完整域名（含 www / 子域名）后查授权）
     *   version   客户端当前版本号（形如 1.3.18，开头的 v 可有可无）
     *   code      本地保存的授权码（可选）。传了服务端就按「授权码 + 域名」两个都对上
     *             才算授权（同 license/status）；没传时老口径「只认域名」
     *
     * 返回 data 的字段（原样交给 LicenseService::fetchBaseData() 归一化）：
     *   domain / authorized / license_type / license_type_label
     *   identity_matched / used_fallback
     *   has_new_version / latest_version / force_update / min_version / patch_package_url
     *   buy_links[] / download_links[]（[{name,url}]）
     *   contact{qq_service,qq_group,wechat_service,wechat_qr,tg_service,tg_group_url}
     *   announcements[] / ad_slots[]
     *
     * 注意：域名未授权、身份标识匹配不到都是 **成功响应**（HTTP 200 / code 200），
     * 分别看 authorized 与 identity_matched，不要当成调用失败。
     *
     * 换线：不自动换线，只请求当前选中的线路；线路不通直接抛异常，由用户手动切换。
     * 超时取 8s（不是默认的 10s），最坏耗时就是单次超时。
     *
     * @return array 服务端 data 整段
     * @throws RuntimeException 网络不可达 / 响应格式异常 / code != 200
     */
    public static function baseData(string $identity, string $domain, string $version, string $code = ''): array
    {
        return self::postForm('api/open/v1/em/base-data', [
            'identity' => $identity,
            'domain'   => $domain,
            'version'  => $version,
            'code'     => $code,
        ], 8);
    }

    /**
     * 登录官方账号（POST /api/auth/login，scope=site）。
     *
     * 用的是官方站前台账号（邮箱/用户名 + 密码），与授权码无关。
     * 返回 data：{ token, token_type, expires_in, scope, user{...} }
     * 注意官方站这套接口的成功码是 **0**（不是 open/v1 那套的 200），
     * 所以这里显式传 successCode=0。账号不存在 / 密码错误时服务端回
     * {code:401xx, message}，由 apiRequest() 当业务错误抛出，message 原样呈现。
     *
     * @throws RuntimeException
     */
    public static function accountLogin(string $account, string $password): array
    {
        return self::postForm('api/auth/login', [
            'account'  => $account,
            'password' => $password,
            'scope'    => 'site',
            // 长期 token（官方站按 LONG_TOKEN_TTL 签，10 年）
            'remember' => 1,
        ], 10, 0);
    }

    /**
     * 发送官方账号邮箱验证码（POST /api/auth/email-code，scene=register）。
     * 成功码同样是 0；上游按 IP/邮箱限流，错误信息在 message 里。
     *
     * @throws RuntimeException
     */
    public static function accountEmailCode(string $email, string $scene = 'register'): array
    {
        return self::postForm('api/auth/email-code', [
            'email' => $email,
            'scene' => $scene,
        ], 10, 0);
    }

    /**
     * 邮箱验证码登录（POST /api/auth/login-code，免密码）。
     *
     * 先调 accountEmailCode($email, 'login') 发码，再带码调这里。
     * 成功码 0，返回 data 与密码登录一致（token / expires_in / user）。
     *
     * @throws RuntimeException
     */
    public static function accountLoginByCode(string $email, string $code): array
    {
        return self::postForm('api/auth/login-code', [
            'email'    => $email,
            'code'     => $code,
            'remember' => 1,
        ], 10, 0);
    }

    /**
     * 注册官方账号（POST /api/auth/register）。
     *
     * 必填：email / code（6 位邮箱验证码）/ password / password_confirmation
     * 成功回 201 + {code:0, data:{token, user}} —— 注册即登录，直接当登录结果用。
     *
     * @param array<string, string> $payload
     * @throws RuntimeException
     */
    public static function accountRegister(array $payload): array
    {
        return self::postForm('api/auth/register', $payload, 10, 0);
    }

    /**
     * 当前官方账号名下的全部激活码（GET /api/my/licenses，需 Bearer token）。
     *
     * 返回 data.licenses[]：{id, code, level, type, type_label, domain, status, created_at, ...}
     * （status=1 有效 / 0 作废；domain 为空串表示还没绑域名）
     * token 失效 / 被禁用时上游回 401 + {code:401xx, message}，这里抛异常。
     *
     * @return array<int, array<string, mixed>>
     * @throws RuntimeException
     */
    public static function myLicenses(string $token): array
    {
        $data = self::apiRequest('api/my/licenses', [], false, 10, 0, 'GET', $token);
        return is_array($data['licenses'] ?? null) ? array_values($data['licenses']) : [];
    }

    /**
     * 当前官方账号的资料（GET /api/auth/me，需 Bearer token）。
     *
     * 账号管理弹窗的「个人资料」用，返回官方站用户表整行（password 已被上游剔除）：
     * {id, username, email, nickname, avatar, role, status, balance, created_at, last_login_at, ...}
     * 这里不裁剪字段，展示层按需取。
     * token 失效 / 被禁用时上游回 401 + {code:401xx, message}，这里抛异常。
     *
     * @return array<string, mixed>
     * @throws RuntimeException
     */
    public static function accountProfile(string $token): array
    {
        return self::apiRequest('api/auth/me', [], false, 10, 0, 'GET', $token);
    }

    /**
     * 当前官方账号概况（GET /api/my/overview，需 Bearer token）。
     *
     * 一次拿到账号资料 + 名下激活码数量，给后台官方账号宫格用 ——
     * 余额和数量同屏显示，拆成 /auth/me + /my/licenses 就是两次跨网往返。
     * 返回 data：{user{...}, licenses_count}
     *
     * @return array<string, mixed>
     * @throws RuntimeException
     */
    public static function accountOverview(string $token): array
    {
        return self::apiRequest('api/my/overview', [], false, 10, 0, 'GET', $token);
    }

    /**
     * 当前官方账号的余额变动明细（GET /api/my/balance/logs，需 Bearer token）。
     *
     * 上游返回：{data[], total, page, per_page, last_page, balance}
     * data[] 每项 {action, action_label, amount, balance_before, balance_after,
     *            remark, operator_id, operator_name, created_at}
     * amount 恒为正数，方向看 balance_after 与 balance_before 的大小。
     *
     * @return array<string, mixed>
     * @throws RuntimeException
     */
    public static function accountBalanceLogs(string $token, int $page = 1, int $perPage = 10): array
    {
        return self::apiRequest('api/my/balance/logs', [
            'page'     => max(1, $page),
            'per_page' => min(50, max(1, $perPage)),
        ], false, 10, 0, 'GET', $token);
    }

    /**
     * 把激活码绑定到当前官方账号（POST /api/my/licenses/bind，需 Bearer token）。
     *
     * 上游只接受「池子里还没发出的码」或「无主（user_id 为空）的旧码」：
     * 已经在别人名下的直接拒（「该激活码已被其他账号绑定」），
     * 已经是自己名下的也会说明（「这个激活码已经在你的账号下了」）。
     * 返回 data.license；失败由 apiRequest 按 errors 第一条抛异常。
     *
     * @return array<string, mixed>
     * @throws RuntimeException
     */
    public static function accountBindLicense(string $token, string $code): array
    {
        return self::apiRequest('api/my/licenses/bind', [
            'code' => $code,
        ], false, 10, 0, 'POST', $token);
    }

    /**
     * 移除授权（POST /api/my/licenses/unbind，需 Bearer token）。
     *
     * 凭「账号（token）+ 激活码」把这条码绑定的域名清空；只能动自己名下的码。
     * 返回 data.license（域名已清空的那条）。
     *
     * @return array<string, mixed>
     * @throws RuntimeException 码不存在 / 不属于当前账号 / 已作废 / 网络失败
     */
    public static function accountRemoveLicense(string $token, string $code): array
    {
        return self::apiRequest('api/my/licenses/unbind', [
            'code' => $code,
        ], false, 10, 0, 'POST', $token);
    }

    /**
     * 重置授权码（POST /api/my/licenses/reset，需 Bearer token）。
     *
     * 凭「账号（token）+ 激活码」给这条授权换一个新码：旧码立即失效，
     * 域名、档位、归属都不动。返回 data.license（code 已是新的）。
     *
     * @return array<string, mixed>
     * @throws RuntimeException 码不存在 / 不属于当前账号 / 已作废 / 网络失败
     */
    public static function accountResetLicense(string $token, string $code): array
    {
        return self::apiRequest('api/my/licenses/reset', [
            'code' => $code,
        ], false, 10, 0, 'POST', $token);
    }

    /**
     * 当前官方账号的代理端配置（GET /api/my/config，需 Bearer token）。
     *
     * 返回 data.config：{identity, qq_service, qq_group, wechat_service, wechat_qr,
     *                    tg_service, tg_group_url, em_buy_links, em_download_links,
     *                    tt_buy_links, tt_download_links}
     * 未设置的项是空串。这些值只在展示时读取，本站不落库。
     *
     * @return array<string, mixed>
     * @throws RuntimeException
     */
    public static function accountConfig(string $token): array
    {
        return self::apiRequest('api/my/config', [], false, 10, 0, 'GET', $token);
    }

    /**
     * 保存代理端配置（POST /api/my/config/secure-update，需 Bearer token + 登录密码）。
     *
     * 上游先校验密码（本人确认这一笔改动），再按白名单写入；密码不落库。
     * 返回 data.config（保存后的完整配置）。
     *
     * @param array<string, string> $payload 白名单字段 + password
     * @return array<string, mixed>
     * @throws RuntimeException 密码为空 / 密码错误 / 校验失败 / 网络失败
     */
    public static function accountConfigUpdateWithPassword(string $token, array $payload): array
    {
        return self::apiRequest('api/my/config/secure-update', $payload, false, 10, 0, 'POST', $token);
    }

    /**
     * 代理的激活码仓库（GET /api/my/em-codes，需 Bearer token，仅代理/管理）。
     *
     * 上游一次返回：{data[], total, page, per_page, last_page, counts{unused, used}, pricing, balance}
     * data[] 每项 {id, code, type, type_label, agent_id, remark, created_at}
     * 已使用的那一类还带 {user_id, domain}（绑定情况的判定依据）。
     * pricing = {configured, overdraft, fees{type: {label, fee, affordable}}}
     *
     * usage：unused（默认，未绑用户且未绑域名）/ used（绑了用户或域名）。
     *
     * @return array<string, mixed>
     * @throws RuntimeException 无代理权限 / token 失效 / 网络失败
     */
    public static function accountEmCodes(string $token, int $page = 1, int $perPage = 10, string $usage = 'unused'): array
    {
        return self::apiRequest('api/my/em-codes', [
            'page'     => max(1, $page),
            'per_page' => min(100, max(1, $perPage)),
            'usage'    => $usage === 'used' ? 'used' : 'unused',
        ], false, 10, 0, 'GET', $token);
    }

    /**
     * 生成一批激活码（POST /api/my/em-codes，需 Bearer token，仅代理/管理）。
     *
     * 按档位单价从上游账号余额扣费；返回 data：{codes[], cost, balance}
     *
     * @throws RuntimeException 无代理权限 / 价格未配置 / 余额不足 / 参数不合法 / 网络失败
     */
    public static function accountGenerateEmCodes(string $token, string $type, int $count): array
    {
        return self::apiRequest('api/my/em-codes', [
            'type'  => $type,
            'count' => max(1, min(200, $count)),
        ], false, 15, 0, 'POST', $token);
    }

    /**
     * 代理版本安装包列表（GET /api/my/versions/em，仅代理 / 管理员）。
     *
     * 上游只返回已发布的 EMSHOP 版本：{id, version, changelog, updated_at, has_install}。
     */
    public static function accountVersions(string $token, int $page = 1, int $perPage = 10): array
    {
        return self::apiRequest('api/my/versions/em', [
            'page'     => max(1, $page),
            'per_page' => min(100, max(1, $perPage)),
        ], false, 10, 0, 'GET', $token);
    }

    /**
     * 下载代理版本源码包（GET /api/my/versions/em/{id}/source，二进制 zip）。
     *
     * 上游会在包里把 `base.php` 的 SERVICE_TOKEN 换成当前代理的身份标识；
     * 没配身份标识时回 40307（JSON 信封，不是二进制）。
     *
     * @return array{body: string, disposition: string}
     * @throws RuntimeException 没配身份标识 / 版本不存在 / 网络失败
     */
    public static function accountVersionSource(string $token, int $id): array
    {
        $url = self::baseUrl() . 'api/my/versions/em/' . max(1, $id) . '/source';
        $disposition = '';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/zip, application/json',
                'X-Em-Client: emshop-' . EM_VERSION,
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$disposition): int {
                if (stripos($line, 'Content-Disposition:') === 0) {
                    $disposition = trim(substr($line, strlen('Content-Disposition:')));
                }

                return strlen($line);
            },
        ] + self::tlsOptions());

        $resp = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new RuntimeException('下载失败：' . ($err !== '' ? $err : '线路请求失败，请重试'));
        }

        if ($httpCode !== 200) {
            $json = json_decode($resp, true);
            $msg = is_array($json) ? (string) ($json['message'] ?? $json['msg'] ?? '') : '';

            throw new RuntimeException($msg !== '' ? $msg : ('下载失败（HTTP ' . $httpCode . '）'));
        }

        return ['body' => $resp, 'disposition' => $disposition];
    }

    /**
     * 代理订单记录（GET /api/my/orders，仅代理 / 管理员）。只返回自己名下的单。
     *
     * 参数：page / per_page / keyword / project（em|tt|空）/ state（四态|空）
     * 返回：标准分页信封 + commission_rate（当前佣金比例）。
     */
    public static function accountOrders(string $token, array $params): array
    {
        return self::apiRequest('api/my/orders', [
            'page'     => max(1, (int) ($params['page'] ?? 1)),
            'per_page' => min(100, max(1, (int) ($params['per_page'] ?? 10))),
            'keyword'  => (string) ($params['keyword'] ?? ''),
            'project'  => (string) ($params['project'] ?? ''),
            'state'    => (string) ($params['state'] ?? ''),
        ], false, 15, 0, 'GET', $token);
    }

    /**
     * 我的广告位列表（GET /api/my/ad-slots/em，仅代理 / 管理员）。
     *
     * 参数：page / per_page / keyword / state（on|expired|off|空）
     */
    public static function accountAdSlots(string $token, array $params): array
    {
        return self::apiRequest('api/my/ad-slots/em', [
            'page'     => max(1, (int) ($params['page'] ?? 1)),
            'per_page' => min(100, max(1, (int) ($params['per_page'] ?? 10))),
            'keyword'  => (string) ($params['keyword'] ?? ''),
            'state'    => (string) ($params['state'] ?? ''),
        ], false, 10, 0, 'GET', $token);
    }

    /** 广告位详情（GET /api/my/ad-slots/em/{id}，列表里没有正文）。 */
    public static function accountAdSlotDetail(string $token, int $id): array
    {
        return self::apiRequest('api/my/ad-slots/em/' . max(1, $id), [], false, 10, 0, 'GET', $token);
    }

    /** 新建广告位（POST /api/my/ad-slots/em，agent_id 由上游按 token 注入）。 */
    public static function accountAdSlotCreate(string $token, array $payload): array
    {
        return self::apiRequest('api/my/ad-slots/em', $payload, false, 10, 0, 'POST', $token);
    }

    /** 修改广告位（PUT /api/my/ad-slots/em/{id}，JSON 请求体）。 */
    public static function accountAdSlotUpdate(string $token, int $id, array $payload): array
    {
        return self::apiRequest('api/my/ad-slots/em/' . max(1, $id), $payload, true, 10, 0, 'PUT', $token);
    }

    /** 删除广告位（DELETE /api/my/ad-slots/em/{id}）。 */
    public static function accountAdSlotDelete(string $token, int $id): array
    {
        return self::apiRequest('api/my/ad-slots/em/' . max(1, $id), [], false, 10, 0, 'DELETE', $token);
    }

    /** 广告位上移 / 下移（POST /api/my/ad-slots/em/{id}/move）。 */
    public static function accountAdSlotMove(string $token, int $id, string $direction): array
    {
        return self::apiRequest('api/my/ad-slots/em/' . max(1, $id) . '/move', [
            'direction' => $direction,
        ], false, 10, 0, 'POST', $token);
    }

    /**
     * 代理安装记录（GET /api/my/install-records，仅代理 / 管理员）。
     *
     * 参数：page / per_page / keyword / project（em|tt|空）
     */
    public static function accountInstallRecords(string $token, array $params): array
    {
        return self::apiRequest('api/my/install-records', [
            'page'     => max(1, (int) ($params['page'] ?? 1)),
            'per_page' => min(100, max(1, (int) ($params['per_page'] ?? 10))),
            'keyword'  => (string) ($params['keyword'] ?? ''),
            'project'  => (string) ($params['project'] ?? ''),
        ], false, 10, 0, 'GET', $token);
    }

    /**
     * 应用商店 · 应用列表（POST /api/open/v1/em/app-list）。
     *
     * 主站货架与分站货架合并成一个接口，用 scope 区分（main 主站 / branch 分站）。
     * 授权信息与分类一并返回，不用再单独请求。
     *
     * 请求参数：
     *   domain      本机在跑的域名（必填，服务端归一成完整域名）
     *   code        授权码；不传 = 未授权。传了必须是有效的、且绑的就是 domain，
     *               否则一律按未授权算（服务端会回 VIP 门槛价 + can_buy=false）
     *   page        第几页，从 1 开始
     *   per_page    每页条数，默认 20、最多 50
     *   type        template / plugin，不传是全部
     *   scope       main 主站使用 / branch 分站使用，不传是两种都返回
     *   category_id 分类 id，取值从返回的 categories 拿
     *   keyword     按中文名 / 英文名 / 作者模糊搜
     *
     * 返回：
     *   license     {authorized, domain, type, type_label, all_free, code_masked}
     *   categories  [{id, name}]
     *   meta        {page, per_page, total, last_page}
     *   data[]      应用列表（price / price_vip / price_svip / can_buy / is_pay / package_url / screenshots 等）
     *
     * is_pay 是布尔：该应用是否已经买过（已拥有），前端据此把按钮换成"已购买，安装"。
     * data[] 原样透传，本方法不裁剪字段。
     *
     * 注意：`name_en` 仍被调用方当作本地安装目录名（slug）使用，不是纯展示字段。
     *
     * 换线：不自动换线，只请求当前选中的线路。
     *
     * @param array{domain?:string,code?:string,page?:int,per_page?:int,type?:string,scope?:string,category_id?:int,keyword?:string} $params
     * @return array{license:array<string,mixed>,categories:array<int,array>,meta:array<string,int>,data:array<int,array>}
     * @throws RuntimeException
     */
    public static function appList(array $params, string $token = ''): array
    {
        // print_r($params);die;
        $data = self::postForm('api/open/v1/em/app-list', $params, 15, 200, $token);

        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        return [
            'license'    => is_array($data['license'] ?? null) ? $data['license'] : [],
            'categories' => is_array($data['categories'] ?? null) ? array_values($data['categories']) : [],
            'meta'       => [
                'page'      => (int) ($meta['page']      ?? 1),
                'per_page'  => (int) ($meta['per_page']  ?? 20),
                'total'     => (int) ($meta['total']     ?? 0),
                'last_page' => (int) ($meta['last_page'] ?? 0),
            ],
            'data'       => is_array($data['data'] ?? null) ? array_values($data['data']) : [],
        ];
    }

    /**
     * 应用商店 - 已购买应用列表（POST /api/app_purchased_list.php）。
     *
     * 走的是旧接口 api/app_purchased_list.php（新接口 app-list 没有"已购买"这个概念），
     * 所以返回结构仍是老的 { list, count, page, pageNum }，别和 appList() 的 meta/data 混了。
     *
     * @param array{page?:int,pageNum?:int,type?:string,category_id?:int,keyword?:string,scope?:int,emkey?:string,host?:string} $params
     * @return array{list:array<int,array>,count:int,page:int,pageNum:int}
     * @throws RuntimeException
     */
    public static function appPurchasedList(array $params, string $token = ''): array
    {
        $data = self::postForm('api/app_purchased_list.php', $params, 15, 200, $token);
        return [
            'list'    => is_array($data['list'] ?? null) ? array_values($data['list']) : [],
            'count'   => (int) ($data['count']   ?? 0),
            'page'    => (int) ($data['page']    ?? 1),
            'pageNum' => (int) ($data['pageNum'] ?? 10),
        ];
    }

    /**
     * 按 id 获取单个应用的详情（/api/app_detail.php），一次返回 app + pay_methods。
     *
     * 价格计算与 app-list 一致：
     *   - 未传 emkey / 校验失败 → price = price_vip（VIP 门槛价）、can_buy = false
     *   - VIP → price_vip、SVIP → price_svip、至尊 → 0
     *
     * @param int    $appId      应用 id
     * @param string $emkey      激活码；空串时按 VIP 价返回
     * @param string $host       客户端 host（服务端会归一化）
     * @param int    $scope      客户端身份：1=主站 / 2=商户
     * @param string $memberCode 商户标识（主站传空串；商户传主用户 invite_code），用于精确定位购买记录
     * @return array ['app' => [...], 'pay_methods' => [...]]
     * @throws RuntimeException
     */
    public static function appDetail(int $appId, string $emkey, string $host, int $scope, string $memberCode = '', string $token = ''): array
    {
        return self::postForm('api/app_detail.php', [
            'app_id'      => $appId,
            'emkey'       => $emkey,
            'host'        => $host,
            'scope'       => $scope,
            'member_code' => $memberCode,
            /* 后台应用商店的拉取标记，供上游记录调用账号 */
            'source'      => 'admin',
        ], 10, 200, $token);
    }

    /**
     * 批量检查已装应用有没有新版本（POST /api/open/v1/em/app-update-check）。
     *
     * 与 app-list 同一套身份口径：domain 必填，code 不传 / 无效一律按未授权算。
     * **服务端只返回"有更新"的条目**（都最新时 data 为空数组），并原样回显 installed_version，
     * 所以调用方不需要自己比版本作为"有没有更新"的判据。
     *
     * 请求体是 JSON：{domain, code, apps:[{name_en, version}]}；apps 上限 100 条，
     * 超出由调用方分片（见 AppUpdateService::checkInstalled）。
     *
     * 返回：
     *   license  {authorized, domain, type, type_label, all_free, code_masked}
     *   data[]   {name_en, installed_version, version, type, type_label, scope, scope_label,
     *             min_version, package_url, package_name, package_size, can_buy,
     *             purchased, is_pay, updated_at}
     *
     * 换线：不自动换线，只请求当前选中的线路。
     *
     * @param array<int, array{name_en:string, version:string}> $apps 本机已装清单
     * @return array{license:array<string,mixed>,data:array<int,array>}
     * @throws RuntimeException
     */
    public static function appUpdateCheck(array $apps, string $domain, string $code): array
    {
        if ($apps === []) {
            return ['license' => [], 'data' => []];
        }

        $data = self::postJson('api/open/v1/em/app-update-check', [
            'domain' => $domain,
            'code'   => $code,
            'apps'   => array_values($apps),
        ]);

        return [
            'license' => is_array($data['license'] ?? null) ? $data['license'] : [],
            'data'    => is_array($data['data'] ?? null) ? array_values($data['data']) : [],
        ];
    }

    /**
     * 应用商店 - 创建购买订单（POST /api/open/v1/em/order）。
     *
     * 下单即把订单信息、收款页地址、可用支付通道一次返回，客户端原样展示即可：
     * 让用户挑一个通道，再把通道 id 拼到 pay_url 上跳转（收银台按 ?channel={id} 选通道）。
     *
     * 请求参数（三个都必填，买东西必须有授权码 —— 这点和应用列表不同）：
     *   code    授权码；必须是有效的、且绑的就是 domain，否则服务端拒绝
     *   domain  本机在跑的域名（可传完整地址，服务端归一成完整域名）
     *   app_id  要买的应用 id（取自 app-list 返回的 data[].id）
     *
     * 返回 data：
     *   order_no / amount / license_type / license_type_label
     *   app{id,name,type,type_label}
     *   status / state / expires_at / pay_url / channels[] / created_at
     * channels[] 每项 {id, name, logo, type, type_label}；logo 是授权服务器上的
     * 相对路径，调用方要补当前线路域名后再给前端。
     *
     * 换线：下单有副作用（重复提交会重复生成订单），**只发一次、不换线、不重试**。
     *
     * @return array 服务端 data（含 pay_url / channels）
     * @throws RuntimeException
     */
    public static function createOrder(string $code, string $domain, int $appId): array
    {
        return self::postForm('api/open/v1/em/order', [
            'code'   => $code,
            'domain' => $domain,
            'app_id' => $appId,
        ], 15);
    }

    /**
     * 站内扫码支付：取这一单的支付宝当面付二维码。
     *
     * 返回 data：{order_no, amount, qr_code, pay_method, pay_method_label, expires_at}
     * `qr_code` 是二维码**内容**（`https://qr.alipay.com/...`），前端用二维码组件画图。
     * 站点没开「支付宝当面付」时服务端回 400 + message，这里抛异常原样展示。
     *
     * @throws RuntimeException
     */
    public static function appOrderQrcode(string $orderNo, int $channelId = 0): array
    {
        return self::postForm('api/open/v1/em/order/' . rawurlencode($orderNo) . '/qrcode', [
            // 用户在下单响应的 channels 里选的那个渠道；不传由服务端挑
            'channel_id' => $channelId,
        ], 15);
    }

    /**
     * 站内扫码支付：查这一单的支付结果（服务端会主动问一次支付宝）。
     *
     * 返回 data：{order_no, amount, status, state, paid_at, expires_at}
     * state：pending / paid / closed / expired。
     *
     * @throws RuntimeException
     */
    public static function appOrderStatus(string $orderNo): array
    {
        return self::postForm('api/open/v1/em/order/' . rawurlencode($orderNo) . '/status', [], 15);
    }

    // --------------------------------------------------------
    // 内部
    // --------------------------------------------------------

    /**
     * 返回当前选中线路的 URL（public 别名，方便外部模块拿到授权服务器域名拼相对地址用）。
     * 用于 UpdateService::resolvePackageUrl() 在服务端返回相对路径时补域名。
     *
     * 实际请求也只走这条线路（见 apiRequest()），不会自动换线。
     */
    public static function currentBaseUrl(): string
    {
        return self::baseUrl();
    }

    /**
     * TLS 校验选项（三个请求入口共用）。
     *
     * 默认**开启**证书与主机名校验。此前 postForm() 为了绕过「SSL error 60」
     * （证书链问题）把校验整个关掉了，而这条通道传的是授权与升级元数据：
     * 关掉校验意味着链路中间人可以篡改响应、把升级包地址换成任意主机。
     *
     * 若你的授权服务器确实是自签证书或证书链不全、且暂时无法修好证书，
     * 可将配置项 license_insecure_tls 置为 '1' 显式降级 ——
     * 但请清楚这等于放弃该通道对中间人攻击的防护。
     *
     * @return array<int, mixed>
     */
    private static function tlsOptions(): array
    {
        if ((string) Config::get('license_insecure_tls', '0') === '1') {
            return [
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ];
        }

        return [
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ];
    }

    /**
     * 归一化线路常量（兼容历史上写成字符串的单线路形式）。
     *
     * @param mixed $raw
     * @return array<int, array{url:string, name:string}>
     */
    private static function normalizeLines($raw): array
    {
        if (is_string($raw)) {
            $raw = [['url' => $raw, 'name' => '官方线路']];
        }
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $line) {
            $url  = is_array($line) ? (string) ($line['url'] ?? '') : (string) $line;
            $name = is_array($line) ? (string) ($line['name'] ?? '') : '';
            $url  = trim($url);
            if ($url === '') {
                continue;
            }
            $out[] = [
                'url'  => rtrim($url, '/') . '/',
                'name' => $name !== '' ? $name : '官方线路',
            ];
        }
        return $out;
    }

    /** 官方线路列表。纯常量，不读 Config —— 安装程序在 init.php 之前也能用。 */
    public static function officialLines(): array
    {
        return self::normalizeLines(self::OFFICIAL_BASE_URL);
    }

    /** 开发模式线路列表。纯常量。 */
    public static function devLines(): array
    {
        return self::normalizeLines(self::DEV_BASE_URL);
    }

    /**
     * 当前生效的线路列表。
     *
     * 后台「设置 › 安全设置 › 开发模式」开启时整组换成 DEV_BASE_URL。
     * 地址不读 config.php：线路必须跟着程序版本走，否则老站升级后残留的旧地址会生效。
     *
     * @return array<int, array{url:string, name:string}>
     */
    public static function lines(): array
    {
        return (string) Config::get('license_dev_mode', '0') === '1'
            ? self::devLines()
            : self::officialLines();
    }

    /**
     * 并发测一遍每条线路的延迟（毫秒），给后台线路列表展示用。
     *
     * 每条线路发一个最小 GET（首页），取各自的 TOTAL_TIME。并发发起，
     * 所以总耗时约等于最慢那条，而不是逐条相加。
     * **不要用 HEAD**：实测部分线路/节点会直接重置 HEAD 连接（curl errno 35），
     * 会导致明明能用却全部显示"超时"。
     * 连不上 / 超时 → ms 为 null；4xx/5xx 也算可达 —— 这里要的是网络往返，
     * 不是响应内容。
     *
     * @return array<int, array{index:int, url:string, name:string, ms:int|null}>
     */
    public static function measureLatency(): array
    {
        $lines = self::lines();
        if ($lines === []) {
            return [];
        }

        $mh = function_exists('curl_multi_init') ? curl_multi_init() : false;
        $handles = [];

        if ($mh !== false) {
            foreach ($lines as $i => $line) {
                $ch = curl_init($line['url']);
                if ($ch === false) {
                    continue;
                }
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER    => true,  // GET（不要 HEAD，见方法注释）
                    CURLOPT_FOLLOWLOCATION    => false,
                    CURLOPT_TIMEOUT_MS        => 3000,
                    CURLOPT_CONNECTTIMEOUT_MS => 2000,
                    CURLOPT_USERAGENT         => 'emshop-' . EM_VERSION,
                ] + self::tlsOptions());
                curl_multi_add_handle($mh, $ch);
                $handles[$i] = $ch;
            }

            do {
                $status = curl_multi_exec($mh, $active);
                if ($active) {
                    curl_multi_select($mh, 0.05);
                }
            } while ($active && $status === CURLM_OK);
        }

        $out = [];
        foreach ($lines as $i => $line) {
            $ms = null;
            if (isset($handles[$i])) {
                $ch = $handles[$i];
                if (curl_errno($ch) === 0) {
                    $ms = (int) round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
                }
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            $out[] = [
                'index' => $i,
                'url'   => $line['url'],
                'name'  => $line['name'],
                'ms'    => $ms,
            ];
        }

        if ($mh !== false) {
            curl_multi_close($mh);
        }

        return $out;
    }

    /** 当前线路的索引；配置值越界（比如线路列表变短了）时回落 0。 */
    public static function currentLineIndex(): int
    {
        $lines = self::lines();
        if ($lines === []) {
            return 0;
        }
        $idx = (int) Config::get('license_line_index', '0');
        return ($idx < 0 || $idx >= count($lines)) ? 0 : $idx;
    }

    /**
     * 当前选中线路的 URL（当前索引那条）。一条都没配时返回空串、不抛异常 ——
     * init.php 启动阶段要 define 常量，那里不适合 catch。
     */
    public static function defaultBaseUrl(): string
    {
        $lines = self::lines();
        return $lines === [] ? '' : $lines[self::currentLineIndex()]['url'];
    }

    /** 当前选中线路 URL，统一带尾部斜杠，方便拼路径。 */
    private static function serverUrl(): string
    {
        return self::defaultBaseUrl();
    }

    /**
     * 当前选中线路 URL；一条线路都没配时抛异常。
     *
     * @throws RuntimeException
     */
    private static function baseUrl(): string
    {
        $url = self::serverUrl();
        if ($url === '') {
            throw new RuntimeException('未配置授权服务器地址（LicenseClient 的线路常量缺失）');
        }
        return $url;
    }

    /**
     * 通用表单 POST → 解析 { code, msg, data } → 返回 data / 抛异常。
     *
     * 用于所有返回 {code:200, msg, data} 格式的现网接口（/api/open/v1/em/* 等）。
     * 错误文案字段两种都有：老接口用 msg，新接口（/api/open/v1/*）用 message，取到哪个用哪个。
     *
     * 换线：只请求当前选中的线路，不自动换线、不重试。
     * 连不上 / 超时 / 响应异常都直接抛异常，由用户在后台手动切换线路后再试。
     *
     * 预算：最坏耗时就是单次 $timeout。
     *
     * @param array<string, mixed> $payload
     * @return array 返回 data（保证是数组）
     * @throws RuntimeException
     */
    private static function postForm(string $path, array $payload, int $timeout = 10, int $successCode = 200, string $token = ''): array
    {
        return self::apiRequest($path, $payload, false, $timeout, $successCode, 'POST', $token);
    }

    /**
     * 通用 JSON POST → 解析 { code, msg|message, data } → 返回 data / 抛异常。
     *
     * 与 postForm() 共用同一套线路与信封口径，区别只在请求体是 JSON、Content-Type 是
     * application/json —— app-update-check 这类接口只收 JSON。
     *
     * @param array<string, mixed> $payload
     * @return array 返回 data（保证是数组）
     * @throws RuntimeException
     */
    private static function postJson(string $path, array $payload, int $timeout = 15): array
    {
        return self::apiRequest($path, $payload, true, $timeout);
    }

    /**
     * 表单 / JSON POST 的公共实现：线路与信封解析只有这一份。
     *
     * 只请求当前选中的线路，不自动换线、不重试；网络层失败直接抛
     * 「线路请求失败，请重试」，由用户手动切换线路后再试。
     *
     * @param array<string, mixed> $payload
     * @param int $successCode 成功响应的 code（open/v1 是 200，官方站 auth/* 是 0）
     * @param string $method 请求方法：POST（默认）/ GET
     * @param string $token 非空时带 `Authorization: Bearer`（官方站 /my/* 需要）
     * @return array 返回 data（保证是数组）
     * @throws RuntimeException
     */
    private static function apiRequest(
        string $path,
        array $payload,
        bool $asJson,
        int $timeout,
        int $successCode = 200,
        string $method = 'POST',
        string $token = ''
    ): array {
        $url    = self::baseUrl() . ltrim($path, '/');
        $method = strtoupper($method);
        $isGet  = $method === 'GET';

        // POST / PUT / PATCH 带请求体；DELETE 只带路径（上游也不需要体）
        $hasBody = in_array($method, ['POST', 'PUT', 'PATCH'], true);

        if ($isGet && $payload !== []) {
            $url .= '?' . http_build_query($payload);
        }

        $body = '';
        if ($hasBody) {
            $body = $asJson
                ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : http_build_query($payload);
            if ($body === false) {
                throw new RuntimeException('请求数据编码失败');
            }
        }

        $headers = [
            'Accept: application/json',
            'X-Em-Client: emshop-' . EM_VERSION,
        ];
        if ($hasBody) {
            $headers[] = 'Content-Type: ' . ($asJson ? 'application/json' : 'application/x-www-form-urlencoded');
        }
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
        ] + self::tlsOptions();
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        } elseif ($isGet) {
            $options[CURLOPT_HTTPGET] = true;
        } else {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            if ($hasBody) {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, $options);
        $resp = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            throw new RuntimeException('线路请求失败，请重试');
        }

        $json = json_decode($resp, true);
        if (!is_array($json)) {
            throw new RuntimeException('响应格式异常（HTTP ' . $httpCode . '）');
        }

        // 成功码不同接口不一样：open/v1 那套是 200，官方站前台接口（auth/*）是 0
        if ((int) ($json['code'] ?? -1) !== $successCode) {
            $msg = (string) ($json['msg'] ?? $json['message'] ?? '请求失败');
            // 校验失败时上游只给通用文案（参数校验失败），具体原因在 errors 里 —— 取第一条
            if (isset($json['errors']) && is_array($json['errors'])) {
                foreach ($json['errors'] as $first) {
                    if (is_string($first) && $first !== '') {
                        $msg = $first;
                        break;
                    }
                }
            }
            throw new RuntimeException($msg);
        }
        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }

    /**
     * 通用 POST JSON → 解析 { ok, data, ... } → 返回 data / 抛异常。
     *
     * 保留给未来走 JSON 协议的接口使用（v2 verify 等）。
     *
     * @param array<string, mixed> $payload
     * @return mixed
     * @throws RuntimeException
     */
    private static function post(string $path, array $payload, int $timeout = 10)
    {
        $url = self::baseUrl() . $path;
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Em-Client: emshop-' . EM_VERSION,
            ],
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
        ] + self::tlsOptions());
        $resp = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new RuntimeException('线路请求失败，请重试');
        }

        $json = json_decode($resp, true);
        if (!is_array($json)) {
            throw new RuntimeException('授权服务器响应格式异常（HTTP ' . $httpCode . '）');
        }
        if (empty($json['ok'])) {
            $msg = (string) ($json['msg'] ?? '授权失败');
            $code = (string) ($json['code'] ?? '');
            throw new RuntimeException($msg . ($code !== '' ? "（{$code}）" : ''));
        }
        return $json['data'] ?? null;
    }

    // ============================================================================
    // 应用商店重构:按 audience 拆出的镜像方法(mainApp* / merchantApp*)
    //
    // 服务端将来计划把"主站货架"和"分站货架"拆成两组独立接口。当前服务端还没就绪,
    // 这一组方法负责区分"主站货架"和"分站货架",历史上服务端是两组接口。
    // 现在 app-list 已合成一个接口,用 scope(main/branch) 区分,这里就只注入 scope。
    //
    // 使用约定:
    //   - mainApp*     主站为自己采购(落 em_plugin / em_template scope='main')
    //   - merchantApp* 主站为分站采购(落 em_app_market;不再以分站身份直连服务端)
    // ============================================================================

    /**
     * 主站货架 · 应用列表。
     *
     * @param array{domain?:string,code?:string,page?:int,per_page?:int,type?:string,category_id?:int,keyword?:string} $params
     * @return array{license:array<string,mixed>,categories:array<int,array>,meta:array<string,int>,data:array<int,array>}
     */
    public static function mainAppList(array $params, string $token = ''): array
    {
        $params['scope'] = 'main';
        return self::appList($params, $token);
    }

    /**
     * 分站货架 · 应用列表(主站后台为分站采购时拉)。
     *
     * 注意 scope 的取值是 `branch`（分站），不是 merchant —— 服务端那边用词是 branch。
     *
     * @param array{domain?:string,code?:string,page?:int,per_page?:int,type?:string,category_id?:int,keyword?:string} $params
     * @return array{license:array<string,mixed>,categories:array<int,array>,meta:array<string,int>,data:array<int,array>}
     */
    public static function merchantAppList(array $params, string $token = ''): array
    {
        $params['scope'] = 'branch';
        return self::appList($params, $token);
    }

    /**
     * 主站货架 · 已购买应用列表。
     *
     * @param array{page?:int,pageNum?:int,type?:string,category_id?:int,keyword?:string,emkey?:string,host?:string} $params
     * @return array{list:array<int,array>,count:int,page:int,pageNum:int}
     */
    public static function mainAppPurchasedList(array $params, string $token = ''): array
    {
        $params['scope'] = 1;
        return self::appPurchasedList($params, $token);
    }

    /**
     * 分站货架 · 已购买应用列表（主站为分站采购视角）。
     *
     * @param array{page?:int,pageNum?:int,type?:string,category_id?:int,keyword?:string,emkey?:string,host?:string} $params
     * @return array{list:array<int,array>,count:int,page:int,pageNum:int}
     */
    public static function merchantAppPurchasedList(array $params, string $token = ''): array
    {
        $params['scope'] = 2;
        return self::appPurchasedList($params, $token);
    }

    /**
     * 主站货架 · 应用详情 + 支付方式。
     */
    public static function mainAppDetail(int $appId, string $emkey, string $host, string $token = ''): array
    {
        return self::appDetail($appId, $emkey, $host, 1, '', $token);
    }

    /**
     * 分站货架 · 应用详情 + 支付方式(主站为分站采购时弹窗一次拉齐)。
     */
    public static function merchantAppDetail(int $appId, string $emkey, string $host, string $token = ''): array
    {
        return self::appDetail($appId, $emkey, $host, 2, '', $token);
    }

    /**
     * 主站货架 · 创建购买订单。
     *
     * 下单接口只有一个（/api/open/v1/em/order），不带 scope —— 买哪个应用由 app_id 决定。
     * 镜像方法保留是为了让调用方继续按 tab 分支，将来服务端要差异化时改这里即可。
     */
    public static function mainAppCreateOrder(string $code, string $domain, int $appId): array
    {
        return self::createOrder($code, $domain, $appId);
    }

    /**
     * 分站货架 · 创建购买订单。
     */
    public static function merchantAppCreateOrder(string $code, string $domain, int $appId): array
    {
        return self::createOrder($code, $domain, $appId);
    }

}
